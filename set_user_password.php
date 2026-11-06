#!/usr/bin/env php
<?php
// ==============================================================================
// Site User Password Configuration Utility (set_user_password.php)
// addr.bar Remote Server Directory & Federated Correspondence Suite
// ==============================================================================
// Rewritten based directly on auth.php & signup.html architecture.
// Uses db.php PDO connection, standard PASSWORD_BCRYPT password hashing,
// and 32-byte hexadecimal session_token generation matching auth.php & index.php.
// Supports CLI execution only (web server access is forbidden).
// ==============================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("403 Forbidden: set_user_password is a command-line utility and cannot be accessed from the web server.\n");
}

// Locate and require db.php
$db_file = null;
if (file_exists('/etc/contact.conf')) {
    @include '/etc/contact.conf';
    if (!empty($webroot) && file_exists($webroot . '/db.php')) {
        $db_file = $webroot . '/db.php';
    }
}

if (!$db_file || !file_exists($db_file)) {
    $search_paths = [
        getenv('DB_PHP') ?: '',
        getenv('WEBROOT') ? rtrim(getenv('WEBROOT'), '/\\') . '/db.php' : '',
        __DIR__ . '/db.php',
        __DIR__ . '/../db.php',
        '/var/www/html/contact/db.php',
        '/addr.bar/contact/db.php',
        '/addr.bar/script/contact/db.php',
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
$cli_user = '';
$cli_pass = '';
$cli_email = '';
$send_link = false;
$list_only = false;
$non_interactive = false;

$args = array_slice($argv, 1);
$positional = [];

for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '-h' || $a === '--help') {
        echo "Usage: " . basename($argv[0]) . " [OPTIONS] [username] [new_password]\n\n";
        echo "Sets or updates the password for a site user in the 'users' table using PHP.\n";
        echo "Uses the exact same PASSWORD_BCRYPT algorithm and session token logic as auth.php.\n\n";
        echo "Options:\n";
        echo "  -u, --user <username>       Target username or email in 'users' table\n";
        echo "  -p, --password <password>   New password to set\n";
        echo "  -e, --email <email>         Recovery / setup email address\n";
        echo "  -s, --send-link             Generate setup token and dispatch password email\n";
        echo "  -d, --database <dbname>     Target database name (default: {$db})\n";
        echo "  -l, --list                  List all registered site users and exit\n";
        echo "  -y, --non-interactive       Run without interactive prompts\n";
        echo "  -h, --help                  Show this help message\n\n";
        exit(0);
    } elseif ($a === '-u' || $a === '--user' || $a === '--username') {
        $cli_user = $args[++$i] ?? '';
    } elseif ($a === '-p' || $a === '--password' || $a === '--pass') {
        $cli_pass = $args[++$i] ?? '';
    } elseif ($a === '-e' || $a === '--email') {
        $cli_email = $args[++$i] ?? '';
    } elseif ($a === '-s' || $a === '--send-link' || $a === '--setup-link') {
        $send_link = true;
    } elseif ($a === '-d' || $a === '--database' || $a === '--dbname') {
        $custom_db = $args[++$i] ?? '';
        if ($custom_db !== '') {
            try {
                $pdo->exec("USE `{$custom_db}`");
                $db = $custom_db;
            } catch (Exception $e) {
                fwrite(STDERR, "Error: Unable to use database '{$custom_db}': " . $e->getMessage() . "\n");
                exit(1);
            }
        }
    } elseif ($a === '-l' || $a === '--list') {
        $list_only = true;
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

// Handle --list option
if ($list_only) {
    echo "==================================================================\n";
    echo "    addr.bar Registered Site Users ('users' table)\n";
    echo "==================================================================\n";
    echo "Active Database: {$db} (Host: {$host})\n\n";
    try {
        $stmt = $pdo->query("SELECT id, username, email, created_at FROM users ORDER BY id ASC");
        $all = $stmt->fetchAll();
    } catch (Exception $e) {
        $all = [];
    }
    if (empty($all)) {
        echo "No users found in 'users' table of database '{$db}'.\n";
        try {
            $other_dbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
            $other_dbs = array_diff($other_dbs, ['information_schema', 'mysql', 'performance_schema', 'sys']);
            echo "Available databases: " . implode(', ', $other_dbs) . "\n";
            echo "Use -d <database> to target a different database (e.g. set_user_password -d " . reset($other_dbs) . " --list)\n";
        } catch (Exception $e) {}
        exit(0);
    }
    printf("%-5s | %-20s | %-30s | %-20s\n", "ID", "Username", "Email", "Created At");
    echo str_repeat("-", 80) . "\n";
    foreach ($all as $u) {
        printf("%-5d | %-20s | %-30s | %-20s\n", $u['id'], $u['username'], $u['email'] ?: "(none)", $u['created_at']);
    }
    exit(0);
}

echo "==================================================================\n";
echo "    Site User Password Configuration — addr.bar (auth.php engine)\n";
echo "==================================================================\n";
echo "Database: {$db} (Host: {$host})\n\n";

// Query existing users
$stmt = $pdo->query("SELECT id, username, email FROM users ORDER BY id ASC");
$existing_users = $stmt->fetchAll();

    // Determine target username (Single User Per Domain)
    $default_target = !empty($existing_users) ? $existing_users[0]['username'] : 'admin';
    $target_user = $cli_user;
    if ($target_user === '') {
        if ($non_interactive) {
            $target_user = $default_target;
        } else {
            echo "Domain user: {$default_target}\n";
            echo "Enter username for this domain [default: {$default_target}]: ";
            $line = trim(fgets(STDIN));
            $target_user = ($line !== '') ? $line : $default_target;
        }
    }

if ($target_user === '') {
    fwrite(STDERR, "Error: Username cannot be empty.\n");
    exit(1);
}

// Look up single domain user in 'users' table
$stmt = $pdo->query("SELECT id, username, email FROM users ORDER BY id ASC LIMIT 1");
$user_row = $stmt ? $stmt->fetch() : null;

if (!$user_row) {
    // If users table is empty, initialize single domain user record
    $ins = $pdo->prepare("INSERT INTO users (domain, username, password, email) VALUES (?, ?, ?, NULL)");
    $ins->execute([get_current_domain(), $target_user, password_hash('temporary', PASSWORD_BCRYPT)]);
    $user_id = (int)$pdo->lastInsertId();
    $user_row = ['id' => $user_id, 'username' => $target_user, 'email' => null];
}

$confirmed_name = $user_row['username'];
$user_id = $user_row['id'];
$target_email = $cli_email !== '' ? $cli_email : ($user_row['email'] ?? '');

echo "Target User: {$confirmed_name} (ID: {$user_id}, Email: " . ($target_email ?: '(none)') . ")\n";

if ($send_link) {
    $reset_token = bin2hex(random_bytes(32));
    $upd = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires_at = datetime('now', '+24 hours') WHERE id = ?");
    $upd->execute([$reset_token, $user_id]);

    $domain = get_current_domain();
    $link_domain = ($domain !== '' && $domain !== 'localhost') ? $domain : 'localhost';
    $setup_link = "http://{$link_domain}/contact/index.php?action=reset_password&token=" . urlencode($reset_token);

    echo "\n==================================================================\n";
    echo "✓ Password setup link generated for '{$confirmed_name}' ({$domain})!\n";
    echo "  - Setup Link:    {$setup_link}\n";
    echo "  - Token Expires: 24 hours\n";
    echo "==================================================================\n";
    echo "Open the link in your browser to set your password directly at your website.\n";
    exit(0);
}

// Determine new password
$new_pass = $cli_pass;
if ($new_pass === '') {
    while (true) {
        echo "Enter new password for '{$confirmed_name}': ";
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

// ----------------------------------------------------------------------
// Hash the password using the EXACT SAME method as auth.php / signup.html
// ----------------------------------------------------------------------
$hashed_password = password_hash($new_pass, PASSWORD_BCRYPT);
$session_token   = bin2hex(random_bytes(32));

// Update user in database
$upd = $pdo->prepare("UPDATE users SET password = ?, session_token = ? WHERE id = ?");
$upd->execute([$hashed_password, $session_token, $user_id]);

// Verification step: verify against newly saved database record
$chk = $pdo->prepare("SELECT password FROM users WHERE id = ?");
$chk->execute([$user_id]);
$saved_hash = $chk->fetchColumn();

if (!password_verify($new_pass, $saved_hash)) {
    fwrite(STDERR, "Error: password_verify() failed after updating database.\n");
    exit(1);
}

echo "\n==================================================================\n";
echo "    PASSWORD UPDATE COMPLETE!\n";
echo "==================================================================\n";
echo "✓ Successfully updated password for site user '{$confirmed_name}'.\n";
echo "✓ User ID:            {$user_id}\n";
echo "✓ Target Database:    {$db} -> table 'users'\n";
echo "✓ Encryption Method:  PASSWORD_BCRYPT (Identical to auth.php & signup.html)\n";
echo "✓ Session Token:      Generated & refreshed ({$session_token})\n";
echo "✓ Hash Verification:  PASSED (password_verify matched stored hash)\n";
echo "\nYou can now sign in to the website form with:\n";
echo "  - Username: {$confirmed_name}\n";
echo "  - Password: [SET SECURELY]\n";
echo "==================================================================\n";
exit(0);
