#!/usr/bin/env php
<?php
// ==============================================================================
// Client Administrator Configuration Utility (set_client_admin.php)
// addr.bar Remote Server Directory & Federated Correspondence Suite
// ==============================================================================
// Command-line only utility to designate a client user as the Client Administrator.
// The Client Administrator is granted access to the Main Contact Point's account
// via the web interface at <domain-name>/hotletter/.
//
// Web server access is strictly forbidden (HTTP 403).
// ==============================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("403 Forbidden: set_client_admin is a command-line utility and cannot be accessed from the web server.\n");
}

// ------------------------------------------------------------------------------
// Helper: Normalize domain input (strip scheme, port, trailing slashes, paths)
// ------------------------------------------------------------------------------
function normalize_domain($input) {
    $dom = trim($input);
    $dom = preg_replace('#^https?://#i', '', $dom);
    $dom = preg_replace('#/.*$#', '', $dom);
    return strtolower(trim(preg_replace('/:[0-9]+$/', '', $dom)));
}

// ------------------------------------------------------------------------------
// CLI Argument Parsing
// ------------------------------------------------------------------------------
$cli_domain = '';
$cli_user = '';
$check_status = false;
$remove_admin = false;
$list_users = false;
$non_interactive = false;

$args = array_slice($argv, 1);
$positional = [];

for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '-h' || $a === '--help') {
        echo "Usage: " . basename($argv[0]) . " [OPTIONS] [username]\n\n";
        echo "Designates a client user as the Client Administrator for a domain group.\n";
        echo "The Client Administrator is granted permission to access the Main Contact Point's account.\n";
        echo "Both Main Contact Point and client accounts are accessed from <domain-name>/hotletter/.\n\n";
        echo "Options:\n";
        echo "  -u, --user <username>         Username of client to grant Client Administrator privileges\n";
        echo "  -D, --domain <domain>         Target domain group (default: auto-detected or current host)\n";
        echo "  -s, --status                  Check the current Client Administrator for the domain group\n";
        echo "  -r, --remove, --clear         Revoke/clear Client Administrator privileges\n";
        echo "  -l, --list                    List all client users in the domain group\n";
        echo "  -d, --database <path>         Override path to SQLite database file\n";
        echo "  -y, --yes, --non-interactive  Run without interactive prompts\n";
        echo "  -h, --help                    Show this help message\n\n";
        echo "Examples:\n";
        echo "  " . basename($argv[0]) . "                                      # Interactive menu\n";
        echo "  " . basename($argv[0]) . " -u jason                             # Set 'jason' as Client Administrator\n";
        echo "  " . basename($argv[0]) . " -D novy.band -u jason                # Set Client Administrator for novy.band\n";
        echo "  " . basename($argv[0]) . " -D novy.band -s                      # View current Client Administrator\n";
        echo "  " . basename($argv[0]) . " -D novy.band -r                      # Remove Client Administrator\n";
        exit(0);
    } elseif ($a === '-D' || $a === '--domain') {
        $cli_domain = normalize_domain($args[++$i] ?? '');
    } elseif ($a === '-u' || $a === '--user' || $a === '--username') {
        $cli_user = trim($args[++$i] ?? '');
    } elseif ($a === '-s' || $a === '--status') {
        $check_status = true;
    } elseif ($a === '-r' || $a === '--remove' || $a === '--clear') {
        $remove_admin = true;
    } elseif ($a === '-l' || $a === '--list') {
        $list_users = true;
    } elseif ($a === '-y' || $a === '--yes' || $a === '--non-interactive') {
        $non_interactive = true;
    } elseif ($a === '-d' || $a === '--database' || $a === '--db-path') {
        $custom_db = trim($args[++$i] ?? '');
        if ($custom_db !== '') {
            putenv("SQLITE_PATH={$custom_db}");
        }
    } elseif (strpos($a, '-') !== 0) {
        $positional[] = $a;
    }
}

// Map positional argument
if ($cli_user === '' && isset($positional[0])) {
    $first = trim($positional[0]);
    if ($first === 'remove' || $first === 'clear' || $first === 'none') {
        $remove_admin = true;
    } elseif ($first === 'status') {
        $check_status = true;
    } elseif ($first === 'list') {
        $list_users = true;
    } elseif (strpos($first, '.') !== false && $cli_domain === '' && !isset($positional[1])) {
        $cli_domain = normalize_domain($first);
    } else {
        $cli_user = $first;
    }
}
if ($cli_domain === '' && isset($positional[1])) {
    $cli_domain = normalize_domain($positional[1]);
}

// ------------------------------------------------------------------------------
// Locate and load db.php
// ------------------------------------------------------------------------------
$db_file = null;
$config_candidates = [
    $cli_domain ? "/etc/tricurse/{$cli_domain}/contact.conf" : '',
    $cli_domain ? "/etc/tricurse/{$cli_domain}.conf" : '',
    getenv('DOMAIN') ? "/etc/tricurse/" . getenv('DOMAIN') . "/contact.conf" : '',
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
        '/var/www/html/hotletter/db.php',
        '/var/www/html/contact/db.php',
        '/addr.bar/github/hotletter.band/db.php',
        '/addr.bar/tricurse.com/contact/db.php'
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

// If domain specified on CLI, set environment so db.php isolates that domain group
if (!empty($cli_domain)) {
    putenv("DOMAIN={$cli_domain}");
    $_SERVER['HTTP_HOST'] = $cli_domain;
    $_SERVER['SERVER_NAME'] = $cli_domain;
}

require_once $db_file;

$pdo = function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null);
if (!$pdo) {
    fwrite(STDERR, "Error: Database connection could not be established.\n");
    exit(1);
}

$target_domain = $cli_domain ?: (function_exists('get_current_domain') ? get_current_domain() : 'localhost');
$target_domain = normalize_domain($target_domain);

// ------------------------------------------------------------------------------
// Fetch users for this domain group
// ------------------------------------------------------------------------------
function fetch_domain_users($pdo, $domain) {
    $users = [];
    try {
        $stmt = $pdo->prepare("SELECT id, username, domain, email, created_at FROM users WHERE domain = ? OR domain = ? OR domain IS NULL OR domain = '' ORDER BY id ASC");
        $stmt->execute([$domain, preg_replace('/:[0-9]+$/', '', $domain)]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}
    return $users;
}

$domain_users = fetch_domain_users($pdo, $target_domain);
$current_admin = function_exists('get_client_admin_user') ? get_client_admin_user($target_domain, $pdo) : null;

// ------------------------------------------------------------------------------
// Action: Status Check
// ------------------------------------------------------------------------------
if ($check_status) {
    echo "======================================================================\n";
    echo "CLIENT ADMINISTRATOR STATUS\n";
    echo "======================================================================\n";
    echo "Domain Group:         {$target_domain}\n";
    if ($current_admin) {
        echo "Client Administrator: {$current_admin}\n";
        echo "Status:               Active (Granted access to Main Contact Point account)\n";
        echo "Web Subdirectory:     https://{$target_domain}/hotletter/\n";
        
        $found = false;
        foreach ($domain_users as $u) {
            if (strcasecmp($u['username'], $current_admin) === 0) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            echo "Warning:              User '{$current_admin}' not found in 'users' table for this domain.\n";
        }
    } else {
        echo "Client Administrator: [None Configured]\n";
        echo "Status:               No client currently has access to the Main Contact Point account.\n";
    }
    echo "======================================================================\n";
    exit(0);
}

// ------------------------------------------------------------------------------
// Action: List Users
// ------------------------------------------------------------------------------
if ($list_users) {
    echo "======================================================================\n";
    echo "USERS IN DOMAIN GROUP: {$target_domain}\n";
    echo "======================================================================\n";
    if (empty($domain_users)) {
        echo "No users found in database for domain '{$target_domain}'.\n";
    } else {
        printf("%-6s | %-20s | %-12s | %s\n", "ID", "Username", "Role", "Created At");
        echo str_repeat("-", 60) . "\n";
        foreach ($domain_users as $u) {
            $is_admin = ($current_admin && strcasecmp($u['username'], $current_admin) === 0);
            $role_badge = $is_admin ? "Client Admin" : "Client User";
            printf("%-6d | %-20s | %-12s | %s\n", $u['id'], $u['username'], $role_badge, $u['created_at'] ?? 'N/A');
        }
    }
    echo "======================================================================\n";
    exit(0);
}

// ------------------------------------------------------------------------------
// Action: Remove Client Administrator
// ------------------------------------------------------------------------------
if ($remove_admin) {
    if (function_exists('remove_client_admin_user')) {
        remove_client_admin_user($target_domain, $pdo);
    }
    echo "======================================================================\n";
    echo "✓ Client Administrator revoked for domain group '{$target_domain}'.\n";
    echo "No client currently has access to the Main Contact Point account.\n";
    echo "======================================================================\n";
    exit(0);
}

// ------------------------------------------------------------------------------
// Interactive Selection (if user not specified)
// ------------------------------------------------------------------------------
if ($cli_user === '') {
    if ($non_interactive) {
        fwrite(STDERR, "Error: Username is required in non-interactive mode. Use -u <username> or -r to remove.\n");
        exit(1);
    }

    echo "======================================================================\n";
    echo "CLIENT ADMINISTRATOR CONFIGURATION\n";
    echo "======================================================================\n";
    echo "Domain Group:         {$target_domain}\n";
    echo "Current Admin:        " . ($current_admin ?: '[None]') . "\n";
    echo "Access URL:           https://{$target_domain}/hotletter/\n\n";

    if (!empty($domain_users)) {
        echo "Available Client Accounts in this group:\n";
        foreach ($domain_users as $idx => $u) {
            $is_admin = ($current_admin && strcasecmp($u['username'], $current_admin) === 0);
            $marker = $is_admin ? " [CURRENT CLIENT ADMIN]" : "";
            echo "  " . ($idx + 1) . ") " . $u['username'] . $marker . "\n";
        }
        echo "\n";
    } else {
        echo "Note: No users currently found in 'users' table for '{$target_domain}'.\n";
    }

    echo "Enter the username to designate as Client Administrator\n";
    echo "(or enter 'none' to remove, 'q' to quit): ";
    $line = trim(fgets(STDIN) ?: '');

    if ($line === '' || strcasecmp($line, 'q') === 0 || strcasecmp($line, 'quit') === 0) {
        echo "Operation cancelled.\n";
        exit(0);
    }

    if (strcasecmp($line, 'none') === 0 || strcasecmp($line, 'remove') === 0 || strcasecmp($line, 'clear') === 0) {
        if (function_exists('remove_client_admin_user')) {
            remove_client_admin_user($target_domain, $pdo);
        }
        echo "✓ Client Administrator revoked for domain group '{$target_domain}'.\n";
        exit(0);
    }

    // Check if numeric choice was entered
    if (is_numeric($line) && isset($domain_users[(int)$line - 1])) {
        $cli_user = $domain_users[(int)$line - 1]['username'];
    } else {
        $cli_user = $line;
    }
}

// ------------------------------------------------------------------------------
// Apply Client Administrator Designation
// ------------------------------------------------------------------------------
if (strcasecmp($cli_user, 'none') === 0 || strcasecmp($cli_user, 'remove') === 0 || strcasecmp($cli_user, 'clear') === 0) {
    if (function_exists('remove_client_admin_user')) {
        remove_client_admin_user($target_domain, $pdo);
    }
    echo "✓ Client Administrator revoked for domain group '{$target_domain}'.\n";
    exit(0);
}

// Verify user exists in domain users table
$user_exists = false;
foreach ($domain_users as $u) {
    if (strcasecmp($u['username'], $cli_user) === 0) {
        $user_exists = true;
        $cli_user = $u['username']; // Preserve exact database casing
        break;
    }
}

if (!$user_exists) {
    if ($non_interactive) {
        // In non-interactive mode, still allow setting if explicitly requested, but display notice
        echo "Notice: User '{$cli_user}' was not found in 'users' table, setting setting anyway.\n";
    } else {
        echo "Warning: User '{$cli_user}' is not currently in the 'users' table for domain '{$target_domain}'.\n";
        echo "Proceed with granting Client Administrator privileges to '{$cli_user}'? [y/N]: ";
        $ans = strtolower(trim(fgets(STDIN) ?: ''));
        if ($ans !== 'y' && $ans !== 'yes') {
            echo "Operation cancelled.\n";
            exit(0);
        }
    }
}

$ok = false;
if (function_exists('set_client_admin_user')) {
    $ok = set_client_admin_user($cli_user, $target_domain, $pdo);
}

if ($ok) {
    echo "======================================================================\n";
    echo "✓ SUCCESS: Client Administrator Configured\n";
    echo "======================================================================\n";
    echo "Domain Group:         {$target_domain}\n";
    echo "Client Administrator: {$cli_user}\n";
    echo "Access URL:           https://{$target_domain}/hotletter/\n";
    echo "\n";
    echo "User '{$cli_user}' is now granted permission to access the Main Contact\n";
    echo "Point's account. When logged into https://{$target_domain}/hotletter/,\n";
    echo "a '[ 🔑 Access Main Contact Point Account ]' action is available to switch\n";
    echo "into the Main Contact Point account.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    fwrite(STDERR, "Error: Failed to update site settings for domain group '{$target_domain}'.\n");
    exit(1);
}
