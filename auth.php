<?php
header('Content-Type: application/json');
require_once 'db.php';

// Parse incoming JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$action = $_GET['action'] ?? $input['action'] ?? '';
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$max_attempts = 3;
$lock_duration_seconds = 21600; // 6 hours = 21,600 seconds

function format_auth_lock_timer($seconds) {
    $seconds = max(0, (int)$seconds);
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

// Helper to get IP login lock status with 6-hour automatic expiration
function get_ip_lock_status($pdo, $ip, $max_attempts = 3, $lock_duration_seconds = 21600) {
    $default = [
        'attempts'          => 0,
        'remaining'         => $max_attempts,
        'locked'            => false,
        'seconds_remaining' => 0,
        'unlock_at'         => null
    ];
    try {
        $pdo->exec("
            DELETE FROM login_attempts
            WHERE (strftime('%s', 'now') - strftime('%s', attempted_at)) >= {$lock_duration_seconds}
        ");

        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS attempt_count,
                MAX(attempted_at) AS last_attempt,
                datetime(MAX(attempted_at), '+6 hours') AS unlock_at,
                MAX(0, {$lock_duration_seconds} - (strftime('%s', 'now') - strftime('%s', MAX(attempted_at)))) AS seconds_remaining
            FROM login_attempts
            WHERE ip_address = ?
        ");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        $attempts = (int)($row['attempt_count'] ?? 0);
        $remaining = max(0, $max_attempts - $attempts);
        $sec_rem = (int)($row['seconds_remaining'] ?? 0);
        $locked = ($attempts >= $max_attempts && $sec_rem > 0);
        return [
            'attempts'          => $attempts,
            'remaining'         => $remaining,
            'locked'            => $locked,
            'seconds_remaining' => $locked ? $sec_rem : 0,
            'unlock_at'         => $locked ? $row['unlock_at'] : null
        ];
    } catch (Exception $e) {
        return $default;
    }
}

// Fetch the single user for this domain
$single_user_stmt = $pdo->query("SELECT * FROM users ORDER BY id ASC LIMIT 1");
$domain_user = $single_user_stmt ? $single_user_stmt->fetch() : null;

if ($action === 'signup') {
    if ($domain_user) {
        echo json_encode([
            'success' => false,
            'error'   => "Only one user account ('{$domain_user['username']}') is permitted per domain. Use 'Set Password' to update its credentials."
        ]);
        exit;
    }

    $username = trim($input['username'] ?? 'admin');
    $password = trim($input['password'] ?? '');

    if ($username === '' || $password === '') {
        echo json_encode(['success' => false, 'error' => 'Username and password are required.']);
        exit;
    }

    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
    $session_token   = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare("INSERT INTO users (username, password, session_token) VALUES (?, ?, ?)");
    $stmt->execute([$username, $hashed_password, $session_token]);

    $cookie_path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') ?: '/';
    setcookie('session_token', $session_token, [
        'expires'  => time() + (86400 * 30),
        'path'     => $cookie_path,
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
    setcookie('dw_user', $username, [
        'expires'  => time() + (86400 * 30),
        'path'     => $cookie_path,
        'httponly' => false,
        'samesite' => 'Lax'
    ]);

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $new_id = (int)$pdo->lastInsertId();
    $_SESSION['auth_user'] = [
        'id'       => $new_id,
        'username' => $username
    ];

    echo json_encode(['success' => true, 'user' => $username]);
    exit;
}

if ($action === 'login') {
    $lock_info = get_ip_lock_status($pdo, $client_ip, $max_attempts, $lock_duration_seconds);
    if ($lock_info['locked']) {
        $timer_str = format_auth_lock_timer($lock_info['seconds_remaining']);
        echo json_encode([
            'success'            => false,
            'locked'             => true,
            'remaining_attempts' => 0,
            'seconds_remaining'  => $lock_info['seconds_remaining'],
            'unlock_at'          => $lock_info['unlock_at'],
            'error'              => "Maximum of {$max_attempts} login attempts reached. Login is locked for 6 hours (Time remaining: {$timer_str}, unlocks at {$lock_info['unlock_at']})."
        ]);
        exit;
    }

    $password = trim($input['password'] ?? '');

    if ($password === '') {
        echo json_encode(['success' => false, 'error' => 'Password is required.']);
        exit;
    }

    $auth_success = false;
    $authenticated_user = null;
    if ($domain_user && password_verify($password, $domain_user['password'])) {
        $auth_success = true;
        $authenticated_user = $domain_user;
    } else {
        $uname = trim($input['username'] ?? '');
        if ($uname !== '') {
            $cand_stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
            $cand_stmt->execute([$uname]);
            $cand = $cand_stmt->fetch();
            if ($cand && password_verify($password, $cand['password'])) {
                $auth_success = true;
                $authenticated_user = $cand;
            }
        }
    }

    if (!$auth_success) {
        try {
            $ins = $pdo->prepare("INSERT INTO login_attempts (ip_address) VALUES (?)");
            $ins->execute([$client_ip]);
        } catch (Exception $e) {}
        $updated_lock = get_ip_lock_status($pdo, $client_ip, $max_attempts, $lock_duration_seconds);
        $locked       = $updated_lock['locked'];
        $remaining    = $updated_lock['remaining'];
        $timer_str    = format_auth_lock_timer($updated_lock['seconds_remaining']);
        $err_msg      = $locked
            ? "Invalid password. Maximum of {$max_attempts} login attempts reached. Login is now locked for 6 hours (Time remaining: {$timer_str}, unlocks at {$updated_lock['unlock_at']})."
            : "Invalid password. {$remaining} of {$max_attempts} login attempt(s) remaining.";

        echo json_encode([
            'success'            => false,
            'locked'             => $locked,
            'remaining_attempts' => $remaining,
            'seconds_remaining'  => $updated_lock['seconds_remaining'],
            'unlock_at'          => $updated_lock['unlock_at'],
            'error'              => $err_msg
        ]);
        exit;
    }

    $domain_user = $authenticated_user;

    // Clear failed login attempts for this IP on successful login
    try {
        $del = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
        $del->execute([$client_ip]);
    } catch (Exception $e) {}

    // Generate new session token
    $session_token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("UPDATE users SET session_token = ? WHERE id = ?");
    $stmt->execute([$session_token, $domain_user['id']]);

    // Set auth cookies
    $cookie_path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') ?: '/';
    setcookie('session_token', $session_token, [
        'expires'  => time() + (86400 * 30),
        'path'     => $cookie_path,
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
    setcookie('dw_user', $domain_user['username'], [
        'expires'  => time() + (86400 * 30),
        'path'     => $cookie_path,
        'httponly' => false,
        'samesite' => 'Lax'
    ]);

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $_SESSION['auth_user'] = [
        'id'       => (int)$domain_user['id'],
        'username' => $domain_user['username']
    ];

    echo json_encode(['success' => true, 'user' => $domain_user['username']]);
    exit;
}

if ($action === 'check' || $action === 'me') {
    $token = $_COOKIE['session_token'] ?? '';
    $authenticated = false;
    $auth_user = null;
    if (!empty($token)) {
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE session_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        if ($user) {
            $authenticated = true;
            $auth_user = [
                'id'       => (int)$user['id'],
                'username' => $user['username']
            ];
        }
    }
    $lock_info = get_ip_lock_status($pdo, $client_ip, $max_attempts, $lock_duration_seconds);
    echo json_encode([
        'success'            => true,
        'authenticated'      => $authenticated,
        'user'               => $auth_user,
        'domain_user'        => $domain_user ? $domain_user['username'] : 'admin',
        'failed_attempts'    => $lock_info['attempts'],
        'remaining_attempts' => $lock_info['remaining'],
        'locked'             => $lock_info['locked'],
        'seconds_remaining'  => $lock_info['seconds_remaining'],
        'unlock_at'          => $lock_info['unlock_at']
    ]);
    exit;
}

if ($action === 'logout') {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    unset($_SESSION['auth_user']);
    $cookie_path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') ?: '/';
    setcookie('session_token', '', [
        'expires' => time() - 3600,
        'path'    => $cookie_path
    ]);
    setcookie('dw_user', '', [
        'expires' => time() - 3600,
        'path'    => $cookie_path
    ]);
    header('Location: index.php');
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
