#!/usr/bin/env php
<?php
// ==============================================================================
// Administrator Password Update Utility (update_password.php)
// addr.bar Remote Server Directory & Federated Correspondence Suite
// ==============================================================================
// Command-line only utility to set or update the administrator password in SQLite.
// Direct database access via db.php using PASSWORD_BCRYPT hashing.
// Web server access is strictly forbidden (HTTP 403).
// ==============================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("403 Forbidden: update_password is a command-line utility and cannot be accessed from the web server.\n");
}

// Parse command line arguments
$cli_domain = '';
$cli_user = '';
$cli_pass = '';
$non_interactive = false;

$args = array_slice($argv, 1);
$positional = [];

for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '-h' || $a === '--help') {
        echo "Usage: " . basename($argv[0]) . " [OPTIONS] [new_password]\n\n";
        echo "Updates the administrator password directly in the SQLite database.\n\n";
        echo "Options:\n";
        echo "  -p, --password <password>   New password to set\n";
        echo "  -u, --user <username>       Target administrator username (default: domain user)\n";
        echo "  -D, --domain <domain>       Target domain name (default: current host domain)\n";
        echo "  -y, --non-interactive       Run without interactive prompts\n";
        echo "  -h, --help                  Show this help message\n\n";
        echo "Examples:\n";
        echo "  " . basename($argv[0]) . "                           # Interactive prompt with hidden input\n";
        echo "  " . basename($argv[0]) . " -p 'MySecretPass123'      # Update password via CLI argument\n";
        echo "  " . basename($argv[0]) . " -u admin -p 'MyPass123'   # Update specific user\n";
        exit(0);
    } elseif ($a === '-p' || $a === '--password' || $a === '--pass') {
        $cli_pass = $args[++$i] ?? '';
    } elseif ($a === '-u' || $a === '--user' || $a === '--username') {
        $cli_user = $args[++$i] ?? '';
    } elseif ($a === '-D' || $a === '--domain') {
        $cli_domain = $args[++$i] ?? '';
    } elseif ($a === '-y' || $a === '--non-interactive') {
        $non_interactive = true;
    } elseif (strpos($a, '-') !== 0) {
        $positional[] = $a;
    }
}

// Locate and load db.php
$db_file = null;
$cli_target_domain = $cli_domain !== '' ? $cli_domain : (getenv('DOMAIN') ?: '');
$config_candidates = [
    $cli_target_domain ? "/etc/tricurse/{$cli_target_domain}/contact.conf" : '',
    $cli_target_domain ? "/etc/tricurse/{$cli_target_domain}.conf" : '',
    '/etc/tricurse/contact.conf',
    '/etc/contact.conf'
];
foreach ($config_candidates as $cfg) {
    if ($cfg !== '' && file_exists($cfg)) {
        @include $cfg;
        if (!empty($webroot) && file_exists($webroot . '/db.php')) {
            $db_file = $webroot . '/db.php';
            break;
        }
    }
}

if (!$db_file || !file_exists($db_file)) {
    $search_paths = [
        getenv('DB_PHP') ?: '',
        getenv('WEBROOT') ? rtrim(getenv('WEBROOT'), '/\\') . '/db.php' : '',
        __DIR__ . '/db.php',
        __DIR__ . '/../db.php',
        '/var/www/html/contact/db.php',
        '/addr.bar/tricurse/contact/db.php',
        '/addr.bar/addressbar.net/contact/db.php',
        '/var/www/html/Project/contact/db.php',
        '/var/www/html/addressbar.net/db.php'
    ];
    foreach ($search_paths as $p) {
        if ($p !== '' && file_exists($p)) {
            $db_file = $p;
            break;
        }
    }
}

if (!$db_file || !file_exists($db_file)) {
    fwrite(STDERR, "Error: db.php database configuration file not found.\n");
    exit(1);
}

require_once $db_file;

if ($cli_pass === '' && isset($positional[0])) {
    $cli_pass = $positional[0];
}

$target_domain = $cli_domain !== '' ? $cli_domain : get_current_domain();
$pdo = get_db();

if (!$pdo) {
    fwrite(STDERR, "Error: Unable to connect to SQLite database.\n");
    exit(1);
}

// Find target domain user
$target_user_row = null;
if ($cli_user !== '') {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$cli_user]);
    $target_user_row = $stmt->fetch();
}

if (!$target_user_row) {
    $target_user_row = get_single_domain_user($target_domain);
}

if (!$target_user_row) {
    $stmt = $pdo->query("SELECT * FROM users ORDER BY id ASC LIMIT 1");
    $target_user_row = $stmt ? $stmt->fetch() : null;
}

$is_new_user = false;
if (!$target_user_row) {
    $is_new_user = true;
    $target_user_row = [
        'id'       => null,
        'username' => $cli_user !== '' ? $cli_user : 'admin',
        'domain'   => $target_domain,
    ];
}

echo "==================================================================\n";
echo "    addr.bar Administrator Password Update Utility\n";
echo "==================================================================\n";
echo "Target User:    " . htmlspecialchars($target_user_row['username']) . ($is_new_user ? " (initializing new account)" : "") . "\n";
echo "Domain:         " . htmlspecialchars($target_user_row['domain'] ?: $target_domain) . "\n";
echo "Database:       " . ($sqlite_path ?? '(SQLite direct file)') . "\n";
echo "------------------------------------------------------------------\n\n";

$new_password = $cli_pass;

if ($new_password === '') {
    if ($non_interactive) {
        fwrite(STDERR, "Error: Password required in non-interactive mode (-p <password> or --password <password>).\n");
        exit(1);
    }

    while ($new_password === '') {
        $p1 = prompt_password_hidden("Enter new administrator password: ");
        if ($p1 === '') {
            echo "Password cannot be empty. Please try again.\n";
            continue;
        }
        $p2 = prompt_password_hidden("Confirm new administrator password: ");
        if ($p1 !== $p2) {
            echo "Passwords do not match. Please try again.\n";
            continue;
        }
        $new_password = $p1;
    }
}

if ($new_password === '') {
    fwrite(STDERR, "Error: Password cannot be empty.\n");
    exit(1);
}

// Hash password with bcrypt and generate fresh session token
$hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
$new_session_token = bin2hex(random_bytes(32));

try {
    if (!empty($target_user_row['id'])) {
        $stmt = $pdo->prepare("UPDATE users SET password = ?, session_token = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?");
        $stmt->execute([$hashed_password, $new_session_token, $target_user_row['id']]);
        $target_id = $target_user_row['id'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (domain, username, password, session_token) VALUES (?, ?, ?, ?)");
        $stmt->execute([$target_domain, $target_user_row['username'], $hashed_password, $new_session_token]);
        $target_id = (int)$pdo->lastInsertId();
    }

    // Clear failed login lockouts so administrator can immediately log in
    try {
        $pdo->exec("DELETE FROM login_attempts");
    } catch (Exception $e) {}

    // Verify the newly set password
    $verify_stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $verify_stmt->execute([$target_id]);
    $updated = $verify_stmt->fetch();

    if ($updated && password_verify($new_password, $updated['password'])) {
        echo "✓ Password successfully updated for user '" . $target_user_row['username'] . "'.\n";
        echo "✓ Password hashed securely using standard PASSWORD_BCRYPT.\n";
        echo "✓ Active sessions refreshed and login attempt locks cleared.\n";
        echo "\nYou may now log in at your website using your new password.\n";
        exit(0);
    } else {
        fwrite(STDERR, "Error: Password update verification failed.\n");
        exit(1);
    }
} catch (Exception $e) {
    fwrite(STDERR, "Database error updating password: " . $e->getMessage() . "\n");
    exit(1);
}
