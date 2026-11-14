#!/usr/bin/env php
<?php
// ==============================================================================
// Site User Creation Utility (create_user.php)
// addr.bar Remote Server Directory & Federated Correspondence Suite
// ==============================================================================
// Rewritten based directly on auth.php & signup.html architecture.
// Uses db.php PDO connection, standard PASSWORD_BCRYPT password hashing,
// and 32-byte hexadecimal session_token generation to match auth.php & index.php.
// Supports CLI execution only (web server access is forbidden).
// ==============================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("403 Forbidden: create_user is a command-line utility and cannot be accessed from the web server.\n");
}

// Locate and require db.php
$db_file = null;
$cli_target_domain = getenv('DOMAIN') ?: (getenv('TARGET_DOMAIN') ?: (getenv('HTTP_HOST') ?: (getenv('SERVER_NAME') ?: (getenv('CLIENT_DOMAIN') ?: ''))));
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

$db = $sqlite_path ?? ($db_file ?? 'contact.sqlite');
$host = 'SQLite';

// ==============================================================================
// CLI Request Handler
// ==============================================================================
$cli_domain = '';
$cli_user = '';
$cli_pass = '';
$non_interactive = false;

$args = array_slice($argv, 1);
$positional = [];

for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '-h' || $a === '--help') {
        echo "Usage: " . basename($argv[0]) . " [OPTIONS] [username] [password]\n\n";
        echo "Creates a new user in the 'users' table using PHP.\n";
        echo "Uses the exact same PASSWORD_BCRYPT algorithm and db.php connection as auth.php.\n\n";
        echo "Options:\n";
        echo "  -D, --domain <domain>       Target domain name (default: current host domain)\n";
        echo "  -u, --user <username>       Username for the new account (required)\n";
        echo "  -p, --password <password>   Password for the new account\n";
        echo "  -d, --database <dbname>     Target database name (default: {$db})\n";
        echo "  -y, --non-interactive       Run without interactive prompts\n";
        echo "  -h, --help                  Show this help message\n\n";
        echo "Examples:\n";
        echo "  " . basename($argv[0]) . " -u jdoe -p \"SecurePass123!\" -y\n";
        echo "  " . basename($argv[0]) . " -d accounts -u jdoe -p \"SecurePass123!\" -y\n";
        echo "  " . basename($argv[0]) . " operator \"OpSecret456!\"\n";
        exit(0);
    } elseif ($a === '-D' || $a === '--domain') {
        $cli_domain = strtolower(trim($args[++$i] ?? ''));
    } elseif ($a === '-u' || $a === '--user' || $a === '--username') {
        $cli_user = $args[++$i] ?? '';
    } elseif ($a === '-p' || $a === '--password' || $a === '--pass') {
        $cli_pass = $args[++$i] ?? '';
    } elseif ($a === '-d' || $a === '--database' || $a === '--dbname') {
        $custom_db = $args[++$i] ?? '';
        if ($custom_db !== '') {
            try {
                if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' || preg_match('/\.sqlite(\d+)?$/i', $custom_db)) {
                    $pdo = new PDO("sqlite:" . $custom_db);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $db = $custom_db;
                } else {
                    $pdo->exec("USE `{$custom_db}`");
                    $db = $custom_db;
                }
            } catch (Exception $e) {
                fwrite(STDERR, "Error: Unable to use database '{$custom_db}': " . $e->getMessage() . "\n");
                exit(1);
            }
        }
    } elseif ($a === '-y' || $a === '--non-interactive') {
        $non_interactive = true;
    } elseif (strpos($a, '-') !== 0) {
        $positional[] = $a;
    }
}

if ($cli_user === '' && isset($positional[0])) {
    $cli_user = $positional[0];
}
if ($cli_pass === '' && isset($positional[1])) {
    $cli_pass = $positional[1];
}

// Resolve domain if not explicitly passed via -D
if ($cli_domain === '') {
    if (!empty($cli_target_domain)) {
        $cli_domain = $cli_target_domain;
    } elseif (!empty($domain)) {
        $cli_domain = $domain;
    } else {
        $cli_domain = get_current_domain();
    }
}

$target_domain = strtolower(trim($cli_domain));

echo "==================================================================\n";
echo "    Create Site User — addr.bar (auth.php engine)\n";
echo "==================================================================\n";
echo "Database: {$db} (Host: {$host})\n";
echo "Domain:   {$target_domain}\n\n";

// Determine username
$new_user = $cli_user;
if ($new_user === '') {
    if ($non_interactive) {
        fwrite(STDERR, "Error: Username is required in non-interactive mode (-u <username>).\n");
        exit(1);
    }
    while (true) {
        echo "Enter username for new user: ";
        $input = trim(fgets(STDIN));
        if ($input === '') {
            echo "Username cannot be empty. Please enter a valid username.\n";
            continue;
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $input)) {
            echo "Username may only contain letters, numbers, underscores, dots, and hyphens.\n";
            continue;
        }
        $new_user = $input;
        break;
    }
}

if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $new_user)) {
    fwrite(STDERR, "Error: Username '{$new_user}' contains invalid characters. Allowed: a-z, A-Z, 0-9, _, ., -\n");
    exit(1);
}

// Check if the domain's sovereign user already exists
$stmt = $pdo->prepare("SELECT id, username FROM users WHERE domain = ? ORDER BY id ASC LIMIT 1");
$stmt->execute([$target_domain]);
$existing = $stmt ? $stmt->fetch() : null;

if (!$existing) {
    // Check for legacy unassigned user with NULL or empty domain
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE (domain IS NULL OR domain = '') ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $existing = $stmt ? $stmt->fetch() : null;
}

if ($existing && !$non_interactive) {
    echo "Notice: Domain user '{$existing['username']}' already exists for domain '{$target_domain}' (1 user per domain).\n";
    echo "Would you like to update the domain user to '{$new_user}' with a new password? [Y/n]: ";
    $ans = trim(fgets(STDIN));
    if ($ans !== '' && strtolower($ans[0]) !== 'y') {
        echo "Operation cancelled.\n";
        exit(0);
    }
}

// Determine password
$new_pass = $cli_pass;
if ($new_pass === '') {
    while (true) {
        echo "Enter password for new user '{$new_user}': ";
        system('stty -echo 2>/dev/null');
        $p1 = trim(fgets(STDIN));
        system('stty echo 2>/dev/null');
        echo "\n";
        if ($p1 === '') {
            echo "Password cannot be empty. Please enter a valid password.\n";
            continue;
        }
        echo "Confirm password for new user '{$new_user}': ";
        system('stty -echo 2>/dev/null');
        $p2 = trim(fgets(STDIN));
        system('stty echo 2>/dev/null');
        echo "\n";
        if ($p1 === $p2) {
            $new_pass = $p1;
            break;
        } else {
            echo "Passwords do not match. Please try again.\n";
        }
    }
}

if ($new_pass === '') {
    fwrite(STDERR, "Error: Password cannot be empty.\n");
    exit(1);
}

// ----------------------------------------------------------------------
// Hash the password and generate session token (exact same logic as auth.php signup)
// ----------------------------------------------------------------------
$hashed_password = password_hash($new_pass, PASSWORD_BCRYPT);
$session_token   = bin2hex(random_bytes(32));

// Enforce single user per domain in users table
if ($existing) {
    $upd = $pdo->prepare("UPDATE users SET domain = ?, username = ?, password = ?, session_token = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?");
    $upd->execute([$target_domain, $new_user, $hashed_password, $session_token, $existing['id']]);
    $new_id = (int)$existing['id'];
} else {
    $ins = $pdo->prepare("INSERT INTO users (domain, username, password, session_token) VALUES (?, ?, ?, ?)");
    $ins->execute([$target_domain, $new_user, $hashed_password, $session_token]);
    $new_id = (int)$pdo->lastInsertId();
}

try {
    $pdo->prepare("DELETE FROM login_attempts WHERE domain = ? OR domain IS NULL")->execute([$target_domain]);
} catch (Exception $e) {}

// Verification step: verify against newly saved database record
$chk = $pdo->prepare("SELECT password FROM users WHERE id = ?");
$chk->execute([$new_id]);
$saved_hash = $chk->fetchColumn();

if (!password_verify($new_pass, $saved_hash)) {
    fwrite(STDERR, "Error: password_verify() failed after creating user.\n");
    exit(1);
}

echo "\n==================================================================\n";
echo "    USER CREATION COMPLETE!\n";
echo "==================================================================\n";
echo "✓ Successfully created site user '{$new_user}' in 'users' table.\n";
echo "✓ User ID:            {$new_id}\n";
echo "✓ Encryption Method:  PASSWORD_BCRYPT (Identical to auth.php & signup.html)\n";
echo "✓ Session Token:      Generated ({$session_token})\n";
echo "✓ Hash Verification:  PASSED (password_verify matched stored hash)\n";
echo "\nThe new user can now sign in to the website form immediately:\n";
echo "  - Username: {$new_user}\n";
echo "  - Password: [SET SECURELY]\n";
echo "==================================================================\n";
exit(0);
