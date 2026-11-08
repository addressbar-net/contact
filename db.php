<?php
// ==============================================================================
// Database Connection Utility (db.php)
// addr.bar Remote Server Directory & Federated Correspondence Suite
// ==============================================================================
// Direct file access SQLite PDO connection. Zero database server daemon,
// zero database passwords, zero network configuration required.
// ==============================================================================

// Determine SQLite database file path & persistent message storage directory
$db_file = null;

if (getenv('SQLITE_PATH')) {
    $db_file = getenv('SQLITE_PATH');
} elseif (getenv('DB_PATH')) {
    $db_file = getenv('DB_PATH');
} elseif (getenv('MESSAGES_DIR')) {
    $db_file = rtrim(getenv('MESSAGES_DIR'), '/\\') . '/contact.sqlite';
} elseif (getenv('DATA_DIR')) {
    $db_file = rtrim(getenv('DATA_DIR'), '/\\') . '/contact.sqlite';
}

if (file_exists(__DIR__ . '/db_config.php')) {
    include __DIR__ . '/db_config.php';
    if (!empty($sqlite_path)) {
        $db_file = $sqlite_path;
    } elseif (!empty($db_path)) {
        $db_file = $db_path;
    } elseif (!empty($messages_dir)) {
        $db_file = rtrim($messages_dir, '/\\') . '/contact.sqlite';
    } elseif (!empty($data_dir)) {
        $db_file = rtrim($data_dir, '/\\') . '/contact.sqlite';
    }
} else {
    // Check system-wide tricurse configuration candidates
    $host_domain = !empty($_SERVER['HTTP_HOST']) ? preg_replace('/:[0-9]+$/', '', $_SERVER['HTTP_HOST']) : '';
    $config_paths = [
        $host_domain ? "/etc/tricurse/{$host_domain}/contact.conf" : '',
        $host_domain ? "/etc/tricurse/{$host_domain}.conf" : '',
        '/etc/tricurse/contact.conf',
        '/etc/contact.conf'
    ];
    foreach ($config_paths as $cp) {
        if ($cp !== '' && file_exists($cp)) {
            @include $cp;
            if (!empty($sqlite_path)) {
                $db_file = $sqlite_path;
                break;
            } elseif (!empty($messages_dir)) {
                $db_file = rtrim($messages_dir, '/\\') . '/contact.sqlite';
                break;
            }
        }
    }
}

// Fallback if not configured via db_config.php, /etc/tricurse, or environment
if (empty($db_file)) {
    $default_dir = '/var/lib/tricurse/contact';
    if (!is_dir($default_dir) && !@mkdir($default_dir, 0777, true)) {
        $user_dir = (getenv('HOME') ?: '/tmp') . '/.local/share/tricurse';
        $db_file = $user_dir . '/contact.sqlite';
    } else {
        $db_file = $default_dir . '/contact.sqlite';
    }
}

// Command line override: -d, --database, --db-path <path>
if (php_sapi_name() === 'cli' && isset($argv)) {
    for ($i = 0; $i < count($argv); $i++) {
        if (($argv[$i] === '-d' || $argv[$i] === '--database' || $argv[$i] === '--db-path') && isset($argv[$i + 1])) {
            $db_file = trim($argv[$i + 1]);
            break;
        }
    }
}

// Require database to reside strictly outside the web document root
if (php_sapi_name() !== 'cli') {
    $real_db_dir = realpath(dirname($db_file)) ?: dirname($db_file);
    $real_web_dir = realpath(__DIR__) ?: __DIR__;
    $doc_root = !empty($_SERVER['DOCUMENT_ROOT']) ? (realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']) : '';

    $is_in_web = false;
    if ($real_db_dir === $real_web_dir || strpos($real_db_dir, $real_web_dir . DIRECTORY_SEPARATOR) === 0) {
        $is_in_web = true;
    }
    if ($doc_root && ($real_db_dir === $doc_root || strpos($real_db_dir, $doc_root . DIRECTORY_SEPARATOR) === 0)) {
        $is_in_web = true;
    }

    if ($is_in_web) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Security Error: The SQLite database file ({$db_file}) is configured inside the public web directory. To prevent public HTTP download, the database MUST be located outside the web root (e.g. /var/lib/contact/contact.sqlite).\n");
    }
}

$db_dir = dirname($db_file);
if (!is_dir($db_dir)) {
    if (!@mkdir($db_dir, 0777, true)) {
        // Fallback for non-root users when system dir cannot be created
        $user_dir = (getenv('HOME') ?: '/tmp') . '/.local/share/tricurse';
        if (!is_dir($user_dir)) @mkdir($user_dir, 0777, true);
        $db_file = $user_dir . '/contact.sqlite';
        $db_dir = dirname($db_file);
    }
}
@chmod($db_dir, 0777);

$pdo = null;
try {
    $pdo = new PDO("sqlite:" . $db_file, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
    // High-concurrency WAL mode and pragmas
    $pdo->exec("PRAGMA journal_mode = WAL;");
    $pdo->exec("PRAGMA foreign_keys = ON;");
    $pdo->exec("PRAGMA busy_timeout = 5000;");

    @chmod($db_file, 0666);
    if (file_exists($db_file . '-wal')) @chmod($db_file . '-wal', 0666);
    if (file_exists($db_file . '-shm')) @chmod($db_file . '-shm', 0666);
} catch (PDOException $e) {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => "Database connection failed. Unable to access SQLite file '{$db_file}': " . $e->getMessage()
        ]);
        exit;
    } else {
        fwrite(STDERR, "Error: Unable to open SQLite database '{$db_file}': " . $e->getMessage() . "\n");
        exit(1);
    }
}

if (!function_exists('get_db')) {
    function get_db() {
        global $pdo;
        return $pdo;
    }
}

// Ensure required tables exist with required columns and multi-domain isolation
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT DEFAULT NULL,
            username TEXT NOT NULL,
            password TEXT NOT NULL,
            client_name TEXT DEFAULT '',
            email TEXT DEFAULT NULL,
            session_token TEXT DEFAULT NULL,
            reset_token TEXT DEFAULT NULL,
            reset_expires_at TEXT DEFAULT NULL,
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_user_domain ON users(domain);

        CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT DEFAULT NULL,
            ip_address TEXT NOT NULL,
            attempted_at TEXT DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_ip_domain_attempted ON login_attempts(ip_address, domain, attempted_at);

        CREATE TABLE IF NOT EXISTS site_settings (
            setting_key TEXT NOT NULL PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TEXT DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS blocked_sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain_or_url TEXT NOT NULL UNIQUE,
            ip_address TEXT DEFAULT NULL,
            reason TEXT DEFAULT NULL,
            is_blocked INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS incoming_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_url TEXT NOT NULL,
            sender_host TEXT NOT NULL,
            sender_ip TEXT NOT NULL,
            sender_rdns TEXT DEFAULT NULL,
            dns_verification_status TEXT NOT NULL,
            dns_verification_details TEXT DEFAULT NULL,
            sender_name TEXT DEFAULT NULL,
            sender_email TEXT DEFAULT NULL,
            subject TEXT DEFAULT '',
            message_body TEXT NOT NULL,
            is_read INTEGER DEFAULT 0,
            received_at TEXT DEFAULT (datetime('now')),
            recipient_user TEXT DEFAULT NULL,
            is_sent_copy INTEGER DEFAULT 0,
            all_recipients TEXT DEFAULT NULL,
            deleted_by_users TEXT DEFAULT NULL,
            is_delayed INTEGER DEFAULT 0,
            retry_after TEXT DEFAULT NULL,
            destination_url TEXT DEFAULT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_sender_host ON incoming_messages(sender_host);
        CREATE INDEX IF NOT EXISTS idx_received_at ON incoming_messages(received_at);
        CREATE INDEX IF NOT EXISTS idx_is_delayed ON incoming_messages(is_delayed);
        CREATE INDEX IF NOT EXISTS idx_recipient_user ON incoming_messages(recipient_user);

        CREATE TABLE IF NOT EXISTS sent_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            destination_url TEXT NOT NULL,
            subject TEXT DEFAULT '',
            message_body TEXT NOT NULL,
            delivery_status TEXT NOT NULL,
            response_code INTEGER DEFAULT NULL,
            response_message TEXT DEFAULT NULL,
            sent_at TEXT DEFAULT (datetime('now')),
            recipient_users TEXT DEFAULT NULL,
            all_recipients TEXT DEFAULT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_delivery_status ON sent_messages(delivery_status);
        CREATE INDEX IF NOT EXISTS idx_sent_at ON sent_messages(sent_at);

        CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT DEFAULT '',
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT DEFAULT '',
            auth TEXT DEFAULT '',
            user_agent TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_push_endpoint ON push_subscriptions(endpoint);
        CREATE INDEX IF NOT EXISTS idx_push_domain ON push_subscriptions(domain);

        CREATE TABLE IF NOT EXISTS main_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_domain TEXT NOT NULL,
            main_domain TEXT NOT NULL,
            client_url TEXT DEFAULT '',
            is_local INTEGER DEFAULT 1,
            client_name TEXT DEFAULT '',
            subscribed_at TEXT DEFAULT (datetime('now')),
            UNIQUE(client_domain, main_domain)
        );
        CREATE INDEX IF NOT EXISTS idx_main_sub_client ON main_subscriptions(client_domain);
        CREATE INDEX IF NOT EXISTS idx_main_sub_main ON main_subscriptions(main_domain);

        CREATE TABLE IF NOT EXISTS message_reads (
            message_id INTEGER NOT NULL,
            domain TEXT NOT NULL,
            read_at TEXT DEFAULT (datetime('now')),
            PRIMARY KEY (message_id, domain)
        );

        CREATE TABLE IF NOT EXISTS message_deletions (
            message_id INTEGER NOT NULL,
            domain TEXT NOT NULL,
            deleted_at TEXT DEFAULT (datetime('now')),
            PRIMARY KEY (message_id, domain)
        );

        CREATE TABLE IF NOT EXISTS message_archives (
            message_id INTEGER NOT NULL,
            domain TEXT NOT NULL,
            archived_at TEXT DEFAULT (datetime('now')),
            PRIMARY KEY (message_id, domain)
        );
        CREATE INDEX IF NOT EXISTS idx_msg_archive_dom ON message_archives(domain, archived_at);

        CREATE TABLE IF NOT EXISTS push_disabled_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT NOT NULL DEFAULT '',
            source_identifier TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now')),
            UNIQUE(domain, source_identifier)
        );
        CREATE INDEX IF NOT EXISTS idx_push_disabled_domain_source ON push_disabled_sources(domain, source_identifier);
    ");

    // Dynamic column check via SQLite PRAGMA table_info
    $inc_cols_raw = $pdo->query("PRAGMA table_info(incoming_messages)")->fetchAll();
    $inc_cols = array_column($inc_cols_raw, 'name');
    if (!in_array("target_domain", $inc_cols)) {
        $pdo->exec("ALTER TABLE incoming_messages ADD COLUMN target_domain TEXT DEFAULT ''");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_target_domain ON incoming_messages(target_domain)");
    }

    $user_cols_raw = $pdo->query("PRAGMA table_info(users)")->fetchAll();
    $user_cols = array_column($user_cols_raw, 'name');
    if (!in_array("domain", $user_cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN domain TEXT DEFAULT NULL");
    }
    if (!in_array("client_name", $user_cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN client_name TEXT DEFAULT ''");
    }
    if (!in_array("reset_token", $user_cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN reset_token TEXT DEFAULT NULL");
    }
    if (!in_array("reset_expires_at", $user_cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN reset_expires_at TEXT DEFAULT NULL");
    }

    $sub_cols_raw = $pdo->query("PRAGMA table_info(main_subscriptions)")->fetchAll();
    $sub_cols = array_column($sub_cols_raw, 'name');
    if (!in_array("client_name", $sub_cols)) {
        $pdo->exec("ALTER TABLE main_subscriptions ADD COLUMN client_name TEXT DEFAULT ''");
    }

    $la_cols_raw = $pdo->query("PRAGMA table_info(login_attempts)")->fetchAll();
    $la_cols = array_column($la_cols_raw, 'name');
    if (!in_array("domain", $la_cols)) {
        $pdo->exec("ALTER TABLE login_attempts ADD COLUMN domain TEXT DEFAULT NULL");
    }

    // Default site settings initialization
    $default_settings = [
        'allow_incoming_communications'             => '1',
        'push_notifications_enabled'                => '1',
        'push_notifications_outside_enabled'        => '1',
        'push_notifications_inside_enabled'         => '1',
        'push_notifications_main_broadcast_enabled' => '1',
        'rate_limiting_enabled'                     => '1',
        'rate_limit_outside_per_day'                => '10',
        'rate_limit_inside_per_day'                 => '100',
        'rate_limit_per_website_per_day'            => '10',
        'server_role'                               => 'main',
        'subscribed_to_main_domain'                 => '',
        'subscribed_to_main_url'                    => ''
    ];
    $set_stmt = $pdo->prepare("INSERT OR IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($default_settings as $sk => $sv) {
        $set_stmt->execute([$sk, $sv]);
    }

    // Migrate any legacy deleted messages into the archive so history is preserved
    try {
        $pdo->exec("INSERT OR IGNORE INTO message_archives (message_id, domain, archived_at) SELECT message_id, domain, deleted_at FROM message_deletions");
    } catch (Exception $e) {}
} catch (Exception $e) {
    // Non-fatal if tables already exist
}

// Settings Helpers (supports domain-scoped values with global fallbacks)
if (!function_exists('get_setting')) {
    function get_setting($key, $default = '', $domain = null) {
        $pdo = function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null);
        if (!$pdo) return $default;
        $dom = ($domain !== null && $domain !== false) ? $domain : (function_exists('get_current_domain') ? get_current_domain() : null);
        try {
            if ($dom) {
                $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
                $stmt->execute([$dom . '::' . $key]);
                $val = $stmt->fetchColumn();
                if ($val !== false && $val !== null) return $val;
            }
            $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return ($val !== false && $val !== null) ? $val : $default;
        } catch (Exception $e) {
            return $default;
        }
    }
}

if (!function_exists('set_setting')) {
    function set_setting($key, $value, $domain = null) {
        $pdo = function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null);
        if (!$pdo) return false;
        $dom = ($domain === false) ? null : (($domain !== null) ? $domain : (function_exists('get_current_domain') ? get_current_domain() : null));
        $save_key = $dom ? ($dom . '::' . $key) : $key;
        try {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, datetime('now'))");
            return $stmt->execute([$save_key, (string)$value]);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('get_main_contact_point_url')) {
    function get_main_contact_point_url($domain = null) {
        $dom = $domain ?: (function_exists('get_current_domain') ? get_current_domain() : 'localhost');
        $pdo = function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null);

        // 1. Check site_settings for configured subscribed main endpoint URL
        if (function_exists('get_setting')) {
            $sub_url = get_setting('subscribed_to_main_url', '', $dom);
            if (!empty($sub_url)) return $sub_url;

            $sub_dom = get_setting('subscribed_to_main_domain', '', $dom);
            if (!empty($sub_dom)) {
                return (strpos($sub_dom, '://') !== false) ? $sub_dom : "https://" . rtrim($sub_dom, '/') . "/contact";
            }
        }

        // 2. Check main_subscriptions table
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT main_domain, client_url FROM main_subscriptions WHERE client_domain = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$dom]);
                $row = $stmt->fetch();
                if ($row && !empty($row['main_domain'])) {
                    return (strpos($row['main_domain'], '://') !== false) ? $row['main_domain'] : "https://" . rtrim($row['main_domain'], '/') . "/contact";
                }
            } catch (Exception $e) {}
        }

        // 3. Scan server installation registry for the active Main Recipient Point
        $regFiles = [
            '/etc/tricurse/installations.json',
            (getenv('HOME') ?: '') . '/.local/share/tricurse/installations.json'
        ];
        foreach ($regFiles as $rf) {
            if ($rf !== '' && file_exists($rf)) {
                $data = @json_decode(@file_get_contents($rf), true);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        if (($item['role'] ?? '') === 'main' && !empty($item['domain'])) {
                            return "https://" . rtrim($item['domain'], '/') . "/contact";
                        }
                    }
                }
            }
        }

        // 4. Check /etc/tricurse/*/contact.conf files
        $confFiles = array_merge(
            @glob('/etc/tricurse/*/contact.conf') ?: [],
            @glob('/etc/tricurse/*.conf') ?: []
        );
        foreach ($confFiles as $cf) {
            $content = @file_get_contents($cf);
            if ($content && preg_match('/\$server_role\s*=\s*[\'"]main[\'"]/i', $content)) {
                if (preg_match('/\$domain\s*=\s*[\'"]([^\'"]+)[\'"]/i', $content, $m)) {
                    return "https://" . rtrim($m[1], '/') . "/contact";
                }
            }
        }

        // 5. If this domain itself is configured with the main role
        if (function_exists('get_setting') && get_setting('server_role', 'main', $dom) === 'main') {
            return ($dom === 'localhost') ? 'http://localhost/contact' : "https://" . rtrim($dom, '/') . "/contact";
        }

        // 6. Default fallback
        return 'https://tricurse.com/contact';
    }
}

if (!function_exists('format_address_display')) {
    function format_address_display($raw_address) {
        if (empty($raw_address)) return '';
        $parts = preg_split('/[;,]+/', $raw_address);
        $cleaned_parts = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            $p = preg_replace('#^https?://#i', '', $p);
            $p = preg_replace('#/index\.php$#i', '', $p);
            $p = preg_replace('#^index\.php$#i', '', $p);
            $p = rtrim($p, '/');
            if ($p !== '') {
                $cleaned_parts[] = $p;
            }
        }
        return implode('; ', $cleaned_parts);
    }
}

if (!function_exists('get_local_server_clients')) {
    /**
     * Retrieve all accounts / clients on the local server with their preset names.
     */
    function get_local_server_clients($pdo = null) {
        $pdo = $pdo ?: (function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null));
        $clients_map = [];

        // 1. From installations.json
        $reg_files = ['/etc/tricurse/installations.json', (getenv('HOME') ?: '') . '/.local/share/tricurse/installations.json'];
        foreach ($reg_files as $rf) {
            if ($rf !== '' && file_exists($rf)) {
                $data = @json_decode(@file_get_contents($rf), true);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        $d = strtolower(trim(preg_replace('/:[0-9]+$/', '', $item['domain'] ?? '')));
                        if ($d === '') continue;
                        $clients_map[$d] = [
                            'domain'      => $d,
                            'client_name' => trim($item['client_name'] ?? ''),
                            'role'        => $item['role'] ?? 'client',
                            'webroot'     => $item['webroot'] ?? ''
                        ];
                    }
                }
            }
        }

        // 2. From /etc/tricurse/*/contact.conf
        if (is_dir('/etc/tricurse')) {
            $dirs = @glob('/etc/tricurse/*/contact.conf');
            if ($dirs) {
                foreach ($dirs as $cf) {
                    $d = strtolower(trim(preg_replace('/:[0-9]+$/', '', basename(dirname($cf)))));
                    if ($d === '') continue;
                    $client_name = ''; $server_role = 'client'; $webroot = '';
                    @include $cf;
                    if (!isset($clients_map[$d])) {
                        $clients_map[$d] = [
                            'domain'      => $d,
                            'client_name' => trim($client_name),
                            'role'        => $server_role ?: 'client',
                            'webroot'     => $webroot
                        ];
                    } elseif (empty($clients_map[$d]['client_name']) && !empty($client_name)) {
                        $clients_map[$d]['client_name'] = trim($client_name);
                    }
                }
            }
        }

        // 3. From database: main_subscriptions and users
        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT client_domain, client_name FROM main_subscriptions");
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $d = strtolower(trim(preg_replace('/:[0-9]+$/', '', $row['client_domain'] ?? '')));
                        if ($d === '') continue;
                        if (!isset($clients_map[$d])) {
                            $clients_map[$d] = [
                                'domain'      => $d,
                                'client_name' => trim($row['client_name'] ?? ''),
                                'role'        => 'client',
                                'webroot'     => ''
                            ];
                        } elseif (empty($clients_map[$d]['client_name']) && !empty($row['client_name'])) {
                            $clients_map[$d]['client_name'] = trim($row['client_name']);
                        }
                    }
                }
            } catch (Exception $e) {}

            try {
                $stmt = $pdo->query("SELECT domain, client_name FROM users WHERE domain IS NOT NULL AND domain != ''");
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $d = strtolower(trim(preg_replace('/:[0-9]+$/', '', $row['domain'] ?? '')));
                        if ($d === '') continue;
                        $cn = trim($row['client_name'] ?? '');
                        if (!isset($clients_map[$d])) {
                            $clients_map[$d] = [
                                'domain'      => $d,
                                'client_name' => $cn,
                                'role'        => 'client',
                                'webroot'     => ''
                            ];
                        } elseif (empty($clients_map[$d]['client_name']) && !empty($cn)) {
                            $clients_map[$d]['client_name'] = $cn;
                        }
                    }
                }
            } catch (Exception $e) {}
        }

        $result = [];
        foreach ($clients_map as $d => $c) {
            $name = $c['client_name'];
            if ($name === '' && function_exists('get_client_name') && $pdo) {
                $name = get_client_name($pdo, $d);
            }
            if ($name === '') {
                $name = $d;
            }
            $clean_dom = function_exists('format_address_display') ? format_address_display($d) : $d;
            $url = 'https://' . $d . '/contact';

            $result[] = [
                'domain'      => $d,
                'client_name' => $name,
                'role'        => $c['role'] ?? 'client',
                'url'         => $url,
                'display'     => $clean_dom
            ];
        }

        usort($result, function($a, $b) {
            return strcasecmp($a['client_name'], $b['client_name']);
        });

        return $result;
    }
}

if (!function_exists('get_preset_name_for_destination')) {
    /**
     * Look up the preset client name for a destination within the local server.
     * Returns the preset name if destination is local, or empty string if external/not preset.
     */
    function get_preset_name_for_destination($destination, $pdo = null) {
        $dest = trim($destination);
        if ($dest === '') return '';
        $pdo = $pdo ?: (function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null));

        if (preg_match('/^(.*?)\s*<([^>]+)>$/', $dest, $m)) {
            $maybe_name = trim($m[1]);
            $maybe_dest = trim($m[2]);
            if ($maybe_name !== '') return $maybe_name;
            $dest = $maybe_dest;
        }

        $clean = preg_replace('#^https?://#i', '', $dest);
        $clean = preg_replace('#/index\.php$#i', '', $clean);
        $clean = preg_replace('#^index\.php$#i', '', $clean);
        $clean = rtrim($clean, '/');
        $host = strtolower(trim(preg_replace('/:[0-9]+$/', '', parse_url('https://' . $clean, PHP_URL_HOST) ?: $clean)));

        $clients = get_local_server_clients($pdo);
        foreach ($clients as $c) {
            if (strcasecmp($dest, $c['client_name']) === 0) {
                return $c['client_name'];
            }
            if ($host !== '' && strcasecmp($host, $c['domain']) === 0 && !empty($c['client_name'])) {
                return $c['client_name'];
            }
            if ($clean !== '' && strcasecmp($clean, $c['display']) === 0 && !empty($c['client_name'])) {
                return $c['client_name'];
            }
        }

        if ($host !== '' && function_exists('get_client_name') && $pdo) {
            $cn = get_client_name($pdo, $host);
            if (!empty($cn)) return $cn;
        }

        return '';
    }
}

if (!function_exists('format_destination_display')) {
    /**
     * Format destination(s) for display:
     * - Uses preset names for destinations within the local server.
     * - Formats external destinations cleanly without https:// or index.php.
     */
    function format_destination_display($raw_address, $pdo = null) {
        if (empty($raw_address)) return '';
        $pdo = $pdo ?: (function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null));
        $parts = preg_split('/[;,]+/', $raw_address);
        $formatted = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            $name = get_preset_name_for_destination($p, $pdo);
            if (!empty($name)) {
                $formatted[] = $name;
            } else {
                $formatted[] = format_address_display($p);
            }
        }
        return implode('; ', $formatted);
    }
}

if (!function_exists('resolve_client_destination')) {
    /**
     * Resolve a destination input (which may be a preset name, domain, or URL)
     * to a valid endpoint URL.
     */
    function resolve_client_destination($raw_destination, $pdo = null) {
        $raw = trim($raw_destination);
        if ($raw === '') return '';
        $pdo = $pdo ?: (function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null));

        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            $raw = trim($m[1]);
        }

        $clients = get_local_server_clients($pdo);
        foreach ($clients as $c) {
            if (strcasecmp($raw, $c['client_name']) === 0) {
                return $c['url'];
            }
        }

        $clean = preg_replace('#^https?://#i', '', $raw);
        $clean = preg_replace('#/index\.php$#i', '', $clean);
        $clean = rtrim($clean, '/');
        $host = strtolower(trim(preg_replace('/:[0-9]+$/', '', parse_url('https://' . $clean, PHP_URL_HOST) ?: $clean)));

        foreach ($clients as $c) {
            if ($host !== '' && strcasecmp($host, $c['domain']) === 0) {
                return $c['url'];
            }
            if ($clean !== '' && strcasecmp($clean, $c['display']) === 0) {
                return $c['url'];
            }
        }

        $u = ltrim($raw, '/');
        if (preg_match('#^http://#i', $u)) {
            $u = preg_replace('#^http://#i', 'https://', $u);
        } elseif (!preg_match('#^https://#i', $u)) {
            $u = 'https://' . $u;
        }
        $u = preg_replace('#/index\.php$#i', '', $u);
        return $u;
    }
}

if (!function_exists('get_default_mail_destinations')) {
    function get_default_mail_destinations($domain = null) {
        $dom = $domain ?: (function_exists('get_current_domain') ? get_current_domain() : 'localhost');
        $raw = function_exists('get_setting') ? get_setting('default_mail_destinations', '', $dom) : '';
        if (!empty($raw)) {
            $decoded = @json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }
        // In new installations, default to the main contact point
        $main_point_url = get_main_contact_point_url($dom);
        return [['url' => $main_point_url, 'user' => '']];
    }
}

// Push Notification Individual Source Helpers
if (!function_exists('is_push_disabled_for_source')) {
    function is_push_disabled_for_source($pdo, $domain, $source_host, $source_url = '') {
        $pdo = $pdo ?: (function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null));
        if (!$pdo) return false;
        $dom = $domain ?: (function_exists('get_current_domain') ? get_current_domain() : '');
        $candidates = [];
        if (!empty($source_host)) {
            $h = strtolower(trim(preg_replace('/:[0-9]+$/', '', $source_host)));
            if ($h !== '') $candidates[] = $h;
        }
        if (!empty($source_url)) {
            $u = strtolower(trim($source_url));
            if ($u !== '') {
                $candidates[] = $u;
                $parsed = parse_url($u);
                if (!empty($parsed['host'])) {
                    $candidates[] = strtolower(trim($parsed['host']));
                }
            }
        }
        $candidates = array_unique($candidates);
        if (empty($candidates)) return false;

        $in_q = implode(',', array_fill(0, count($candidates), '?'));
        $sql = "SELECT count(*) FROM push_disabled_sources 
                WHERE (domain = ? OR domain = '' OR domain IS NULL) 
                  AND source_identifier IN ($in_q)";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$dom], $candidates));
            return ($stmt->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('disable_push_for_source')) {
    function disable_push_for_source($pdo, $domain, $source) {
        $pdo = $pdo ?: (function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null));
        if (!$pdo) return false;
        $dom = $domain ?: (function_exists('get_current_domain') ? get_current_domain() : '');
        $src = strtolower(trim(preg_replace('/:[0-9]+$/', '', $source)));
        if ($src === '') return false;
        try {
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO push_disabled_sources (domain, source_identifier) VALUES (?, ?)");
            return $stmt->execute([$dom, $src]);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('enable_push_for_source')) {
    function enable_push_for_source($pdo, $domain, $source) {
        $pdo = $pdo ?: (function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null));
        if (!$pdo) return false;
        $dom = $domain ?: (function_exists('get_current_domain') ? get_current_domain() : '');
        $src = strtolower(trim(preg_replace('/:[0-9]+$/', '', $source)));
        if ($src === '') return false;
        try {
            $stmt = $pdo->prepare("DELETE FROM push_disabled_sources WHERE (domain = ? OR domain = '' OR domain IS NULL) AND source_identifier = ?");
            return $stmt->execute([$dom, $src]);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('get_push_disabled_sources_for_domain')) {
    function get_push_disabled_sources_for_domain($pdo, $domain) {
        $pdo = $pdo ?: (function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null));
        if (!$pdo) return [];
        $dom = $domain ?: (function_exists('get_current_domain') ? get_current_domain() : '');
        try {
            $stmt = $pdo->prepare("SELECT * FROM push_disabled_sources WHERE domain = ? OR domain = '' OR domain IS NULL ORDER BY id DESC");
            $stmt->execute([$dom]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            return [];
        }
    }
}

// Multi-byte / UTF-8 Polyfills if ext-mbstring is not installed
if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null) {
        if (function_exists('iconv_strlen')) {
            $enc = $encoding ?: 'UTF-8';
            $len = @iconv_strlen($string, $enc);
            if ($len !== false) return $len;
        }
        return strlen($string);
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null) {
        if (function_exists('iconv_substr')) {
            $enc = $encoding ?: 'UTF-8';
            if ($length === null) {
                $sub = @iconv_substr($string, $start, iconv_strlen($string, $enc), $enc);
            } else {
                $sub = @iconv_substr($string, $start, $length, $enc);
            }
            if ($sub !== false) return $sub;
        }
        return ($length === null) ? substr($string, $start) : substr($string, $start, $length);
    }
}

if (!function_exists('get_current_domain')) {
    function get_current_domain($default = null) {
        $raw = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if ($raw !== '') {
            $host = strtolower(trim(preg_replace('/:\d+$/', '', $raw)));
            if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1') {
                return $host;
            }
        }
        if ($default !== null && $default !== '') {
            return strtolower(trim($default));
        }
        return 'addressbar.net';
    }
}

if (!function_exists('base64url_encode')) {
    function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('base64url_decode')) {
    function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

if (!function_exists('der_to_raw_sig')) {
    function der_to_raw_sig($der) {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += (ord($der[1]) & 0x7f);
        }
        $pos++;
        $lenR = ord($der[$pos++]);
        $r = substr($der, $pos, $lenR);
        $pos += $lenR;
        $pos++;
        $lenS = ord($der[$pos++]);
        $s = substr($der, $pos, $lenS);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }
}

if (!function_exists('get_or_create_vapid_keys')) {
    function get_or_create_vapid_keys($pdo = null) {
        $pdo = $pdo ?: (function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null));
        if (!$pdo) return null;
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'vapid_public_key' LIMIT 1");
            $stmt->execute();
            $pub = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'vapid_private_pem' LIMIT 1");
            $stmt->execute();
            $pem = $stmt->fetchColumn();

            if (!empty($pub) && !empty($pem)) {
                return ['publicKey' => $pub, 'privatePem' => $pem];
            }

            // Generate new EC prime256v1 keypair via OpenSSL
            $config = [
                'curve_name' => 'prime256v1',
                'private_key_type' => OPENSSL_KEYTYPE_EC
            ];
            $res = openssl_pkey_new($config);
            if (!$res) {
                return null;
            }
            openssl_pkey_export($res, $privPem);
            $details = openssl_pkey_get_details($res);
            $ec = $details['ec'] ?? null;
            if (!$ec) {
                return null;
            }

            $x = str_pad($ec['x'], 32, "\0", STR_PAD_LEFT);
            $y = str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
            $pubKeyRaw = "\x04" . $x . $y;
            $pubKeyB64 = base64url_encode($pubKeyRaw);

            $ins = $pdo->prepare("INSERT OR REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, datetime('now'))");
            $ins->execute(['vapid_public_key', $pubKeyB64]);
            $ins->execute(['vapid_private_pem', $privPem]);

            return ['publicKey' => $pubKeyB64, 'privatePem' => $privPem];
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('send_browser_push_notification')) {
    /**
     * Send Web Push notification to registered browser / PWA endpoints
     * Uses RFC 8292 VAPID over standard HTTPS (outbound port 443).
     * Issued under the base website domain name.
     */
    function send_browser_push_notification($pdo = null, $domain = null, $msg = [], $context = []) {
        $pdo = $pdo ?: (function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null));
        if (!$pdo) return false;
        $vapid = get_or_create_vapid_keys($pdo);
        if (!$vapid || empty($vapid['publicKey']) || empty($vapid['privatePem'])) {
            @file_put_contents('/tmp/addr_bar_push_notification.log', "[" . date('Y-m-d H:i:s T') . "] VAPID keys could not be generated or loaded.\n", FILE_APPEND);
            return false;
        }

        $dom = $domain ?: (function_exists('get_current_domain') ? get_current_domain() : 'addressbar.net');
        $base_domain = preg_replace('/:[0-9]+$/', '', $dom);
        $sender_host = trim($msg['sender_host'] ?? ($context['sender_host'] ?? ''));
        $subject = trim($msg['subject'] ?? 'New Message');

        $is_test = !empty($context['is_test']);

        // Determine sender location (inside local server vs outside remote)
        $is_inside = false;
        if (isset($context['is_inside'])) {
            $is_inside = (bool)$context['is_inside'];
        } elseif (!$is_test) {
            $s_ip = $context['sender_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
            if (function_exists('is_sender_in_server_group')) {
                $is_inside = is_sender_in_server_group($sender_host, $s_ip, $pdo);
            } elseif (function_exists('is_local_sender')) {
                $is_inside = is_local_sender($sender_host, $s_ip);
            } else {
                $is_inside = in_array($s_ip, ['127.0.0.1', '::1', 'localhost'], true);
            }
        }

        // Mail from an external server shouldn't include a name
        $sender_display = '';
        if ($is_inside) {
            $sender_display = trim($msg['sender_name'] ?? '');
        }
        if ($sender_display === '') {
            $clean_host = function_exists('format_address_display') ? format_address_display($sender_host) : $sender_host;
            $sender_display = $clean_host ?: 'Peer Website';
        }

        try {
            // Find candidate domains: the primary domain and all client domains subscribed to it
            $candidate_domains = [$dom];
            try {
                $sub_clients_stmt = $pdo->prepare("SELECT client_domain FROM main_subscriptions WHERE main_domain = ?");
                $sub_clients_stmt->execute([$dom]);
                $client_doms = $sub_clients_stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($client_doms)) {
                    $candidate_domains = array_unique(array_merge($candidate_domains, $client_doms));
                }
            } catch (Exception $e) {}

            // Filter candidate domains according to their push notification options
            $allowed_target_domains = [];

            foreach ($candidate_domains as $cdom) {
                if ($is_test) {
                    $allowed_target_domains[] = $cdom;
                    continue;
                }

                // 1. Master push notification enabled check
                $master_enabled = (get_setting('push_notifications_enabled', '1', $cdom) === '1');
                if (!$master_enabled) {
                    continue;
                }

                // 2. Check if this notification is from the main contact point for $cdom
                // A notification is from the main contact point if:
                // a) $cdom is a client receiving a broadcast via $dom ($cdom !== $dom)
                // b) Or $cdom is subscribed to $sender_host as its main website
                // c) Or explicitly flagged as a main broadcast
                $is_main_point_source = false;
                if (!empty($context['is_main_broadcast'])) {
                    $is_main_point_source = true;
                } elseif ($cdom !== $dom) {
                    $is_main_point_source = true;
                } else {
                    $sub_main = get_setting('subscribed_to_main_domain', '', $cdom);
                    if ($sender_host !== '' && strtolower($sender_host) === strtolower($sub_main)) {
                        $is_main_point_source = true;
                    }
                }

                if ($is_main_point_source) {
                    // For any message sent from the main contact point:
                    // All subscribers get a copy in their inbox, but NO push notification unless the main contact point included itself in the recipient list!
                    if (!empty($context['is_main_point_sender']) || $cdom !== $dom) {
                        if (empty($context['main_included_itself'])) {
                            continue;
                        }
                    }

                    $allow_main = (get_setting('push_notifications_main_broadcast_enabled', '1', $cdom) === '1');
                    if (!$allow_main) {
                        // Push notifications from main contact point disabled for this domain
                        continue;
                    }
                }

                // 3. Check inside vs outside source options
                if ($is_inside) {
                    $allow_inside = (get_setting('push_notifications_inside_enabled', '1', $cdom) === '1');
                    if (!$allow_inside) {
                        continue;
                    }
                } else {
                    // Outside source
                    $allow_outside = (get_setting('push_notifications_outside_enabled', '1', $cdom) === '1');
                    if (!$allow_outside) {
                        continue;
                    }
                }

                // 4. Check if push notifications are disabled for this individual source
                if (function_exists('is_push_disabled_for_source')) {
                    $sender_url = $msg['sender_url'] ?? ($context['sender_url'] ?? '');
                    if (is_push_disabled_for_source($pdo, $cdom, $sender_host, $sender_url)) {
                        // Push disabled for this individual source! Messages still enter inbox and show in unread count until read.
                        continue;
                    }
                }

                $allowed_target_domains[] = $cdom;
            }

            if (empty($allowed_target_domains)) {
                @file_put_contents('/tmp/addr_bar_push_notification.log', "[" . date('Y-m-d H:i:s T') . "] Domain: {$dom} | Push notifications suppressed by options (individual source, outside, or main contact point settings).\n", FILE_APPEND);
                return true;
            }

            // Fetch subscriptions for allowed target domains
            $where_parts = [];
            $params = [];

            $placeholders = implode(',', array_fill(0, count($allowed_target_domains), '?'));
            $where_parts[] = "domain IN ($placeholders)";
            $params = array_values($allowed_target_domains);

            if (in_array($dom, $allowed_target_domains)) {
                $where_parts[] = "domain = '' OR domain IS NULL";
            }

            $sql = "SELECT id, domain, endpoint, p256dh, auth FROM push_subscriptions WHERE (" . implode(' OR ', $where_parts) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $subscriptions = $stmt->fetchAll();

            if (empty($subscriptions)) {
                @file_put_contents('/tmp/addr_bar_push_notification.log', "[" . date('Y-m-d H:i:s T') . "] Domain: {$dom} | No active browser push subscriptions registered for allowed targets.\n", FILE_APPEND);
                return true;
            }

            $success_count = 0;
            $fail_count = 0;

            foreach ($subscriptions as $sub) {
                $sub_id   = $sub['id'];
                $endpoint = trim($sub['endpoint']);
                if (empty($endpoint)) continue;

                $parsed = parse_url($endpoint);
                if (empty($parsed['scheme']) || empty($parsed['host'])) continue;
                $audience = $parsed['scheme'] . '://' . $parsed['host'];

                // Create VAPID JWT (ES256)
                $jwt_header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
                $jwt_payload = base64url_encode(json_encode([
                    'aud' => $audience,
                    'exp' => time() + 86400,
                    'sub' => "https://{$base_domain}"
                ]));
                $jwt_unsigned = $jwt_header . '.' . $jwt_payload;

                $der_sig = '';
                $priv_key_res = openssl_pkey_get_private($vapid['privatePem']);
                if (!$priv_key_res) continue;
                openssl_sign($jwt_unsigned, $der_sig, $priv_key_res, OPENSSL_ALGO_SHA256);
                $raw_sig = der_to_raw_sig($der_sig);
                $jwt = $jwt_unsigned . '.' . base64url_encode($raw_sig);

                // Build Web Push HTTP request headers
                $headers = [
                    "Authorization: vapid t={$jwt}, k={$vapid['publicKey']}",
                    "TTL: 86400",
                    "Urgency: high",
                    "Content-Length: 0"
                ];

                $opts = [
                    'http' => [
                        'method'        => 'POST',
                        'header'        => implode("\r\n", $headers) . "\r\n",
                        'content'       => '',
                        'timeout'       => 8,
                        'ignore_errors' => true
                    ],
                    'ssl' => [
                        'verify_peer'      => true,
                        'verify_peer_name' => true
                    ]
                ];

                $context = stream_context_create($opts);
                $response = @file_get_contents($endpoint, false, $context);
                $http_response_header = $http_response_header ?? [];

                $status_code = 0;
                if (!empty($http_response_header[0])) {
                    preg_match('/HTTP\/[0-9\.]+\s+([0-9]+)/', $http_response_header[0], $m);
                    $status_code = isset($m[1]) ? (int)$m[1] : 0;
                }

                // 201 Created or 200/202: Success
                if ($status_code >= 200 && $status_code < 300) {
                    $success_count++;
                } else {
                    $fail_count++;
                    // 404 Not Found or 410 Gone: Browser unsubscribed or subscription expired
                    if ($status_code === 404 || $status_code === 410) {
                        $del = $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?");
                        $del->execute([$sub_id]);
                    }
                }
            }

            $log = "[" . date('Y-m-d H:i:s T') . "] Push Dispatch | Base Domain: {$base_domain} | From: {$sender_display} | Subject: {$subject} | Delivered: {$success_count} | Expired/Removed: {$fail_count}\n";
            @file_put_contents('/tmp/addr_bar_push_notification.log', $log, FILE_APPEND);
            @chmod('/tmp/addr_bar_push_notification.log', 0600);

            return ($success_count > 0);
        } catch (Exception $e) {
            @file_put_contents('/tmp/addr_bar_push_notification.log', "[" . date('Y-m-d H:i:s T') . "] Push Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return false;
        }
    }
}



if (!function_exists('get_or_init_domain_user')) {
    function get_or_init_domain_user($pdo, $domain = null) {
        if (!$pdo) return null;
        $dom = $domain ?: get_current_domain();
        try {
            // 1. Look for user specifically tied to this domain
            $stmt = $pdo->prepare("SELECT * FROM users WHERE domain = ? LIMIT 1");
            $stmt->execute([$dom]);
            $user = $stmt->fetch();
            if ($user) return $user;

            // 2. Look for legacy user with NULL or empty domain
            $stmt = $pdo->prepare("SELECT * FROM users WHERE (domain IS NULL OR domain = '') ORDER BY (username = 'admin') DESC, id ASC LIMIT 1");
            $stmt->execute();
            $fallback = $stmt->fetch();
            if ($fallback) {
                $upd = $pdo->prepare("UPDATE users SET domain = ? WHERE id = ?");
                $upd->execute([$dom, $fallback['id']]);
                $fallback['domain'] = $dom;
                return $fallback;
            }

            // 3. Auto-initialize single user for this domain with unguessable random password
            $default_user = 'admin';
            $default_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
            $ins = $pdo->prepare("INSERT INTO users (domain, username, password) VALUES (?, ?, ?)");
            $ins->execute([$dom, $default_user, $default_hash]);
            $new_id = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$new_id]);
            return $stmt->fetch() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('get_single_domain_user')) {
    function get_single_domain_user($domain = null) {
        global $pdo;
        return get_or_init_domain_user($pdo, $domain);
    }
}

if (!function_exists('get_server_group_domains')) {
    /**
     * Returns an array of normalized domain names that belong to the local server group.
     * Includes main contact point(s), subscribed clients, registered user domains, and local installations.
     */
    function get_server_group_domains($pdo = null) {
        if (!$pdo) {
            $pdo = function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null);
        }
        $domains = [];

        // 1. From main_subscriptions (both client_domain and main_domain)
        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT client_domain FROM main_subscriptions UNION SELECT main_domain FROM main_subscriptions");
                if ($stmt) {
                    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    foreach ($rows as $r) {
                        if ($r) $domains[] = $r;
                    }
                }
            } catch (Exception $e) {}

            // 2. From users table (domains registered on this server)
            try {
                $stmt = $pdo->query("SELECT DISTINCT domain FROM users WHERE domain IS NOT NULL AND domain != ''");
                if ($stmt) {
                    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    foreach ($rows as $r) {
                        if ($r) $domains[] = $r;
                    }
                }
            } catch (Exception $e) {}

            // 3. From site_settings table (subscribed_to_main_domain or main domains)
            try {
                $stmt = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key LIKE '%subscribed_to_main_domain%' OR (setting_key LIKE '%server_role%' AND setting_value = 'main')");
                if ($stmt) {
                    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    foreach ($rows as $r) {
                        if ($r) $domains[] = $r;
                    }
                }
            } catch (Exception $e) {}
        }

        // 4. Subscribed main domain from setting helper if available
        if (function_exists('get_setting')) {
            $main_sub = get_setting('subscribed_to_main_domain', '');
            if (!empty($main_sub)) $domains[] = $main_sub;
        }

        // 5. Current host/domain
        if (function_exists('get_current_domain')) {
            $cur = get_current_domain();
            if (!empty($cur)) $domains[] = $cur;
        }
        if (!empty($_SERVER['HTTP_HOST'])) {
            $domains[] = $_SERVER['HTTP_HOST'];
        }

        // 6. From /etc/tricurse/installations.json if present
        if (file_exists('/etc/tricurse/installations.json')) {
            $installs = @json_decode(@file_get_contents('/etc/tricurse/installations.json'), true);
            if (is_array($installs)) {
                foreach ($installs as $inst) {
                    if (!empty($inst['domain'])) $domains[] = $inst['domain'];
                }
            }
        }

        // Normalize all domains (lowercase, strip port, strip scheme/path)
        $clean_domains = [];
        foreach ($domains as $d) {
            $d = trim($d);
            if ($d === '') continue;
            $parsed = parse_url($d, PHP_URL_HOST);
            $host = $parsed ?: $d;
            $host = strtolower(trim(preg_replace('/:[0-9]+$/', '', $host)));
            $host = ltrim($host, '*.');
            if ($host !== '' && !in_array($host, $clean_domains, true)) {
                $clean_domains[] = $host;
            }
        }

        return $clean_domains;
    }
}

if (!function_exists('is_sender_in_server_group')) {
    /**
     * Determines whether a sender host/IP is part of the server group.
     */
    function is_sender_in_server_group($sender_host, $sender_ip = '', $pdo = null) {
        $host = strtolower(trim(preg_replace('/:[0-9]+$/', '', parse_url($sender_host, PHP_URL_HOST) ?: $sender_host)));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        if (function_exists('is_local_sender') && is_local_sender($host, $sender_ip)) {
            return true;
        }

        $group_domains = get_server_group_domains($pdo);
        if (in_array($host, $group_domains, true)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('is_local_sender')) {
    /**
     * Checks /etc/hosts for local loopback / server mappings, local IPs, and /etc/tricurse installations.
     */
    function is_local_sender($sender_host, $sender_ip = '') {
        $sender_host = strtolower(trim($sender_host));
        if ($sender_host === '' || $sender_host === 'localhost' || $sender_host === '127.0.0.1' || $sender_host === '::1') {
            return true;
        }

        $sender_ip = trim($sender_ip);
        $server_ip = $_SERVER['SERVER_ADDR'] ?? '';

        // 1. Check if connecting from local loopback or server self-IP
        if ($sender_ip === '127.0.0.1' || $sender_ip === '::1' || ($server_ip !== '' && $sender_ip === $server_ip)) {
            return true;
        }

        // 2. Check /etc/hosts to see if sender_host resolves to loopback (127.*, ::1) or server IP
        if (is_readable('/etc/hosts')) {
            $lines = @file('/etc/hosts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines) {
                foreach ($lines as $line) {
                    $line = trim(preg_replace('/#.*$/', '', $line));
                    if ($line === '') continue;
                    $parts = preg_split('/\s+/', $line);
                    if (count($parts) >= 2) {
                        $ip = $parts[0];
                        $is_loopback_ip = (
                            $ip === '127.0.0.1' ||
                            strpos($ip, '127.') === 0 ||
                            $ip === '::1' ||
                            ($server_ip !== '' && $ip === $server_ip)
                        );
                        if ($is_loopback_ip) {
                            for ($i = 1; $i < count($parts); $i++) {
                                if (strtolower($parts[$i]) === $sender_host) {
                                    return true;
                                }
                            }
                        }
                    }
                }
            }
        }

        // 3. Check gethostbyname resolution
        $resolved = @gethostbyname($sender_host);
        if ($resolved && ($resolved === '127.0.0.1' || strpos($resolved, '127.') === 0 || $resolved === '::1' || ($server_ip !== '' && $resolved === $server_ip))) {
            return true;
        }

        // 4. Check if /etc/tricurse has a registered configuration for this domain
        if (file_exists("/etc/tricurse/{$sender_host}/contact.conf") || file_exists("/etc/tricurse/{$sender_host}.conf")) {
            return true;
        }

        // 5. Check if /etc/tricurse/installations.json lists this domain
        if (file_exists('/etc/tricurse/installations.json')) {
            $installs = @json_decode(@file_get_contents('/etc/tricurse/installations.json'), true);
            if (is_array($installs)) {
                foreach ($installs as $inst) {
                    if (!empty($inst['domain']) && strtolower($inst['domain']) === $sender_host) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}

if (!function_exists('is_inner_message')) {
    /**
     * Determines whether a message originates from or is shared within the inner server group.
     * Inner messages include:
     *  - Messages marked as server group letters (intra-server group letters)
     *  - Broadcasts sent from the main contact point / transmitter to subscribers
     *  - Messages whose sender host, sender URL, or target belongs to the local server group
     *  - Messages originating from local loopback (localhost, 127.*, ::1) or server IP
     *
     * @param array $msg Message record
     * @param PDO|null $pdo Database connection
     * @return bool True if the message is an inner message
     */
    function is_inner_message($msg, $pdo = null) {
        if (!is_array($msg)) {
            return false;
        }

        // 1. Explicitly flagged group letters or transmitter broadcasts
        if (!empty($msg['is_server_group_letter']) || !empty($msg['is_broadcast_from_main'])) {
            return true;
        }

        // 2. Sent copies
        if (!empty($msg['is_sent_copy'])) {
            return true;
        }

        $s_host = trim($msg['sender_host'] ?? '');
        $s_url  = trim($msg['sender_url'] ?? '');
        $s_ip   = trim($msg['sender_ip'] ?? '');

        // 3. Sender in server group check
        if (function_exists('is_sender_in_server_group')) {
            if ($s_host !== '' && is_sender_in_server_group($s_host, $s_ip, $pdo)) {
                return true;
            }
            if ($s_url !== '' && is_sender_in_server_group($s_url, $s_ip, $pdo)) {
                return true;
            }
        }

        // 4. Local sender / loopback check
        if (function_exists('is_local_sender')) {
            if ($s_host !== '' && is_local_sender($s_host, $s_ip)) {
                return true;
            }
            if ($s_url !== '' && is_local_sender($s_url, $s_ip)) {
                return true;
            }
            if ($s_ip !== '' && is_local_sender('', $s_ip)) {
                return true;
            }
        }

        // 5. Match against server group domains
        if (function_exists('get_server_group_domains')) {
            $group_domains = get_server_group_domains($pdo);
            $clean_host = strtolower(trim(preg_replace('/:[0-9]+$/', '', parse_url($s_host, PHP_URL_HOST) ?: $s_host)));
            if ($clean_host !== '' && in_array($clean_host, $group_domains, true)) {
                return true;
            }
            $clean_url_host = strtolower(trim(preg_replace('/:[0-9]+$/', '', parse_url($s_url, PHP_URL_HOST) ?: $s_url)));
            if ($clean_url_host !== '' && in_array($clean_url_host, $group_domains, true)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('get_inbox_messages_for_domain')) {
    /**
     * Retrieve inbox messages accessible to $domain.
     * Includes:
     *  1. Direct messages to this domain (and own sent copies).
     *  2. Broadcast messages sent to transmitter / main contact point.
     *  3. All letters within the server group (intra-server group letters received by all clients).
     * Excludes:
     *  - Messages deleted by this domain (message_deletions).
     *  - Messages received by an individual peer client from another (outside) website.
     * Preserves independent read status (message_reads) and deduplicates.
     */
    function get_inbox_messages_for_domain($pdo, $domain, $limit = 50) {
        if (!$pdo) return [];
        $dom = $domain ?: get_current_domain();
        $base_host = strtolower(trim(preg_replace('/:[0-9]+$/', '', $dom)));

        // 1. Gather any main websites this domain is subscribed to (transmitters)
        $sub_mains = [];
        try {
            $stmt = $pdo->prepare("SELECT main_domain FROM main_subscriptions WHERE client_domain = ?");
            $stmt->execute([$dom]);
            $sub_mains = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Exception $e) {}

        // Also check site_settings for configured subscribed main domain
        $setting_sub = function_exists('get_setting') ? get_setting('subscribed_to_main_domain', '', $dom) : '';
        if (!empty($setting_sub) && !in_array($setting_sub, $sub_mains)) {
            $sub_mains[] = $setting_sub;
        }

        $clean_sub_mains = [];
        foreach ($sub_mains as $sm) {
            $sm_clean = strtolower(trim(preg_replace('/:[0-9]+$/', '', parse_url($sm, PHP_URL_HOST) ?: $sm)));
            if ($sm_clean !== '' && !in_array($sm_clean, $clean_sub_mains, true)) {
                $clean_sub_mains[] = $sm_clean;
            }
        }

        // 2. Gather server group domains
        $group_domains = get_server_group_domains($pdo);
        if (!in_array($base_host, $group_domains, true)) {
            $group_domains[] = $base_host;
        }

        // 3. Build SQL query
        $where_clauses = [];
        $params = [];

        // Clause 1: Directly addressed to this domain, or sent copy for this domain
        $where_clauses[] = "(
            target_domain = ? 
            OR target_domain = ? 
            OR (is_sent_copy = 1 AND (target_domain = ? OR target_domain = ? OR sender_host = ? OR sender_host = ?))
            OR ((target_domain = '' OR target_domain IS NULL) AND (is_sent_copy = 0 OR sender_host = ? OR sender_host = ?))
        )";
        $params[] = $dom;
        $params[] = $base_host;
        $params[] = $dom;
        $params[] = $base_host;
        $params[] = $dom;
        $params[] = $base_host;
        $params[] = $dom;
        $params[] = $base_host;

        // Clause 2: Mail sent to transmitter / main contact point (broadcasts to subscribers in group)
        if (!empty($clean_sub_mains)) {
            $sub_items = array_merge($clean_sub_mains, $sub_mains);
            $sub_items = array_unique($sub_items);
            $sub_placeholders = implode(',', array_fill(0, count($sub_items), '?'));
            $where_clauses[] = "(target_domain IN ($sub_placeholders) AND is_sent_copy = 0)";
            foreach ($sub_items as $si) {
                $params[] = $si;
            }
        }

        // Clause 3: Intra-server group letter:
        // Any letter within the server group (sender is in server group AND target is in server group or group broadcast)
        // is received in the inbox of all clients in the group!
        if (!empty($group_domains)) {
            $grp_placeholders = implode(',', array_fill(0, count($group_domains), '?'));
            $where_clauses[] = "(
                sender_host IN ($grp_placeholders) 
                AND (target_domain IN ($grp_placeholders) OR target_domain = '' OR target_domain IS NULL)
                AND is_sent_copy = 0
            )";
            foreach ($group_domains as $gd) {
                $params[] = $gd;
            }
            foreach ($group_domains as $gd) {
                $params[] = $gd;
            }
        }

        $where_sql = '(' . implode(' OR ', $where_clauses) . ')';
        $params[] = $dom;
        $params[] = $base_host;
        $params[] = $dom;
        $params[] = $base_host;

        $fetch_limit = max(50, (int)$limit * 3);
        $sql = "
            SELECT * FROM incoming_messages 
            WHERE $where_sql 
              AND id NOT IN (
                  SELECT message_id FROM message_archives WHERE domain = ? OR domain = ?
                  UNION
                  SELECT message_id FROM message_deletions WHERE domain = ? OR domain = ?
              )
            ORDER BY id DESC 
            LIMIT " . (int)$fetch_limit;

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // 4. Determine read status & deduplicate messages for this domain
            $read_stmt = $pdo->prepare("SELECT count(*) FROM message_reads WHERE message_id = ? AND (domain = ? OR domain = ?)");
            $is_current_main = (function_exists('get_setting') && get_setting('server_role', 'main', $dom) === 'main');

            $deduped_rows = [];
            $seen_signatures = [];

            foreach ($rows as $r) {
                $t_clean = strtolower(trim(preg_replace('/:[0-9]+$/', '', parse_url($r['target_domain'] ?: '', PHP_URL_HOST) ?: ($r['target_domain'] ?? ''))));
                $s_clean = strtolower(trim(preg_replace('/:[0-9]+$/', '', parse_url($r['sender_url'] ?: '', PHP_URL_HOST) ?: ($r['sender_host'] ?? ''))));

                $is_direct = ($t_clean === $base_host || $r['target_domain'] === $dom);
                $is_transmitter_broadcast = (!$is_direct && in_array($t_clean, $clean_sub_mains, true));
                $is_server_group_letter = (!$is_direct && !$is_transmitter_broadcast && in_array($s_clean, $group_domains, true) && (in_array($t_clean, $group_domains, true) || $t_clean === ''));

                $r['is_broadcast_from_main'] = $is_transmitter_broadcast;
                $r['is_server_group_letter'] = $is_server_group_letter;

                $read_stmt->execute([$r['id'], $dom, $base_host]);
                $has_read_record = ($read_stmt->fetchColumn() > 0);

                if ($is_direct) {
                    $r['is_read'] = (!empty($r['is_read']) || $has_read_record) ? 1 : 0;
                } else {
                    // For server group letters and transmitter broadcasts, read status is tracked independently per client
                    $r['is_read'] = $has_read_record ? 1 : 0;
                }

                // If viewing domain was the sender of an intra-server group letter or broadcast,
                // their sent copy (is_sent_copy = 1) is already shown in outbox/sent copy.
                // Do not display their own outgoing letter as an incoming unread message to themselves.
                if (empty($r['is_sent_copy']) && $s_clean === $base_host && !$is_direct) {
                    continue;
                }

                // Deduplication Signature (sender, subject, body within same 24-hour window)
                $clean_sender = strtolower(trim(function_exists('format_address_display') ? format_address_display($r['sender_url'] ?: $r['sender_host']) : ($r['sender_url'] ?: $r['sender_host'])));
                $clean_subj = strtolower(trim(preg_replace('/^re:\s*/i', '', $r['subject'] ?? '')));
                $clean_body = strtolower(trim($r['message_body'] ?? ''));
                $time_window = !empty($r['received_at']) ? date('Y-m-d', strtotime($r['received_at'])) : date('Y-m-d');
                $sig = md5($clean_sender . '||' . $clean_subj . '||' . $clean_body . '||' . $time_window);

                if (isset($seen_signatures[$sig])) {
                    // Duplicate found!
                    $idx = $seen_signatures[$sig];
                    if (!empty($r['is_read'])) {
                        $deduped_rows[$idx]['is_read'] = 1;
                    }
                    // Prefer direct delivery over group broadcast
                    if ((!empty($deduped_rows[$idx]['is_broadcast_from_main']) || !empty($deduped_rows[$idx]['is_server_group_letter'])) && $is_direct) {
                        $r['is_read'] = $deduped_rows[$idx]['is_read'];
                        $deduped_rows[$idx] = $r;
                    }
                    continue;
                }

                $seen_signatures[$sig] = count($deduped_rows);
                $deduped_rows[] = $r;
                if (count($deduped_rows) >= $limit) {
                    break;
                }
            }

            return $deduped_rows;
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('archive_message_for_domain')) {
    /**
     * Archive a message for a domain (moves from inbox to archive list).
     */
    function archive_message_for_domain($pdo, $message_id, $domain) {
        if (!$pdo || $message_id <= 0) return false;
        $dom = $domain ?: (function_exists('get_current_domain') ? get_current_domain() : 'localhost');
        $base = strtolower(trim(preg_replace('/:[0-9]+$/', '', $dom)));
        try {
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO message_archives (message_id, domain, archived_at) VALUES (?, ?, datetime('now'))");
            $stmt->execute([$message_id, $dom]);
            if ($base !== '' && $base !== $dom) {
                $stmt->execute([$message_id, $base]);
            }
            $stmt2 = $pdo->prepare("INSERT OR IGNORE INTO message_deletions (message_id, domain, deleted_at) VALUES (?, ?, datetime('now'))");
            $stmt2->execute([$message_id, $dom]);
            if ($base !== '' && $base !== $dom) {
                $stmt2->execute([$message_id, $base]);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('unarchive_message_for_domain')) {
    /**
     * Unarchive / restore a message back to the domain inbox.
     */
    function unarchive_message_for_domain($pdo, $message_id, $domain) {
        if (!$pdo || $message_id <= 0) return false;
        $dom = $domain ?: (function_exists('get_current_domain') ? get_current_domain() : 'localhost');
        $base = strtolower(trim(preg_replace('/:[0-9]+$/', '', $dom)));
        try {
            $stmt = $pdo->prepare("DELETE FROM message_archives WHERE message_id = ? AND (domain = ? OR domain = ?)");
            $stmt->execute([$message_id, $dom, $base]);
            $stmt2 = $pdo->prepare("DELETE FROM message_deletions WHERE message_id = ? AND (domain = ? OR domain = ?)");
            $stmt2->execute([$message_id, $dom, $base]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('count_archived_messages_for_domain')) {
    /**
     * Count total archived messages for a domain.
     */
    function count_archived_messages_for_domain($pdo, $domain) {
        if (!$pdo) return 0;
        $dom = $domain ?: (function_exists('get_current_domain') ? get_current_domain() : 'localhost');
        $base = strtolower(trim(preg_replace('/:[0-9]+$/', '', $dom)));
        try {
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT ma.message_id) FROM message_archives ma JOIN incoming_messages im ON ma.message_id = im.id WHERE ma.domain = ? OR ma.domain = ?");
            $stmt->execute([$dom, $base]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('get_archive_periods_for_domain')) {
    /**
     * Retrieve list of available archive periods (year, month, count) for a domain.
     */
    function get_archive_periods_for_domain($pdo, $domain) {
        if (!$pdo) return [];
        $dom = $domain ?: (function_exists('get_current_domain') ? get_current_domain() : 'localhost');
        $base = strtolower(trim(preg_replace('/:[0-9]+$/', '', $dom)));
        $periods = [];
        try {
            $sql = "
                SELECT 
                    strftime('%Y', COALESCE(im.received_at, ma.archived_at, 'now')) AS yr,
                    strftime('%m', COALESCE(im.received_at, ma.archived_at, 'now')) AS mo,
                    COUNT(DISTINCT im.id) AS cnt
                FROM message_archives ma
                JOIN incoming_messages im ON ma.message_id = im.id
                WHERE (ma.domain = ? OR ma.domain = ?)
                GROUP BY yr, mo
                ORDER BY yr DESC, mo DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$dom, $base]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $yr = $row['yr'];
                $mo = $row['mo'];
                $m_num = (int)$mo;
                $date_obj = DateTime::createFromFormat('!m', $m_num);
                $month_name = $date_obj ? $date_obj->format('F') : ('Month ' . $m_num);
                $periods[] = [
                    'year'       => $yr,
                    'month'      => $mo,
                    'month_name' => $month_name,
                    'label'      => $month_name . ' ' . $yr,
                    'count'      => (int)$row['cnt']
                ];
            }
        } catch (Exception $e) {}
        return $periods;
    }
}

if (!function_exists('get_archived_messages_for_domain')) {
    /**
     * Retrieve archived messages for a domain filtered by month and year.
     */
    function get_archived_messages_for_domain($pdo, $domain, $year = null, $month = null, $limit = 200) {
        if (!$pdo) return [];
        $dom = $domain ?: (function_exists('get_current_domain') ? get_current_domain() : 'localhost');
        $base = strtolower(trim(preg_replace('/:[0-9]+$/', '', $dom)));

        $where = "(ma.domain = ? OR ma.domain = ?)";
        $params = [$dom, $base];

        if (!empty($year) && $year !== 'all') {
            $where .= " AND strftime('%Y', COALESCE(im.received_at, ma.archived_at, 'now')) = ?";
            $params[] = sprintf('%04d', (int)$year);
        }
        if (!empty($month) && $month !== 'all') {
            $where .= " AND strftime('%m', COALESCE(im.received_at, ma.archived_at, 'now')) = ?";
            $params[] = sprintf('%02d', (int)$month);
        }

        $sql = "
            SELECT im.*, ma.archived_at
            FROM message_archives ma
            JOIN incoming_messages im ON ma.message_id = im.id
            WHERE $where
            ORDER BY im.id DESC
            LIMIT " . (int)$limit;

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $deduped = [];
            $seen_ids = [];
            foreach ($rows as $r) {
                if (isset($seen_ids[$r['id']])) continue;
                $seen_ids[$r['id']] = true;
                $deduped[] = $r;
            }
            return $deduped;
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('subscribe_client_to_main')) {
    function subscribe_client_to_main($pdo, $client_domain, $main_domain, $client_url = '', $is_local = 1, $client_name = '') {
        if (!$pdo || empty($client_domain) || empty($main_domain)) return false;
        try {
            $cols = array_column($pdo->query("PRAGMA table_info(main_subscriptions)")->fetchAll(), 'name');
            if (!in_array('client_name', $cols)) {
                $pdo->exec("ALTER TABLE main_subscriptions ADD COLUMN client_name TEXT DEFAULT ''");
            }
            if ($client_name !== '') {
                $stmt = $pdo->prepare("INSERT OR REPLACE INTO main_subscriptions (client_domain, main_domain, client_url, is_local, client_name, subscribed_at) VALUES (?, ?, ?, ?, ?, datetime('now'))");
                return $stmt->execute([$client_domain, $main_domain, $client_url, $is_local ? 1 : 0, $client_name]);
            } else {
                $stmt = $pdo->prepare("INSERT OR REPLACE INTO main_subscriptions (client_domain, main_domain, client_url, is_local, subscribed_at) VALUES (?, ?, ?, ?, datetime('now'))");
                return $stmt->execute([$client_domain, $main_domain, $client_url, $is_local ? 1 : 0]);
            }
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('unsubscribe_client_from_main')) {
    function unsubscribe_client_from_main($pdo, $client_domain, $main_domain) {
        if (!$pdo || empty($client_domain) || empty($main_domain)) return false;
        try {
            $stmt = $pdo->prepare("DELETE FROM main_subscriptions WHERE client_domain = ? AND main_domain = ?");
            return $stmt->execute([$client_domain, $main_domain]);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('get_client_name')) {
    /**
     * Retrieve the configured name for a client domain.
     */
    function get_client_name($pdo = null, $domain = null) {
        $dom = $domain ?: (function_exists('get_current_domain') ? get_current_domain() : 'localhost');
        $base_dom = strtolower(trim(preg_replace('/:[0-9]+$/', '', $dom)));
        if (!$pdo) {
            $pdo = function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null);
        }

        // 1. Check site_settings specifically scoped to this domain
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? OR setting_key = ?");
                $stmt->execute([$dom . '::client_name', $base_dom . '::client_name']);
                $val = $stmt->fetchColumn();
                if (!empty($val)) return $val;
            } catch (Exception $e) {}
        }

        // 2. Check users table
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT client_name FROM users WHERE (domain = ? OR domain = ?) AND client_name IS NOT NULL AND client_name != '' LIMIT 1");
                $stmt->execute([$dom, $base_dom]);
                $val = $stmt->fetchColumn();
                if (!empty($val)) return $val;
            } catch (Exception $e) {}

            // 3. Check main_subscriptions table
            try {
                $stmt = $pdo->prepare("SELECT client_name FROM main_subscriptions WHERE (client_domain = ? OR client_domain = ?) AND client_name IS NOT NULL AND client_name != '' LIMIT 1");
                $stmt->execute([$dom, $base_dom]);
                $val = $stmt->fetchColumn();
                if (!empty($val)) return $val;
            } catch (Exception $e) {}
        }

        // 4. Check /etc/tricurse/<domain>/contact.conf
        $confs = ["/etc/tricurse/{$dom}/contact.conf", "/etc/tricurse/{$base_dom}/contact.conf"];
        foreach ($confs as $conf) {
            if (file_exists($conf)) {
                $client_name = '';
                @include $conf;
                if (!empty($client_name)) return $client_name;
            }
        }

        // 5. Check installations.json
        $reg_files = ['/etc/tricurse/installations.json', getenv('HOME') . '/.local/share/tricurse/installations.json'];
        foreach ($reg_files as $rf) {
            if (file_exists($rf)) {
                $data = @json_decode(@file_get_contents($rf), true);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        $idom = strtolower(trim(preg_replace('/:[0-9]+$/', '', $item['domain'] ?? '')));
                        if (($idom === $dom || $idom === $base_dom) && !empty($item['client_name'])) {
                            return $item['client_name'];
                        }
                    }
                }
            }
        }

        return '';
    }
}

if (!function_exists('set_client_name')) {
    /**
     * Save the name for a client domain across site_settings, users, main_subscriptions, contact.conf, and installations.json.
     */
    function set_client_name($pdo, $domain, $client_name) {
        $dom = trim($domain);
        $base_dom = strtolower(trim(preg_replace('/:[0-9]+$/', '', $dom)));
        $name = trim($client_name);
        if ($dom === '' || $name === '') return false;

        // 1. Save in site_settings
        if (function_exists('set_setting')) {
            set_setting('client_name', $name, $dom);
            if ($base_dom !== $dom) {
                set_setting('client_name', $name, $base_dom);
            }
        }

        // 2. Save in users table
        if ($pdo) {
            try {
                $user_cols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(), 'name');
                if (!in_array('client_name', $user_cols)) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN client_name TEXT DEFAULT ''");
                }
                $pdo->prepare("UPDATE users SET client_name = ? WHERE domain = ? OR domain = ?")->execute([$name, $dom, $base_dom]);
            } catch (Exception $e) {}

            // 3. Save in main_subscriptions
            try {
                $sub_cols = array_column($pdo->query("PRAGMA table_info(main_subscriptions)")->fetchAll(), 'name');
                if (!in_array('client_name', $sub_cols)) {
                    $pdo->exec("ALTER TABLE main_subscriptions ADD COLUMN client_name TEXT DEFAULT ''");
                }
                $pdo->prepare("UPDATE main_subscriptions SET client_name = ? WHERE client_domain = ? OR client_domain = ?")->execute([$name, $dom, $base_dom]);
            } catch (Exception $e) {}
        }

        // 4. Update /etc/tricurse/<domain>/contact.conf
        $confs = ["/etc/tricurse/{$dom}/contact.conf", "/etc/tricurse/{$base_dom}/contact.conf"];
        foreach ($confs as $conf) {
            if (file_exists($conf) && is_writable($conf)) {
                $content = file_get_contents($conf);
                if (strpos($content, '$client_name') !== false) {
                    $content = preg_replace('/\$client_name\s*=\s*.*?;/', '$client_name = ' . var_export($name, true) . ';', $content);
                } else {
                    $content = rtrim($content) . "\n\$client_name = " . var_export($name, true) . ";\n";
                }
                @file_put_contents($conf, $content);
                $flat = dirname($conf) . ".conf";
                if (file_exists($flat) && is_writable($flat)) {
                    @copy($conf, $flat);
                }
            }
        }

        // 5. Update installations.json
        $reg_files = ['/etc/tricurse/installations.json', getenv('HOME') . '/.local/share/tricurse/installations.json'];
        foreach ($reg_files as $rf) {
            if (file_exists($rf) && is_writable($rf)) {
                $data = @json_decode(@file_get_contents($rf), true);
                if (is_array($data)) {
                    $changed = false;
                    foreach ($data as &$item) {
                        $idom = strtolower(trim(preg_replace('/:[0-9]+$/', '', $item['domain'] ?? '')));
                        if ($idom === $dom || $idom === $base_dom) {
                            $item['client_name'] = $name;
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        @file_put_contents($rf, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                }
            }
        }

        return true;
    }
}
