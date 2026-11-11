#!/usr/bin/env php
<?php
// ==============================================================================
// Client Removal Utility (remove_client.php)
// addr.bar Remote Server Directory & Federated Correspondence Suite
// ==============================================================================
// Command-line only utility to cleanly remove an existing or orphaned client
// installation from:
//   1. /etc/tricurse/installations.json (and ~/.local/share/tricurse/installations.json)
//   2. /etc/tricurse/<domain>/ (contact.conf and flat .conf files)
//   3. contact.sqlite database (users, main_subscriptions, site_settings, login_attempts, push_subscriptions)
//   4. /etc/hosts (optional loopback entry cleanup)
// Web server access is strictly forbidden (HTTP 403).
// ==============================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("403 Forbidden: remove_client is a command-line utility and cannot be accessed from the web server.\n");
}

$ansi = [
    'reset'   => "\033[0m",
    'bold'    => "\033[1m",
    'red'     => "\033[31m",
    'green'   => "\033[32m",
    'yellow'  => "\033[33m",
    'blue'    => "\033[34m",
    'cyan'    => "\033[36m"
];

// Helper: Normalize domain input
function normalize_domain($input) {
    $dom = trim($input);
    $dom = preg_replace('#^https?://#i', '', $dom);
    $dom = preg_replace('#/.*$#', '', $dom);
    return strtolower(trim($dom));
}

// Locate DB helper if available
$db_helper = dirname(__FILE__) . '/db.php';
if (file_exists($db_helper)) {
    require_once $db_helper;
}

// ------------------------------------------------------------------------------
// Gather all registered installations
// ------------------------------------------------------------------------------
function get_all_installations() {
    $reg_files = array_filter([
        getenv('REG_FILE') ?: '',
        '/etc/tricurse/installations.json',
        (getenv('HOME') ?: '') . '/.local/share/tricurse/installations.json'
    ]);

    $installs = [];

    foreach ($reg_files as $rf) {
        if ($rf !== '' && file_exists($rf)) {
            $data = @json_decode(@file_get_contents($rf), true);
            if (is_array($data)) {
                foreach ($data as $item) {
                    $d = normalize_domain($item['domain'] ?? '');
                    if ($d === '' || filter_var($d, FILTER_VALIDATE_IP) || preg_match('/^[0-9\.\:]+$/', $d) || $d === 'localhost') continue;
                    $wb = $item['webroot'] ?? '';
                    $key = $d . '::' . $wb;
                    $installs[$key] = $item;
                    $installs[$key]['domain'] = $d;
                }
            }
        }
    }

    // Also scan /etc/tricurse/*/contact.conf
    if (is_dir('/etc/tricurse')) {
        $dirs = @glob('/etc/tricurse/*/contact.conf');
        if ($dirs) {
            foreach ($dirs as $cf) {
                $d = normalize_domain(basename(dirname($cf)));
                if ($d === '' || filter_var($d, FILTER_VALIDATE_IP) || preg_match('/^[0-9\.\:]+$/', $d) || $d === 'localhost') continue;
                $key_prefix = $d . '::';
                $found = false;
                foreach ($installs as $k => $v) {
                    if (strpos($k, $key_prefix) === 0) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $client_name = ''; $server_role = 'client'; $webroot = ''; $messages_dir = ''; $sqlite_path = '';
                    @include $cf;
                    $installs[$d . '::' . $webroot] = [
                        'domain'        => $d,
                        'client_name'   => $client_name,
                        'role'          => $server_role ?: 'client',
                        'webroot'       => $webroot,
                        'messages_dir'  => $messages_dir,
                        'db_path'       => $sqlite_path,
                        'config_file'   => $cf
                    ];
                }
            }
        }
    }

    // Also check database users/subscriptions for domains not in registry
    $db_file = '/var/lib/tricurse/contact/contact.sqlite';
    if (!file_exists($db_file) && getenv('HOME')) {
        $user_db = getenv('HOME') . '/.local/share/tricurse/contact.sqlite';
        if (file_exists($user_db)) $db_file = $user_db;
    }
    if (file_exists($db_file)) {
        try {
            $pdo = new PDO("sqlite:" . $db_file);
            $stmt = $pdo->query("SELECT domain, client_name FROM users WHERE domain IS NOT NULL AND domain != ''");
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $d = normalize_domain($row['domain'] ?? '');
                    if ($d === '') continue;
                    $found = false;
                    foreach ($installs as $k => $v) {
                        if (($v['domain'] ?? '') === $d) {
                            $found = true;
                            if (empty($installs[$k]['client_name']) && !empty($row['client_name'])) {
                                $installs[$k]['client_name'] = $row['client_name'];
                            }
                            break;
                        }
                    }
                    if (!$found) {
                        $installs[$d . '::'] = [
                            'domain'      => $d,
                            'client_name' => $row['client_name'] ?? '',
                            'role'        => 'client',
                            'webroot'     => '',
                            'db_path'     => $db_file
                        ];
                    }
                }
            }
        } catch (Exception $e) {}
    }

    return array_values($installs);
}

// ------------------------------------------------------------------------------
// Perform Complete Client Removal
// ------------------------------------------------------------------------------
function remove_client_entry($domain, $webroot = null, $quiet = false) {
    global $ansi;
    $dom = normalize_domain($domain);
    if ($dom === '') return false;

    if (!$quiet) {
        echo "{$ansi['bold']}==> Removing client: {$ansi['cyan']}{$dom}{$ansi['reset']}...\n";
    }

    // 1. Remove from installations.json registries
    $reg_files = [
        '/etc/tricurse/installations.json',
        (getenv('HOME') ?: '') . '/.local/share/tricurse/installations.json'
    ];
    $target_wb = $webroot ? (realpath($webroot) ?: $webroot) : null;
    $reg_cleaned = false;

    foreach ($reg_files as $rf) {
        if ($rf !== '' && file_exists($rf)) {
            $data = @json_decode(@file_get_contents($rf), true);
            if (is_array($data)) {
                $new_data = [];
                foreach ($data as $item) {
                    $idom = normalize_domain($item['domain'] ?? '');
                    $iwb = !empty($item['webroot']) ? (realpath($item['webroot']) ?: $item['webroot']) : '';
                    if ($idom === $dom && ($target_wb === null || $iwb === $target_wb || !file_exists($iwb . '/index.php'))) {
                        $reg_cleaned = true;
                        continue;
                    }
                    $new_data[] = $item;
                }
                if ($reg_cleaned && is_writable($rf)) {
                    @file_put_contents($rf, json_encode($new_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            }
        }
    }
    if (!$quiet) {
        echo "  {$ansi['green']}✓ Registry entry removed from installations.json{$ansi['reset']}\n";
    }

    // 2. Remove configuration files
    $conf_paths = [
        "/etc/tricurse/{$dom}/contact.conf",
        "/etc/tricurse/{$dom}.conf",
        (getenv('HOME') ?: '') . "/.local/share/tricurse/{$dom}/contact.conf",
        (getenv('HOME') ?: '') . "/.local/share/tricurse/{$dom}.conf"
    ];
    $conf_cleaned = false;
    foreach ($conf_paths as $cp) {
        if ($cp !== '' && file_exists($cp)) {
            @unlink($cp);
            $dir = dirname($cp);
            if (is_dir($dir) && count(scandir($dir)) <= 2) {
                @rmdir($dir);
            }
            $conf_cleaned = true;
        }
    }
    if ($conf_cleaned && !$quiet) {
        echo "  {$ansi['green']}✓ Configuration files removed from /etc/tricurse/{$dom}{$ansi['reset']}\n";
    }

    // 3. Database cleanup (contact.sqlite)
    $db_candidates = [
        '/var/lib/tricurse/contact/contact.sqlite',
        (getenv('HOME') ?: '') . '/.local/share/tricurse/contact.sqlite'
    ];
    foreach ($db_candidates as $db_file) {
        if ($db_file !== '' && file_exists($db_file)) {
            try {
                $pdo = new PDO("sqlite:" . $db_file);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

                $safe_exec = function($sql, $params = []) use ($pdo) {
                    try {
                        $st = $pdo->prepare($sql);
                        if ($st) $st->execute($params);
                    } catch (Exception $e) {}
                };

                $safe_exec("DELETE FROM users WHERE LOWER(domain) = ?", [$dom]);
                $safe_exec("DELETE FROM main_subscriptions WHERE LOWER(client_domain) = ?", [$dom]);
                $safe_exec("DELETE FROM site_settings WHERE setting_key LIKE ? OR setting_key LIKE ?", [$dom . '::%', '%::' . $dom]);
                $safe_exec("DELETE FROM login_attempts WHERE LOWER(domain) = ?", [$dom]);
                $safe_exec("DELETE FROM push_subscriptions WHERE LOWER(domain) = ?", [$dom]);
                $safe_exec("DELETE FROM push_disabled_sources WHERE LOWER(domain) = ?", [$dom]);

                if (!$quiet) {
                    echo "  {$ansi['green']}✓ Database records purged from {$db_file}{$ansi['reset']}\n";
                }
            } catch (Exception $e) {}
        }
    }

    // 4. Check if loopback entry in /etc/hosts should be cleaned
    if (is_readable('/etc/hosts') && is_writable('/etc/hosts')) {
        $content = @file_get_contents('/etc/hosts');
        if ($content && preg_match('/^\s*(?:127\.\d+\.\d+\.\d+|::1)\s+.*\b' . preg_quote($dom, '/') . '\b/mi', $content)) {
            // Only clean if no other active installation uses this domain
            $lines = explode("\n", $content);
            $new_lines = [];
            foreach ($lines as $line) {
                if (preg_match('/^\s*(?:127\.\d+\.\d+\.\d+|::1)\s+.*\b' . preg_quote($dom, '/') . '\b/mi', $line)) {
                    // Remove domain from host line
                    $line = preg_replace('/\b' . preg_quote($dom, '/') . '\b/', '', $line);
                    $line = trim(preg_replace('/\s+/', ' ', $line));
                    $parts = explode(' ', $line);
                    if (count($parts) > 1) {
                        $new_lines[] = $line;
                    }
                } else {
                    $new_lines[] = $line;
                }
            }
            @file_put_contents('/etc/hosts', implode("\n", $new_lines));
            if (!$quiet) {
                echo "  {$ansi['green']}✓ Cleaned loopback host entry for '{$dom}' in /etc/hosts{$ansi['reset']}\n";
            }
        }
    }

    echo "{$ansi['bold']}{$ansi['green']}✓ Client '{$dom}' removed successfully!{$ansi['reset']}\n\n";
    run_html_backup_if_server_installed();
    return true;
}

// ------------------------------------------------------------------------------
// Run html_backup if github.com/addressbar-net/server is installed
// ------------------------------------------------------------------------------
function run_html_backup_if_server_installed() {
    global $ansi;
    $backup_bin = null;
    $server_dir = null;
    $candidates = [
        '/addr.bar/tricurse/server',
        dirname(__DIR__) . '/server',
        dirname(dirname(__DIR__)) . '/server'
    ];
    foreach ($candidates as $cd) {
        if (is_dir($cd)) {
            if (is_dir($cd . '/.git')) {
                $rem = trim(@shell_exec("git -C " . escapeshellarg($cd) . " remote get-url origin 2>/dev/null") ?: '');
                if (stripos($rem, 'addressbar-net/server') !== false) {
                    $server_dir = $cd;
                    if (file_exists($cd . '/bin/html_backup') && is_executable($cd . '/bin/html_backup')) {
                        $backup_bin = $cd . '/bin/html_backup';
                    }
                    break;
                }
            }
            if (file_exists($cd . '/bin/html_backup')) {
                $server_dir = $cd;
                $backup_bin = $cd . '/bin/html_backup';
                break;
            }
        }
    }

    if (!$backup_bin) {
        if (file_exists('/usr/local/bin/html_backup') && is_executable('/usr/local/bin/html_backup')) {
            $backup_bin = '/usr/local/bin/html_backup';
        } else {
            $which = trim(@shell_exec('command -v html_backup 2>/dev/null') ?: '');
            if ($which && file_exists($which) && is_executable($which)) {
                $backup_bin = $which;
            }
        }
    }

    if ($backup_bin) {
        @mkdir('/tmp/html', 0755, true);
        echo "{$ansi['cyan']}==> Running html_backup (github.com/addressbar-net/server detected)...{$ansi['reset']}\n";
        passthru(escapeshellcmd($backup_bin), $code);
        if ($code === 0) {
            echo "{$ansi['green']}✓ html_backup completed successfully.{$ansi['reset']}\n\n";
        }
    }
}

// ------------------------------------------------------------------------------
// CLI Arguments Parsing
// ------------------------------------------------------------------------------
$cli_domain = '';
$cli_webroot = '';
$clean_orphans = false;
$list_only = false;
$auto_yes = false;

$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '-h' || $a === '--help') {
        echo "Usage: " . basename($argv[0]) . " [OPTIONS] [domain]\n\n";
        echo "addr.bar Client Removal & Orphan Cleanup Utility\n";
        echo "Removes old or relocated client installations from registries, configuration, and database.\n\n";
        echo "Options:\n";
        echo "  -D, --domain <domain>         Target client domain/subdomain to remove\n";
        echo "  -d, --dir, --webroot <path>   Target installation directory to remove\n";
        echo "  -c, --clean-orphans           Automatically remove all clients whose webroot has no index.php\n";
        echo "  -l, --list                    List all registered clients and their active/orphaned status\n";
        echo "  -y, --yes                     Non-interactive mode: skip confirmation prompts\n";
        echo "  -h, --help                    Display this help message and exit\n\n";
        echo "Examples:\n";
        echo "  sudo " . basename($argv[0]) . "                                # Interactive selection menu\n";
        echo "  sudo " . basename($argv[0]) . " -c                             # Clean all orphaned client installations\n";
        echo "  sudo " . basename($argv[0]) . " -D oldclient.example.com -y    # Remove specific client directly\n\n";
        exit(0);
    } elseif ($a === '-D' || $a === '--domain') {
        $cli_domain = $args[++$i] ?? '';
    } elseif ($a === '-d' || $a === '--dir' || $a === '--webroot') {
        $cli_webroot = $args[++$i] ?? '';
    } elseif ($a === '-c' || $a === '--clean-orphans') {
        $clean_orphans = true;
    } elseif ($a === '-l' || $a === '--list') {
        $list_only = true;
    } elseif ($a === '-y' || $a === '--yes') {
        $auto_yes = true;
    } elseif (!empty($a) && $a[0] !== '-' && empty($cli_domain)) {
        $cli_domain = $a;
    }
}

// ------------------------------------------------------------------------------
// Execute Specified Actions
// ------------------------------------------------------------------------------
$installs = get_all_installations();

// 1. List Only
if ($list_only) {
    echo "========================================================================================\n";
    echo "addr.bar Registered Client Installations & Status\n";
    echo "========================================================================================\n";
    printf("%-26s | %-16s | %-12s | %-24s\n", "Domain / Subdomain", "Client Name", "Status", "Directory");
    echo "----------------------------------------------------------------------------------------\n";
    foreach ($installs as $ins) {
        $dom = $ins['domain'] ?? '';
        $name = $ins['client_name'] ?? '(none)';
        $wb = $ins['webroot'] ?? '';
        $is_active = (!empty($wb) && is_dir($wb) && file_exists($wb . '/index.php'));
        $status_label = $is_active ? "🟢 Active" : "⚠️ Orphaned (no index.php)";
        printf("%-26s | %-16s | %-12s | %-24s\n",
            substr($dom, 0, 26),
            substr($name, 0, 16),
            $status_label,
            substr($wb ?: '(not specified)', 0, 24)
        );
    }
    echo "========================================================================================\n\n";
    exit(0);
}

// 2. Clean All Orphans
if ($clean_orphans) {
    echo "\n{$ansi['bold']}==> Scanning for orphaned client installations (webroot missing index.php)...{$ansi['reset']}\n";
    $orphans = [];
    foreach ($installs as $ins) {
        $wb = $ins['webroot'] ?? '';
        $has_index = (!empty($wb) && is_dir($wb) && file_exists($wb . '/index.php'));
        if (!$has_index) {
            $orphans[] = $ins;
        }
    }

    if (empty($orphans)) {
        echo "{$ansi['green']}✓ No orphaned installations found. All registered clients have an active index.php.{$ansi['reset']}\n\n";
        exit(0);
    }

    echo "Found " . count($orphans) . " orphaned installation(s):\n";
    foreach ($orphans as $o) {
        echo "  - {$ansi['yellow']}" . ($o['domain'] ?? '') . "{$ansi['reset']} (Webroot: " . ($o['webroot'] ?: 'none') . ")\n";
    }
    echo "\n";

    if (!$auto_yes) {
        echo "Do you want to permanently remove these orphaned client(s)? (y/N): ";
        $ans = trim(fgets(STDIN));
        if (strtolower($ans) !== 'y' && strtolower($ans) !== 'yes') {
            echo "Operation cancelled.\n\n";
            exit(0);
        }
    }

    foreach ($orphans as $o) {
        remove_client_entry($o['domain'], $o['webroot'] ?? null);
    }
    echo "{$ansi['bold']}{$ansi['green']}✓ All orphaned installations cleaned successfully!{$ansi['reset']}\n\n";
    exit(0);
}

// 3. Direct Domain Removal via Flag
if (!empty($cli_domain)) {
    $cli_domain = normalize_domain($cli_domain);
    if (!$auto_yes) {
        echo "Are you sure you want to permanently remove client '{$cli_domain}'? (y/N): ";
        $ans = trim(fgets(STDIN));
        if (strtolower($ans) !== 'y' && strtolower($ans) !== 'yes') {
            echo "Operation cancelled.\n\n";
            exit(0);
        }
    }
    remove_client_entry($cli_domain, $cli_webroot ?: null);
    exit(0);
}

// 4. Interactive Selection Menu
if (empty($installs)) {
    echo "{$ansi['yellow']}Notice: No registered client installations found on this server.{$ansi['reset']}\n\n";
    exit(0);
}

echo "========================================================================================\n";
echo "{$ansi['bold']}addr.bar Client Removal & Management Utility{$ansi['reset']}\n";
echo "========================================================================================\n";
echo "Registered installations on this server:\n\n";
printf("  %-4s %-24s %-16s %-10s %-24s\n", "[#]", "Domain / Subdomain", "Client Name", "Status", "Directory");
echo "  --------------------------------------------------------------------------------------\n";

$orphan_indices = [];
for ($idx = 0; $idx < count($installs); $idx++) {
    $ins = $installs[$idx];
    $dom = $ins['domain'] ?? '';
    $name = !empty($ins['client_name']) ? $ins['client_name'] : '(none)';
    $wb = $ins['webroot'] ?? '';
    $has_index = (!empty($wb) && is_dir($wb) && file_exists($wb . '/index.php'));
    $status_label = $has_index ? "🟢 Active" : "⚠️ Orphan";
    if (!$has_index) {
        $orphan_indices[] = $idx;
    }
    printf("  %-4s %-24s %-16s %-10s %-24s\n",
        "[" . ($idx + 1) . "]",
        substr($dom, 0, 24),
        substr($name, 0, 16),
        $status_label,
        substr($wb ?: '(no webroot)', 0, 24)
    );
}

echo "  --------------------------------------------------------------------------------------\n";
if (!empty($orphan_indices)) {
    echo "  [C] Clean all " . count($orphan_indices) . " orphaned installation(s) with no index.php\n";
}
echo "  [Q] Quit without making changes\n";
echo "========================================================================================\n";

echo "Select an option or installation number to remove: ";
$choice = trim(fgets(STDIN));

if (strtolower($choice) === 'q' || $choice === '') {
    echo "Exiting without making changes.\n\n";
    exit(0);
}

if (strtolower($choice) === 'c') {
    foreach ($orphan_indices as $oidx) {
        $o = $installs[$oidx];
        remove_client_entry($o['domain'], $o['webroot'] ?? null);
    }
    echo "{$ansi['bold']}{$ansi['green']}✓ All orphaned installations cleaned successfully!{$ansi['reset']}\n\n";
    exit(0);
}

$num = (int)$choice;
if ($num < 1 || $num > count($installs)) {
    echo "{$ansi['red']}Invalid selection. Exiting.{$ansi['reset']}\n\n";
    exit(1);
}

$selected = $installs[$num - 1];
$dom = $selected['domain'];
$name = $selected['client_name'] ?? $dom;

echo "Are you sure you want to permanently remove client '{$name}' ({$dom})? (y/N): ";
$ans = trim(fgets(STDIN));
if (strtolower($ans) !== 'y' && strtolower($ans) !== 'yes') {
    echo "Operation cancelled.\n\n";
    exit(0);
}

remove_client_entry($dom, $selected['webroot'] ?? null);
exit(0);
