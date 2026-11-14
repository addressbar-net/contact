#!/usr/bin/env php
<?php
// ==============================================================================
// PHP Database User Password Setup Utility (set_password.php)
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
    exit("403 Forbidden: set_password is a command-line utility and cannot be accessed from the web server.\n");
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
// CLI Request Handling
// ==============================================================================
echo "==================================================================\n";
echo "    PHP Site Password Configuration — addr.bar (auth.php engine)\n";
echo "==================================================================\n";
echo "Database: {$db} (Host: {$host})\n\n";

// Query existing users with domain information
$users = [];
try {
    $stmt = $pdo->query("SELECT id, username, domain FROM users ORDER BY id ASC");
    $users = $stmt ? $stmt->fetchAll() : [];
} catch (Exception $e) {}

if (!empty($users)) {
    echo "Existing registered users:\n";
    foreach ($users as $u) {
        $dom_label = !empty($u['domain']) ? " (Domain: {$u['domain']})" : "";
        echo "  - ID {$u['id']}: {$u['username']}{$dom_label}\n";
    }
    echo "\n";
}

$cli_domain = '';
$cli_user = '';
$cli_pass = '';
$send_link = false;
$non_interactive = false;

$args = array_slice($argv, 1);
$positional = [];

for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '-h' || $a === '--help') {
        echo "Usage: " . basename($argv[0]) . " [OPTIONS] [username] [password]\n\n";
        echo "Sets or updates the password for a domain user in 'users'.\n\n";
        echo "Options:\n";
        echo "  -D, --domain <domain>       Target domain name (default: configured host domain)\n";
        echo "  -u, --user <username>       Target username for this domain\n";
        echo "  -p, --password <password>   New password to set\n";
        echo "  -s, --send-link             Generate setup token and URL for browser password setup\n";
        echo "  -d, --database <dbname>     Target database name (default: {$db})\n";
        echo "  -y, --non-interactive       Run without interactive prompts\n";
        echo "  -h, --help                  Show this help message\n";
        exit(0);
    } elseif ($a === '-D' || $a === '--domain') {
        $cli_domain = strtolower(trim($args[++$i] ?? ''));
    } elseif ($a === '-u' || $a === '--user' || $a === '--username') {
        $cli_user = trim($args[++$i] ?? '');
    } elseif ($a === '-p' || $a === '--password' || $a === '--pass') {
        $cli_pass = trim($args[++$i] ?? '');
    } elseif ($a === '-s' || $a === '--send-link' || $a === '--setup-link') {
        $send_link = true;
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

try {
    $stmt = $pdo->query("SELECT id, username, domain FROM users ORDER BY id ASC");
    $users = $stmt ? $stmt->fetchAll() : [];
} catch (Exception $e) {}

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

// Locate target user row in users table
$user = null;
if ($cli_user !== '') {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE domain = ? AND username = ? LIMIT 1");
    $stmt->execute([$target_domain, $cli_user]);
    $user = $stmt->fetch();
    if (!$user) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND (domain IS NULL OR domain = '') LIMIT 1");
        $stmt->execute([$cli_user]);
        $user = $stmt->fetch();
        if ($user) {
            $pdo->prepare("UPDATE users SET domain = ? WHERE id = ?")->execute([$target_domain, $user['id']]);
            $user['domain'] = $target_domain;
        }
    }
} else {
    // Look for user specifically tied to this domain
    $stmt = $pdo->prepare("SELECT * FROM users WHERE domain = ? ORDER BY (username = 'admin') DESC, id ASC LIMIT 1");
    $stmt->execute([$target_domain]);
    $user = $stmt->fetch();
    if (!$user) {
        // Look for legacy user with NULL or empty domain
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (domain IS NULL OR domain = '') ORDER BY (username = 'admin') DESC, id ASC LIMIT 1");
        $stmt->execute();
        $user = $stmt->fetch();
        if ($user) {
            $pdo->prepare("UPDATE users SET domain = ? WHERE id = ?")->execute([$target_domain, $user['id']]);
            $user['domain'] = $target_domain;
        }
    }
    // If no user found for target_domain and only one registered user exists in DB, adopt that user
    if (!$user && count($users) === 1) {
        $user = $users[0];
        if (!empty($user['domain'])) {
            $target_domain = $user['domain'];
        } else {
            $pdo->prepare("UPDATE users SET domain = ? WHERE id = ?")->execute([$target_domain, $user['id']]);
            $user['domain'] = $target_domain;
        }
    }
}

// Only if no user exists at all in the database, initialize single user for this domain
if (!$user) {
    $user = get_or_init_domain_user($pdo, $target_domain);
}

$confirmed_name = $cli_user !== '' ? $cli_user : ($user['username'] ?? 'admin');
$user_id = $user['id'];

echo "Target Domain: {$target_domain} | User: {$confirmed_name} (ID: {$user_id})\n";

if ($send_link) {
    $reset_token = bin2hex(random_bytes(32));
    $upd = $pdo->prepare("UPDATE users SET domain = ?, username = ?, reset_token = ?, reset_expires_at = datetime('now', '+24 hours') WHERE id = ?");
    $upd->execute([$target_domain, $confirmed_name, $reset_token, $user_id]);

    $link_domain = ($target_domain !== '' && $target_domain !== 'localhost') ? $target_domain : 'localhost';
    $setup_link = "http://{$link_domain}/contact/index.php?action=reset_password&token=" . urlencode($reset_token);

    echo "\n==================================================================\n";
    echo "✓ Password setup link generated for '{$confirmed_name}' ({$target_domain})!\n";
    echo "  - Setup Link:    {$setup_link}\n";
    echo "  - Token Expires: 24 hours\n";
    echo "==================================================================\n";
    echo "Open the link in your browser to set your password directly at your website.\n";
    exit(0);
}

$new_pass = $cli_pass;
if ($new_pass === '') {
    if ($non_interactive) {
        fwrite(STDERR, "Error: Password is required in non-interactive mode (-p <password>).\n");
        exit(1);
    }
    while (true) {
        echo "Enter new password for '{$confirmed_name}' ({$target_domain}): ";
        system('stty -echo 2>/dev/null');
        $p1 = trim(fgets(STDIN));
        system('stty echo 2>/dev/null');
        echo "\n";
        if ($p1 === '') {
            echo "Password cannot be empty. Please enter a valid password.\n";
            continue;
        }
        echo "Confirm new password for '{$confirmed_name}': ";
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

// Hash using PHP's native bcrypt and refresh session token (matching auth.php)
$hash = password_hash($new_pass, PASSWORD_BCRYPT);
$token = bin2hex(random_bytes(32));

// Update in database
$upd = $pdo->prepare("UPDATE users SET domain = ?, username = ?, password = ?, session_token = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?");
$upd->execute([$target_domain, $confirmed_name, $hash, $token, $user_id]);
try {
    $pdo->prepare("DELETE FROM login_attempts WHERE domain = ? OR domain IS NULL")->execute([$target_domain]);
} catch (Exception $e) {}

// Verify hash matches
$chk = $pdo->prepare("SELECT password FROM users WHERE id = ?");
$chk->execute([$user_id]);
$saved_hash = $chk->fetchColumn();

if (!password_verify($new_pass, $saved_hash)) {
    fwrite(STDERR, "Error: Verification failed after writing to database.\n");
    exit(1);
}

echo "\n==================================================================\n";
echo "✓ Password successfully updated in PHP and saved to 'users' table!\n";
echo "  - Domain:   {$target_domain}\n";
echo "  - User:     {$confirmed_name} (ID: {$user_id})\n";
echo "  - Database: {$db}\n";
echo "  - Format:   Bcrypt (" . substr($saved_hash, 0, 7) . "...)\n";
echo "  - Token:    Generated ({$token})\n";
echo "  - Status:   VERIFIED (password_verify returned true)\n";
echo "==================================================================\n";
echo "You can now log into the website form with this password.\n";
exit(0);
