#!/usr/bin/env php
<?php
// ==============================================================================
// Client Name Update Utility (update_client_name.php)
// addr.bar Remote Server Directory & Federated Correspondence Suite
// ==============================================================================
// Command-line only utility to set or update client account names.
// Persists updates across:
//   1. site_settings table (key: 'client_name' & '<domain>::client_name')
//   2. users table ('client_name' column for domain accounts)
//   3. main_subscriptions table ('client_name' column for client registrations)
//   4. /etc/tricurse/<domain>/contact.conf (and <domain>.conf)
//   5. /etc/tricurse/installations.json (and ~/.local/share/tricurse/installations.json)
// Web server access is strictly forbidden (HTTP 403).
// ==============================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("403 Forbidden: update_client_name is a command-line utility and cannot be accessed from the web server.\n");
}

// ------------------------------------------------------------------------------
// Helper: Normalize domain input (strip scheme, port, trailing slashes, paths)
// ------------------------------------------------------------------------------
function normalize_domain($input) {
    $dom = trim($input);
    $dom = preg_replace('#^https?://#i', '', $dom);
    $dom = preg_replace('#/.*$#', '', $dom);
    return strtolower(trim($dom));
}

// ------------------------------------------------------------------------------
// CLI Argument Parsing
// ------------------------------------------------------------------------------
$cli_domain = '';
$cli_name = '';
$list_only = false;
$update_all = false;
$non_interactive = false;

$args = array_slice($argv, 1);
$positional = [];

for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '-h' || $a === '--help') {
        echo "Usage: " . basename($argv[0]) . " [OPTIONS] [domain] [new_name]\n\n";
        echo "Updates the administrator-managed client name for existing client accounts.\n";
        echo "Persists changes across site_settings, users, main_subscriptions, contact.conf, and installations.json.\n\n";
        echo "Options:\n";
        echo "  -D, --domain <domain>         Target client domain (e.g. client.example.com)\n";
        echo "  -N, -n, --name <name>         New client name to set\n";
        echo "  -l, --list                    List registered client accounts and their configured names\n";
        echo "  -a, --all                     Interactively review and update all client account names\n";
        echo "  -y, --yes, --non-interactive  Run without interactive prompts (requires domain and name)\n";
        echo "  -h, --help                    Show this help message\n\n";
        echo "Examples:\n";
        echo "  " . basename($argv[0]) . "                                     # Interactive menu to select and update\n";
        echo "  " . basename($argv[0]) . " -l                                  # List all clients and current names\n";
        echo "  " . basename($argv[0]) . " -D client.addr.bar -N 'Alpha Office' # Directly update a client name\n";
        echo "  " . basename($argv[0]) . " -a                                  # Step through all clients sequentially\n";
        exit(0);
    } elseif ($a === '-D' || $a === '--domain') {
        $cli_domain = normalize_domain($args[++$i] ?? '');
    } elseif ($a === '-N' || $a === '-n' || $a === '--name' || $a === '--client-name') {
        $cli_name = trim($args[++$i] ?? '');
    } elseif ($a === '-l' || $a === '--list') {
        $list_only = true;
    } elseif ($a === '-a' || $a === '--all') {
        $update_all = true;
    } elseif ($a === '-y' || $a === '--yes' || $a === '--non-interactive') {
        $non_interactive = true;
    } elseif (strpos($a, '-') !== 0) {
        $positional[] = $a;
    }
}

// Map positional arguments if flags not explicitly given
if ($cli_domain === '' && isset($positional[0])) {
    // If positional[0] looks like a domain (contains dot or matches installed client)
    $cli_domain = normalize_domain($positional[0]);
    if ($cli_name === '' && isset($positional[1])) {
        $cli_name = trim($positional[1]);
    }
} elseif ($cli_domain !== '' && $cli_name === '' && isset($positional[0])) {
    $cli_name = trim($positional[0]);
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
        $webroot = '';
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
$default_pdo = get_db();

// ------------------------------------------------------------------------------
// Discover Installations & Client Accounts
// ------------------------------------------------------------------------------
$registry_files = array_filter([
    getenv('REG_FILE') ?: '',
    getenv('TRICURSE_REGISTRY') ?: '',
    '/etc/tricurse/installations.json',
    getenv('HOME') ? (getenv('HOME') . '/.local/share/tricurse/installations.json') : ''
]);

$all_installations = [];

foreach ($registry_files as $rf) {
    if (file_exists($rf)) {
        $data = @json_decode(@file_get_contents($rf), true);
        if (is_array($data)) {
            foreach ($data as $item) {
                $dom = normalize_domain($item['domain'] ?? '');
                if ($dom !== '') {
                    $all_installations[$dom] = [
                        'domain'        => $dom,
                        'client_name'   => trim($item['client_name'] ?? ''),
                        'role'          => $item['role'] ?? 'main',
                        'subscribed_to' => $item['subscribed_to'] ?? '',
                        'webroot'       => $item['webroot'] ?? '',
                        'db_path'       => $item['db_path'] ?? ($item['messages_dir'] ? $item['messages_dir'] . '/contact.sqlite' : ''),
                        'config_file'   => $item['config_file'] ?? "/etc/tricurse/{$dom}/contact.conf"
                    ];
                }
            }
        }
    }
}

// Also scan /etc/tricurse/*/contact.conf
if (is_dir('/etc/tricurse')) {
    $dirs = @glob('/etc/tricurse/*/contact.conf');
    if ($dirs) {
        foreach ($dirs as $cf) {
            $dom = normalize_domain(basename(dirname($cf)));
            if ($dom !== '' && !isset($all_installations[$dom])) {
                $messages_dir = '';
                $sqlite_path = '';
                $webroot = '';
                $server_role = 'main';
                $subscribed_to = '';
                $client_name = '';
                @include $cf;
                $all_installations[$dom] = [
                    'domain'        => $dom,
                    'client_name'   => trim($client_name),
                    'role'          => $server_role ?: 'main',
                    'subscribed_to' => $subscribed_to ?: '',
                    'webroot'       => $webroot,
                    'db_path'       => $sqlite_path ?: ($messages_dir ? $messages_dir . '/contact.sqlite' : ''),
                    'config_file'   => $cf
                ];
            }
        }
    }
}

// Also query main_subscriptions table in default database for registered client domains
if ($default_pdo) {
    try {
        $stmt = $default_pdo->query("SELECT client_domain, client_name, main_domain FROM main_subscriptions");
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $dom = normalize_domain($row['client_domain'] ?? '');
                if ($dom !== '') {
                    if (!isset($all_installations[$dom])) {
                        $all_installations[$dom] = [
                            'domain'        => $dom,
                            'client_name'   => trim($row['client_name'] ?? ''),
                            'role'          => 'client',
                            'subscribed_to' => $row['main_domain'] ?? '',
                            'webroot'       => '',
                            'db_path'       => '',
                            'config_file'   => "/etc/tricurse/{$dom}/contact.conf"
                        ];
                    } elseif (empty($all_installations[$dom]['client_name']) && !empty($row['client_name'])) {
                        $all_installations[$dom]['client_name'] = trim($row['client_name']);
                    }
                }
            }
        }
    } catch (Exception $e) {}
}

// Resolve current client name for each installation
foreach ($all_installations as $dom => &$entry) {
    if (empty($entry['client_name'])) {
        // Try get_client_name from default_pdo
        if (function_exists('get_client_name') && $default_pdo) {
            $cn = get_client_name($default_pdo, $dom);
            if (!empty($cn)) {
                $entry['client_name'] = $cn;
            }
        }
    }
    if (empty($entry['client_name']) && !empty($entry['config_file']) && file_exists($entry['config_file'])) {
        $client_name = '';
        @include $entry['config_file'];
        if (!empty($client_name)) {
            $entry['client_name'] = trim($client_name);
        }
    }
    if (empty($entry['client_name']) && !empty($entry['db_path']) && file_exists($entry['db_path'])) {
        try {
            $p = new PDO("sqlite:" . $entry['db_path']);
            $s = $p->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? OR setting_key = 'client_name' LIMIT 1");
            $s->execute([$dom . '::client_name']);
            $v = $s->fetchColumn();
            if ($v) {
                $entry['client_name'] = trim($v);
            }
        } catch (Exception $e) {}
    }
}
unset($entry);

// Include all registered server accounts: client accounts and the main contact point
$clients = $all_installations;

// ------------------------------------------------------------------------------
// Function: Update Client Name Across All Locations
// ------------------------------------------------------------------------------
function execute_client_name_update($domain, $new_name, $client_info, $default_pdo, $all_installations) {
    $dom = normalize_domain($domain);
    $name = trim($new_name);

    if ($dom === '') {
        fwrite(STDERR, "Error: Domain cannot be empty.\n");
        return false;
    }
    if ($name === '') {
        fwrite(STDERR, "Error: Client name cannot be empty.\n");
        return false;
    }

    $updated_dbs = [];
    $updated_confs = [];
    $updated_registries = [];

    // 1. Gather all database files to update
    $db_paths = [];
    if (!empty($client_info['db_path']) && file_exists($client_info['db_path'])) {
        $db_paths[] = realpath($client_info['db_path']) ?: $client_info['db_path'];
    }
    if (!empty($client_info['messages_dir']) && file_exists($client_info['messages_dir'] . '/contact.sqlite')) {
        $p = realpath($client_info['messages_dir'] . '/contact.sqlite') ?: ($client_info['messages_dir'] . '/contact.sqlite');
        $db_paths[] = $p;
    }
    if (file_exists("/var/lib/tricurse/contact/{$dom}/contact.sqlite")) {
        $db_paths[] = "/var/lib/tricurse/contact/{$dom}/contact.sqlite";
    }
    if (file_exists("/var/lib/tricurse/contact/contact.sqlite")) {
        $db_paths[] = "/var/lib/tricurse/contact/contact.sqlite";
    }

    // Add databases from all recorded installations
    foreach ($all_installations as $inst) {
        if (!empty($inst['db_path']) && file_exists($inst['db_path'])) {
            $p = realpath($inst['db_path']) ?: $inst['db_path'];
            $db_paths[] = $p;
        }
    }
    $db_paths = array_unique(array_filter($db_paths));

    // Update each SQLite database
    foreach ($db_paths as $dbp) {
        try {
            $p = new PDO("sqlite:" . $dbp);
            $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

            // Ensure site_settings table and set client_name
            $p->exec("CREATE TABLE IF NOT EXISTS site_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT,
                updated_at TEXT DEFAULT (datetime('now'))
            )");
            $stmt = $p->prepare("INSERT OR REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, datetime('now'))");
            $stmt->execute([$dom . '::client_name', $name]);
            if (count($all_installations) <= 1) {
                $stmt->execute(['client_name', $name]);
            }

            // Ensure users table client_name column
            $user_cols = array_column($p->query("PRAGMA table_info(users)")->fetchAll(), 'name');
            if ($user_cols && !in_array('client_name', $user_cols)) {
                $p->exec("ALTER TABLE users ADD COLUMN client_name TEXT DEFAULT ''");
            }
            if ($user_cols) {
                $p->prepare("UPDATE users SET client_name = ? WHERE domain = ? OR domain = '' OR domain IS NULL")->execute([$name, $dom]);
            }

            // Ensure main_subscriptions table client_name column
            $sub_cols = array_column($p->query("PRAGMA table_info(main_subscriptions)")->fetchAll(), 'name');
            if ($sub_cols && !in_array('client_name', $sub_cols)) {
                $p->exec("ALTER TABLE main_subscriptions ADD COLUMN client_name TEXT DEFAULT ''");
            }
            if ($sub_cols) {
                $p->prepare("UPDATE main_subscriptions SET client_name = ? WHERE client_domain = ?")->execute([$name, $dom]);
            }

            $updated_dbs[] = $dbp;
        } catch (Exception $e) {}
    }

    // Also call set_client_name on default_pdo if available
    if ($default_pdo && function_exists('set_client_name')) {
        set_client_name($default_pdo, $dom, $name);
    }

    // 2. Update /etc/tricurse/<domain>/contact.conf and <domain>.conf
    $conf_candidates = [
        "/etc/tricurse/{$dom}/contact.conf",
        "/etc/tricurse/{$dom}.conf"
    ];
    if (!empty($client_info['config_file'])) {
        $conf_candidates[] = $client_info['config_file'];
    }
    $conf_candidates = array_unique(array_filter($conf_candidates));

    foreach ($conf_candidates as $cf) {
        if (file_exists($cf) && is_writable($cf)) {
            $content = file_get_contents($cf);
            if (strpos($content, '$client_name') !== false) {
                $content = preg_replace('/\$client_name\s*=\s*.*?;/', '$client_name = ' . var_export($name, true) . ';', $content);
            } else {
                $content = rtrim($content) . "\n\$client_name = " . var_export($name, true) . ";\n";
            }
            @file_put_contents($cf, $content);
            $updated_confs[] = $cf;
        }
    }

    // 3. Update installations.json files
    $reg_files = array_filter([
        getenv('REG_FILE') ?: '',
        getenv('TRICURSE_REGISTRY') ?: '',
        '/etc/tricurse/installations.json',
        getenv('HOME') ? (getenv('HOME') . '/.local/share/tricurse/installations.json') : ''
    ]);
    foreach ($reg_files as $rf) {
        if (file_exists($rf) && is_writable($rf)) {
            $data = @json_decode(@file_get_contents($rf), true);
            if (is_array($data)) {
                $changed = false;
                foreach ($data as &$item) {
                    $idom = normalize_domain($item['domain'] ?? '');
                    if ($idom === $dom) {
                        $item['client_name'] = $name;
                        $changed = true;
                    }
                }
                if ($changed) {
                    @file_put_contents($rf, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    $updated_registries[] = $rf;
                }
            }
        }
    }

    // Output success details
    $role_label = ($client_info['role'] ?? 'client') === 'main' ? 'Main Contact Point' : 'Client Account';
    echo "\n------------------------------------------------------------------\n";
    echo "✓ Name successfully updated for {$role_label}!\n";
    echo "  Domain:         {$dom}\n";
    echo "  Role:           {$role_label}\n";
    echo "  New Name:       {$name}\n";
    if (!empty($updated_confs)) {
        echo "  Config File(s): " . implode(', ', $updated_confs) . "\n";
    }
    if (!empty($updated_registries)) {
        echo "  Registry:       " . implode(', ', $updated_registries) . "\n";
    }
    if (!empty($updated_dbs)) {
        echo "  Database(s):    " . implode(', ', $updated_dbs) . "\n";
    }
    echo "------------------------------------------------------------------\n";

    return true;
}

// ------------------------------------------------------------------------------
// MODE: List Only (-l, --list)
// ------------------------------------------------------------------------------
if ($list_only) {
    echo "\n========================================================================================\n";
    echo "    addr.bar / Tricurse Contact Suite — Account Names (Main Point & Clients)\n";
    echo "========================================================================================\n";

    if (empty($clients)) {
        echo "No accounts found on this server.\n\n";
        exit(0);
    }

    printf("%-28s | %-24s | %-18s | %-18s\n", "Domain", "Configured Name", "Role", "Subscribed To");
    echo str_repeat("-", 96) . "\n";

    foreach ($clients as $dom => $c) {
        $c_name = $c['client_name'] !== '' ? $c['client_name'] : '(not set)';
        $role = ($c['role'] ?? 'client') === 'main' ? 'Main Contact Point' : 'Client Account';
        $sub = $c['subscribed_to'] !== '' ? $c['subscribed_to'] : ($role === 'Main Contact Point' ? '(Broadcasting)' : '-');

        printf("%-28s | %-24s | %-18s | %-18s\n",
            substr($dom, 0, 28),
            substr($c_name, 0, 24),
            substr($role, 0, 18),
            substr($sub, 0, 18)
        );
    }

    echo "========================================================================================\n";
    echo "Total accounts listed: " . count($clients) . "\n";
    echo "To update a name: " . basename($argv[0]) . " -D <domain> -N '<name>'\n\n";
    exit(0);
}

// ------------------------------------------------------------------------------
// MODE: Directly update specific domain with -D and -N
// ------------------------------------------------------------------------------
if ($cli_domain !== '' && $cli_name !== '') {
    $info = $clients[$cli_domain] ?? ($all_installations[$cli_domain] ?? [
        'domain' => $cli_domain,
        'role'   => 'client',
        'db_path' => "/var/lib/tricurse/contact/{$cli_domain}/contact.sqlite",
        'config_file' => "/etc/tricurse/{$cli_domain}/contact.conf"
    ]);

    $ok = execute_client_name_update($cli_domain, $cli_name, $info, $default_pdo, $all_installations);
    exit($ok ? 0 : 1);
}

// ------------------------------------------------------------------------------
// MODE: Specific domain with -D, but name not provided (-N missing)
// ------------------------------------------------------------------------------
if ($cli_domain !== '' && $cli_name === '') {
    if ($non_interactive) {
        fwrite(STDERR, "Error: Name is required in non-interactive mode (-N <name>).\n");
        exit(1);
    }

    $info = $clients[$cli_domain] ?? ($all_installations[$cli_domain] ?? [
        'domain' => $cli_domain,
        'client_name' => '',
        'role'   => 'client',
        'db_path' => "/var/lib/tricurse/contact/{$cli_domain}/contact.sqlite",
        'config_file' => "/etc/tricurse/{$cli_domain}/contact.conf"
    ]);

    $cur_name = $info['client_name'] ?? '';
    $role_label = ($info['role'] ?? 'client') === 'main' ? 'Main Contact Point' : 'Client Account';
    echo "\n==================================================================\n";
    echo "    addr.bar Account Name Update Utility\n";
    echo "==================================================================\n";
    echo "Target Domain:        {$cli_domain} [{$role_label}]\n";
    echo "Current Name:         " . ($cur_name !== '' ? $cur_name : "(not set)") . "\n";
    echo "------------------------------------------------------------------\n";

    while (true) {
        $prompt = $cur_name !== '' ? "Enter new name for '{$cli_domain}' [{$cur_name}]: " : "Enter new name for '{$cli_domain}': ";
        echo $prompt;
        $input = trim(fgets(STDIN));
        if ($input === '' && $cur_name !== '') {
            $input = $cur_name;
        }
        if ($input === '') {
            echo "Name cannot be empty. Please enter a valid name.\n";
            continue;
        }
        $cli_name = $input;
        break;
    }

    $ok = execute_client_name_update($cli_domain, $cli_name, $info, $default_pdo, $all_installations);
    exit($ok ? 0 : 1);
}

// ------------------------------------------------------------------------------
// MODE: Update All Accounts Sequentially (-a, --all)
// ------------------------------------------------------------------------------
if ($update_all) {
    if (empty($clients)) {
        fwrite(STDERR, "Error: No accounts found to update.\n");
        exit(1);
    }

    echo "\n==================================================================\n";
    echo "    addr.bar — Batch Account Name Update\n";
    echo "==================================================================\n";
    echo "Found " . count($clients) . " account(s) (including main contact point and clients).\n\n";

    $count = 0;
    foreach ($clients as $dom => $info) {
        $cur_name = $info['client_name'] ?? '';
        $role_label = ($info['role'] ?? 'client') === 'main' ? 'Main Contact Point' : 'Client Account';
        echo "Account: {$dom} [{$role_label}]\n";
        echo "Current Name: " . ($cur_name !== '' ? $cur_name : "(not set)") . "\n";

        if ($non_interactive) {
            // In non-interactive mode with -a, only update if name is given or skip
            if ($cli_name !== '') {
                execute_client_name_update($dom, $cli_name, $info, $default_pdo, $all_installations);
                $count++;
            }
            continue;
        }

        while (true) {
            $prompt = $cur_name !== '' ? "Enter new name for '{$dom}' (press Enter to keep [{$cur_name}]): " : "Enter new name for '{$dom}': ";
            echo $prompt;
            $input = trim(fgets(STDIN));
            if ($input === '' && $cur_name !== '') {
                echo "Keeping existing name: {$cur_name}\n\n";
                break;
            }
            if ($input === '') {
                echo "Name cannot be empty. Please enter a valid name.\n";
                continue;
            }
            execute_client_name_update($dom, $input, $info, $default_pdo, $all_installations);
            $count++;
            echo "\n";
            break;
        }
    }

    echo "✓ Finished updating {$count} account(s).\n\n";
    exit(0);
}

// ------------------------------------------------------------------------------
// MODE: Interactive Menu (Default when run with no arguments)
// ------------------------------------------------------------------------------
if ($non_interactive) {
    fwrite(STDERR, "Error: Domain (-D <domain>) and Name (-N <name>) are required in non-interactive mode.\n");
    exit(1);
}

if (empty($clients)) {
    echo "\nNo recorded accounts found in /etc/tricurse/installations.json or /etc/tricurse/*/contact.conf.\n";
    echo "Enter domain manually (or press Enter to cancel): ";
    $manual_dom = normalize_domain(trim(fgets(STDIN)));
    if ($manual_dom === '') {
        echo "Operation cancelled.\n";
        exit(0);
    }
    $info = [
        'domain'      => $manual_dom,
        'client_name' => '',
        'role'        => 'client',
        'db_path'     => "/var/lib/tricurse/contact/{$manual_dom}/contact.sqlite",
        'config_file' => "/etc/tricurse/{$manual_dom}/contact.conf"
    ];
    echo "Enter name for '{$manual_dom}': ";
    $manual_name = trim(fgets(STDIN));
    if ($manual_name === '') {
        fwrite(STDERR, "Error: Name cannot be empty.\n");
        exit(1);
    }
    $ok = execute_client_name_update($manual_dom, $manual_name, $info, $default_pdo, $all_installations);
    exit($ok ? 0 : 1);
}

$client_keys = array_values(array_keys($clients));
$num_clients = count($client_keys);

if ($num_clients === 1) {
    $sel_domain = $client_keys[0];
    $sel_info = $clients[$sel_domain];
    $cur_name = $sel_info['client_name'] ?? '';
    $role_label = ($sel_info['role'] ?? 'client') === 'main' ? 'Main Contact Point' : 'Client Account';

    echo "\n==================================================================\n";
    echo "    addr.bar Account Name Update Utility\n";
    echo "==================================================================\n";
    echo "Target Domain: {$sel_domain} [{$role_label}]\n";
    echo "Current Name:  " . ($cur_name !== '' ? $cur_name : "(not set)") . "\n";
    echo "------------------------------------------------------------------\n";

    while (true) {
        $prompt = $cur_name !== '' ? "Enter new name [{$cur_name}]: " : "Enter new name: ";
        echo $prompt;
        $input = trim(fgets(STDIN));
        if ($input === '' && $cur_name !== '') {
            $input = $cur_name;
        }
        if ($input === '') {
            echo "Name cannot be empty. Please enter a valid name.\n";
            continue;
        }
        $new_name = $input;
        break;
    }

    $ok = execute_client_name_update($sel_domain, $new_name, $sel_info, $default_pdo, $all_installations);
    exit($ok ? 0 : 1);
}

// Multiple accounts found: display selection menu
echo "\n==================================================================\n";
echo "    addr.bar Account Name Update Utility\n";
echo "==================================================================\n";
echo "Found {$num_clients} account(s) on this server (Main Contact Point & Clients):\n\n";

for ($idx = 0; $idx < $num_clients; $idx++) {
    $d = $client_keys[$idx];
    $c = $clients[$d];
    $cn = $c['client_name'] !== '' ? $c['client_name'] : '(not set)';
    $role_badge = ($c['role'] ?? 'client') === 'main' ? ' [Main Contact Point]' : ' [Client Account]';
    printf("  [%d] %-26s %-22s (Current: %s)\n", $idx + 1, $d, $role_badge, $cn);
}
echo "  [a] Update all accounts sequentially\n";
echo "  [q] Cancel and quit\n\n";

while (true) {
    echo "Select an account to update [1-{$num_clients}, a, q]: ";
    $choice = strtolower(trim(fgets(STDIN)));

    if ($choice === 'q') {
        echo "Operation cancelled.\n";
        exit(0);
    }

    if ($choice === 'a') {
        foreach ($clients as $dom => $info) {
            $cur_name = $info['client_name'] ?? '';
            $role_label = ($info['role'] ?? 'client') === 'main' ? 'Main Contact Point' : 'Client Account';
            echo "\nAccount: " . $dom . " [{$role_label}]\n";
            echo "Current Name: " . ($cur_name !== '' ? $cur_name : "(not set)") . "\n";
            while (true) {
                $prompt = $cur_name !== '' ? "Enter new name for '{$dom}' (press Enter to keep [{$cur_name}]): " : "Enter new name for '{$dom}': ";
                echo $prompt;
                $input = trim(fgets(STDIN));
                if ($input === '' && $cur_name !== '') {
                    echo "Keeping existing name: {$cur_name}\n";
                    break;
                }
                if ($input === '') {
                    echo "Name cannot be empty. Please enter a valid name.\n";
                    continue;
                }
                execute_client_name_update($dom, $input, $info, $default_pdo, $all_installations);
                break;
            }
        }
        echo "\n✓ Batch update completed successfully.\n\n";
        exit(0);
    }

    if (is_numeric($choice)) {
        $num = (int)$choice;
        if ($num >= 1 && $num <= $num_clients) {
            $sel_domain = $client_keys[$num - 1];
            $sel_info = $clients[$sel_domain];
            $cur_name = $sel_info['client_name'] ?? '';
            $role_label = ($sel_info['role'] ?? 'client') === 'main' ? 'Main Contact Point' : 'Client Account';

            echo "\nTarget Domain: {$sel_domain} [{$role_label}]\n";
            echo "Current Name:  " . ($cur_name !== '' ? $cur_name : "(not set)") . "\n";

            while (true) {
                $prompt = $cur_name !== '' ? "Enter new name for '{$sel_domain}' [{$cur_name}]: " : "Enter new name for '{$sel_domain}': ";
                echo $prompt;
                $input = trim(fgets(STDIN));
                if ($input === '' && $cur_name !== '') {
                    $input = $cur_name;
                }
                if ($input === '') {
                    echo "Name cannot be empty. Please enter a valid name.\n";
                    continue;
                }
                $new_name = $input;
                break;
            }

            $ok = execute_client_name_update($sel_domain, $new_name, $sel_info, $default_pdo, $all_installations);
            exit($ok ? 0 : 1);
        }
    }

    echo "Invalid option. Please enter a number between 1 and {$num_clients}, 'a', or 'q'.\n";
}
