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
    $db_file = '/var/lib/tricurse/contact/contact.sqlite';
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
    @mkdir($db_dir, 0777, true);
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
    if (!in_array("reset_token", $user_cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN reset_token TEXT DEFAULT NULL");
    }
    if (!in_array("reset_expires_at", $user_cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN reset_expires_at TEXT DEFAULT NULL");
    }

    $la_cols_raw = $pdo->query("PRAGMA table_info(login_attempts)")->fetchAll();
    $la_cols = array_column($la_cols_raw, 'name');
    if (!in_array("domain", $la_cols)) {
        $pdo->exec("ALTER TABLE login_attempts ADD COLUMN domain TEXT DEFAULT NULL");
    }
} catch (Exception $e) {
    // Non-fatal if tables already exist
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
    function send_browser_push_notification($pdo = null, $domain = null, $msg = []) {
        $pdo = $pdo ?: (function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null));
        if (!$pdo) return false;
        $vapid = get_or_create_vapid_keys($pdo);
        if (!$vapid || empty($vapid['publicKey']) || empty($vapid['privatePem'])) {
            @file_put_contents('/tmp/addr_bar_push_notification.log', "[" . date('Y-m-d H:i:s T') . "] VAPID keys could not be generated or loaded.\n", FILE_APPEND);
            return false;
        }

        $dom = $domain ?: get_current_domain();
        $base_domain = preg_replace('/:[0-9]+$/', '', $dom);
        $sender_display = trim($msg['sender_name'] ?? '');
        $sender_host = trim($msg['sender_host'] ?? '');
        if ($sender_display === '') $sender_display = $sender_host ?: 'Peer Website';
        $subject = trim($msg['subject'] ?? 'New Message');

        try {
            // Find all push subscriptions for this domain AND any client domains subscribed to it
            $target_domains = [$dom];
            try {
                $sub_clients_stmt = $pdo->prepare("SELECT client_domain FROM main_subscriptions WHERE main_domain = ?");
                $sub_clients_stmt->execute([$dom]);
                $client_doms = $sub_clients_stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($client_doms)) {
                    $target_domains = array_unique(array_merge($target_domains, $client_doms));
                }
            } catch (Exception $e) {}

            $in_placeholders = implode(',', array_fill(0, count($target_domains), '?'));
            $stmt = $pdo->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE domain IN ($in_placeholders) OR domain = '' OR domain IS NULL");
            $stmt->execute($target_domains);
            $subscriptions = $stmt->fetchAll();

            if (empty($subscriptions)) {
                @file_put_contents('/tmp/addr_bar_push_notification.log', "[" . date('Y-m-d H:i:s T') . "] Domain: {$dom} | No active browser push subscriptions registered.\n", FILE_APPEND);
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
            $stmt = $pdo->prepare("SELECT * FROM users WHERE domain IS NULL OR domain = '' ORDER BY (username = 'admin') DESC, id ASC LIMIT 1");
            $stmt->execute();
            $fallback = $stmt->fetch();
            if ($fallback) {
                $upd = $pdo->prepare("UPDATE users SET domain = ? WHERE id = ?");
                $upd->execute([$dom, $fallback['id']]);
                $fallback['domain'] = $dom;
                return $fallback;
            }

            // 2b. If exactly one user exists in the users table, bind it to this domain!
            $stmt = $pdo->query("SELECT * FROM users ORDER BY id ASC LIMIT 2");
            $all_users = $stmt ? $stmt->fetchAll() : [];
            if (count($all_users) === 1) {
                $single = $all_users[0];
                $upd = $pdo->prepare("UPDATE users SET domain = ? WHERE id = ?");
                $upd->execute([$dom, $single['id']]);
                $single['domain'] = $dom;
                return $single;
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

if (!function_exists('get_inbox_messages_for_domain')) {
    /**
     * Retrieve inbox messages accessible to $domain.
     * Includes direct messages and messages sent to main websites this client is subscribed to.
     * Excludes messages deleted by this domain.
     * Only one copy of each broadcast message is stored in the database!
     */
    function get_inbox_messages_for_domain($pdo, $domain, $limit = 50) {
        if (!$pdo) return [];
        $dom = $domain ?: get_current_domain();

        // 1. Gather any main websites this domain is subscribed to
        $sub_mains = [];
        try {
            $stmt = $pdo->prepare("SELECT main_domain FROM main_subscriptions WHERE client_domain = ?");
            $stmt->execute([$dom]);
            $sub_mains = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Exception $e) {}

        // Also check site_settings for configured subscribed main domain
        $setting_sub = function_exists('get_setting') ? get_setting('subscribed_to_main_domain', '') : '';
        if (!empty($setting_sub) && !in_array($setting_sub, $sub_mains)) {
            $sub_mains[] = $setting_sub;
        }

        // 2. Build SQL query
        $params = [$dom];
        $where_clauses = ["(target_domain = ? OR target_domain = '' OR target_domain IS NULL)"];

        if (!empty($sub_mains)) {
            $sub_placeholders = implode(',', array_fill(0, count($sub_mains), '?'));
            $where_clauses[] = "(target_domain IN ($sub_placeholders) AND is_sent_copy = 0)";
            $params = array_merge($params, $sub_mains);
        }

        $where_sql = '(' . implode(' OR ', $where_clauses) . ')';
        $params[] = $dom; // For message_deletions subquery

        $sql = "
            SELECT * FROM incoming_messages 
            WHERE $where_sql 
              AND id NOT IN (SELECT message_id FROM message_deletions WHERE domain = ?)
            ORDER BY id DESC 
            LIMIT " . (int)$limit;

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // 3. Determine read status for this domain (direct vs broadcast)
            $read_stmt = $pdo->prepare("SELECT count(*) FROM message_reads WHERE message_id = ? AND domain = ?");

            foreach ($rows as &$r) {
                $is_broadcast = (!empty($r['target_domain']) && $r['target_domain'] !== $dom);
                $r['is_broadcast_from_main'] = $is_broadcast;

                $read_stmt->execute([$r['id'], $dom]);
                $has_read_record = ($read_stmt->fetchColumn() > 0);

                if ($is_broadcast) {
                    $r['is_read'] = $has_read_record ? 1 : 0;
                } else {
                    $r['is_read'] = (!empty($r['is_read']) || $has_read_record) ? 1 : 0;
                }
            }
            unset($r);

            return $rows;
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('subscribe_client_to_main')) {
    function subscribe_client_to_main($pdo, $client_domain, $main_domain, $client_url = '', $is_local = 1) {
        if (!$pdo || empty($client_domain) || empty($main_domain)) return false;
        try {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO main_subscriptions (client_domain, main_domain, client_url, is_local, subscribed_at) VALUES (?, ?, ?, ?, datetime('now'))");
            return $stmt->execute([$client_domain, $main_domain, $client_url, $is_local ? 1 : 0]);
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
