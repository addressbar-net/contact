#!/usr/bin/env php
<?php
// ==============================================================================
// Database Merger Utility (merge_databases.php)
// addr.bar Remote Server Directory & Federated Correspondence Suite
// ==============================================================================
// Merges multiple independent domain SQLite databases into a single shared database.
// Updates all client db_config.php and installations.json registry records.
// ==============================================================================

if (php_sapi_name() !== 'cli') {
    die("This utility must be run from the command line.\n");
}

$short_opts = "t:s:h";
$long_opts  = [
    "target:",
    "schema:",
    "source:",
    "update-configs",
    "help"
];

$options = getopt($short_opts, $long_opts);

if (isset($options['h']) || isset($options['help']) || (empty($options['t']) && empty($options['target']))) {
    echo "Usage: sudo php merge_databases.php --target <target_db_path> [options]\n\n";
    echo "Options:\n";
    echo "  -t, --target PATH       Path to the target shared SQLite database (e.g. /var/lib/tricurse/contact/contact.sqlite)\n";
    echo "  --schema PATH           Path to schema.sql file (default: __DIR__/schema.sql)\n";
    echo "  -s, --source PATH       Explicit source SQLite database path (repeatable, or scans registered installs if omitted)\n";
    echo "  --update-configs        Update db_config.php and contact.conf in all existing webroots to point to target DB\n";
    echo "  -h, --help              Show this help message\n\n";
    exit(0);
}

$target_db = trim($options['t'] ?? ($options['target'] ?? ''));
if ($target_db === '') {
    fwrite(STDERR, "Error: Target database path is required (--target <path>).\n");
    exit(1);
}

$schema_file = trim($options['schema'] ?? (__DIR__ . '/schema.sql'));
$update_configs = isset($options['update-configs']);

// 1. Gather all registered installations
$registry_files = [
    '/etc/tricurse/installations.json',
    getenv('HOME') . '/.local/share/tricurse/installations.json'
];

$installs = [];
foreach ($registry_files as $rf) {
    if (file_exists($rf)) {
        $d = @json_decode(@file_get_contents($rf), true);
        if (is_array($d)) {
            foreach ($d as $item) {
                $k = strtolower($item['domain'] ?? '') . '::' . ($item['webroot'] ?? '');
                $installs[$k] = $item;
            }
        }
    }
}

// Also scan /etc/tricurse/*/contact.conf
if (is_dir('/etc/tricurse')) {
    $dirs = @glob('/etc/tricurse/*/contact.conf');
    if ($dirs) {
        foreach ($dirs as $cf) {
            $dom = basename(dirname($cf));
            $k_prefix = strtolower($dom) . '::';
            $found = false;
            foreach ($installs as $k => $v) {
                if (strpos($k, $k_prefix) === 0) { $found = true; break; }
            }
            if (!$found) {
                $messages_dir = ''; $sqlite_path = ''; $webroot = ''; $server_role = 'main'; $subscribed_to = '';
                @include $cf;
                $installs[$dom . '::' . $webroot] = [
                    'domain'        => $dom,
                    'webroot'       => $webroot ?: '',
                    'messages_dir'  => $messages_dir,
                    'db_path'       => $sqlite_path ?: ($messages_dir ? $messages_dir . '/contact.sqlite' : ''),
                    'role'          => $server_role ?: 'main',
                    'subscribed_to' => $subscribed_to ?: '',
                    'config_file'   => $cf
                ];
            }
        }
    }
}

// Determine source databases to merge
$source_dbs = [];
if (!empty($options['s']) || !empty($options['source'])) {
    $raw_sources = $options['s'] ?? $options['source'];
    if (!is_array($raw_sources)) $raw_sources = [$raw_sources];
    foreach ($raw_sources as $s) {
        if (file_exists($s)) {
            $real_s = realpath($s);
            $source_dbs[$real_s] = ['path' => $real_s, 'domain' => 'localhost'];
        }
    }
} else {
    foreach ($installs as $ins) {
        $p = $ins['db_path'] ?? '';
        if ($p !== '' && file_exists($p)) {
            $real_p = realpath($p);
            $source_dbs[$real_p] = [
                'path'    => $real_p,
                'domain'  => $ins['domain'] ?? 'localhost',
                'webroot' => $ins['webroot'] ?? ''
            ];
        }
    }
}

$real_target = file_exists($target_db) ? realpath($target_db) : $target_db;

// Remove target from source list if already pointing there
$sources_to_merge = [];
foreach ($source_dbs as $path => $info) {
    if ($path !== $real_target) {
        $sources_to_merge[$path] = $info;
    }
}

echo "==================================================================\n";
echo "    addr.bar Database Merger Utility — Single Shared Save Location\n";
echo "==================================================================\n";
echo "Target Shared Database: " . $target_db . "\n";
echo "Found " . count($sources_to_merge) . " independent database(s) to merge.\n\n";

// Ensure target directory exists
$target_dir = dirname($target_db);
if (!is_dir($target_dir)) {
    if (!@mkdir($target_dir, 0775, true)) {
        fwrite(STDERR, "Error: Unable to create target directory: {$target_dir}\n");
        exit(1);
    }
}

// 2. Initialize target database connection and apply schema if needed
try {
    $target_pdo = new PDO("sqlite:{$target_db}");
    $target_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $target_pdo->exec("PRAGMA journal_mode = WAL;");
    $target_pdo->exec("PRAGMA foreign_keys = ON;");

    // Check if target database already has schema
    $tables = $target_pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
    if (!$tables && file_exists($schema_file)) {
        echo "Applying initial schema to target database ({$target_db})...\n";
        $sql = file_get_contents($schema_file);
        $target_pdo->exec($sql);
        echo "✓ Target database schema initialized successfully.\n\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "Error connecting to target database: " . $e->getMessage() . "\n");
    exit(1);
}

// 3. Merge each independent database
$total_users = 0;
$total_in = 0;
$total_sent = 0;
$total_push = 0;
$total_subs = 0;

foreach ($sources_to_merge as $src_path => $src_info) {
    $dom = $src_info['domain'];
    echo "Merging: {$src_path} (Domain: {$dom})...\n";

    try {
        $src_pdo = new PDO("sqlite:{$src_path}");
        $src_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $target_pdo->beginTransaction();

        // 3a. Table: users
        $u_stmt = $src_pdo->query("SELECT * FROM users");
        $u_insert = $target_pdo->prepare("
            INSERT INTO users (domain, username, password, email, session_token, reset_token, reset_expires_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $u_check = $target_pdo->prepare("SELECT id FROM users WHERE (domain = ? OR (domain IS NULL AND ? = '')) AND username = ?");

        while ($row = $u_stmt->fetch(PDO::FETCH_ASSOC)) {
            $u_dom = !empty($row['domain']) ? $row['domain'] : $dom;
            $u_check->execute([$u_dom, $u_dom, $row['username']]);
            if (!$u_check->fetch()) {
                $u_insert->execute([
                    $u_dom,
                    $row['username'],
                    $row['password'],
                    $row['email'] ?? null,
                    $row['session_token'] ?? null,
                    $row['reset_token'] ?? null,
                    $row['reset_expires_at'] ?? null,
                    $row['created_at'] ?? date('Y-m-d H:i:s')
                ]);
                $total_users++;
            }
        }

        // 3b. Table: incoming_messages
        $in_stmt = $src_pdo->query("SELECT * FROM incoming_messages");
        $in_insert = $target_pdo->prepare("
            INSERT INTO incoming_messages (
                sender_url, sender_host, sender_ip, sender_rdns, dns_verification_status,
                dns_verification_details, sender_name, sender_email, subject, message_body,
                is_read, received_at, recipient_user, is_sent_copy, all_recipients,
                deleted_by_users, is_delayed, retry_after, destination_url, target_domain
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $in_check = $target_pdo->prepare("
            SELECT id FROM incoming_messages 
            WHERE sender_url = ? AND received_at = ? AND subject = ? AND target_domain = ?
        ");

        while ($row = $in_stmt->fetch(PDO::FETCH_ASSOC)) {
            $t_dom = !empty($row['target_domain']) ? $row['target_domain'] : $dom;
            $in_check->execute([
                $row['sender_url'],
                $row['received_at'] ?? '',
                $row['subject'] ?? '',
                $t_dom
            ]);
            if (!$in_check->fetch()) {
                $in_insert->execute([
                    $row['sender_url'],
                    $row['sender_host'],
                    $row['sender_ip'] ?? '127.0.0.1',
                    $row['sender_rdns'] ?? null,
                    $row['dns_verification_status'] ?? 'RECEIVED',
                    $row['dns_verification_details'] ?? null,
                    $row['sender_name'] ?? null,
                    $row['sender_email'] ?? null,
                    $row['subject'] ?? '',
                    $row['message_body'],
                    $row['is_read'] ?? 0,
                    $row['received_at'] ?? date('Y-m-d H:i:s'),
                    $row['recipient_user'] ?? null,
                    $row['is_sent_copy'] ?? 0,
                    $row['all_recipients'] ?? null,
                    $row['deleted_by_users'] ?? null,
                    $row['is_delayed'] ?? 0,
                    $row['retry_after'] ?? null,
                    $row['destination_url'] ?? null,
                    $t_dom
                ]);
                $total_in++;
            }
        }

        // 3c. Table: sent_messages
        $sent_stmt = $src_pdo->query("SELECT * FROM sent_messages");
        $sent_insert = $target_pdo->prepare("
            INSERT INTO sent_messages (
                destination_url, subject, message_body, delivery_status,
                response_code, response_message, sent_at, recipient_users, all_recipients
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $sent_check = $target_pdo->prepare("
            SELECT id FROM sent_messages WHERE destination_url = ? AND sent_at = ? AND subject = ?
        ");

        while ($row = $sent_stmt->fetch(PDO::FETCH_ASSOC)) {
            $sent_check->execute([
                $row['destination_url'],
                $row['sent_at'] ?? '',
                $row['subject'] ?? ''
            ]);
            if (!$sent_check->fetch()) {
                $sent_insert->execute([
                    $row['destination_url'],
                    $row['subject'] ?? '',
                    $row['message_body'],
                    $row['delivery_status'],
                    $row['response_code'] ?? null,
                    $row['response_message'] ?? null,
                    $row['sent_at'] ?? date('Y-m-d H:i:s'),
                    $row['recipient_users'] ?? null,
                    $row['all_recipients'] ?? null
                ]);
                $total_sent++;
            }
        }

        // 3d. Table: push_subscriptions
        $push_stmt = $src_pdo->query("SELECT * FROM push_subscriptions");
        $push_insert = $target_pdo->prepare("
            INSERT OR IGNORE INTO push_subscriptions (domain, endpoint, p256dh, auth, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        while ($row = $push_stmt->fetch(PDO::FETCH_ASSOC)) {
            $p_dom = !empty($row['domain']) ? $row['domain'] : $dom;
            $push_insert->execute([
                $p_dom,
                $row['endpoint'],
                $row['p256dh'] ?? '',
                $row['auth'] ?? '',
                $row['user_agent'] ?? '',
                $row['created_at'] ?? date('Y-m-d H:i:s')
            ]);
            $total_push++;
        }

        // 3e. Table: main_subscriptions
        $sub_stmt = $src_pdo->query("SELECT * FROM main_subscriptions");
        $sub_insert = $target_pdo->prepare("
            INSERT OR IGNORE INTO main_subscriptions (client_domain, main_domain, client_url, is_local, subscribed_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        while ($row = $sub_stmt->fetch(PDO::FETCH_ASSOC)) {
            $sub_insert->execute([
                $row['client_domain'],
                $row['main_domain'],
                $row['client_url'] ?? '',
                $row['is_local'] ?? 1,
                $row['subscribed_at'] ?? date('Y-m-d H:i:s')
            ]);
            $total_subs++;
        }

        // 3f. Table: blocked_sites
        $b_stmt = $src_pdo->query("SELECT * FROM blocked_sites");
        $b_insert = $target_pdo->prepare("
            INSERT OR IGNORE INTO blocked_sites (domain_or_url, ip_address, reason, is_blocked, created_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        while ($row = $b_stmt->fetch(PDO::FETCH_ASSOC)) {
            $b_insert->execute([
                $row['domain_or_url'],
                $row['ip_address'] ?? null,
                $row['reason'] ?? null,
                $row['is_blocked'] ?? 1,
                $row['created_at'] ?? date('Y-m-d H:i:s')
            ]);
        }

        // 3g. Table: push_disabled_sources
        $pds_stmt = $src_pdo->query("SELECT * FROM push_disabled_sources");
        $pds_insert = $target_pdo->prepare("
            INSERT OR IGNORE INTO push_disabled_sources (domain, source_identifier, created_at)
            VALUES (?, ?, ?)
        ");
        while ($row = $pds_stmt->fetch(PDO::FETCH_ASSOC)) {
            $p_dom = !empty($row['domain']) ? $row['domain'] : $dom;
            $pds_insert->execute([
                $p_dom,
                $row['source_identifier'],
                $row['created_at'] ?? date('Y-m-d H:i:s')
            ]);
        }

        // 3h. Table: message_reads & message_deletions
        $mr_stmt = $src_pdo->query("SELECT * FROM message_reads");
        $mr_insert = $target_pdo->prepare("INSERT OR IGNORE INTO message_reads (message_id, domain, read_at) VALUES (?, ?, ?)");
        while ($row = $mr_stmt->fetch(PDO::FETCH_ASSOC)) {
            $mr_insert->execute([$row['message_id'], $row['domain'] ?? $dom, $row['read_at'] ?? date('Y-m-d H:i:s')]);
        }

        $md_stmt = $src_pdo->query("SELECT * FROM message_deletions");
        $md_insert = $target_pdo->prepare("INSERT OR IGNORE INTO message_deletions (message_id, domain, deleted_at) VALUES (?, ?, ?)");
        while ($row = $md_stmt->fetch(PDO::FETCH_ASSOC)) {
            $md_insert->execute([$row['message_id'], $row['domain'] ?? $dom, $row['deleted_at'] ?? date('Y-m-d H:i:s')]);
        }

        // 3h-2. Table: message_archives
        try {
            $target_pdo->exec("CREATE TABLE IF NOT EXISTS message_archives (
                message_id INTEGER NOT NULL,
                domain TEXT NOT NULL,
                archived_at TEXT DEFAULT (datetime('now')),
                PRIMARY KEY (message_id, domain)
            )");
            $ma_stmt = $src_pdo->query("SELECT * FROM message_archives");
            if ($ma_stmt) {
                $ma_insert = $target_pdo->prepare("INSERT OR IGNORE INTO message_archives (message_id, domain, archived_at) VALUES (?, ?, ?)");
                while ($row = $ma_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $ma_insert->execute([$row['message_id'], $row['domain'] ?? $dom, $row['archived_at'] ?? date('Y-m-d H:i:s')]);
                }
            }
        } catch (Exception $e) {}

        // 3i. Table: login_attempts
        $la_stmt = $src_pdo->query("SELECT * FROM login_attempts");
        $la_insert = $target_pdo->prepare("INSERT INTO login_attempts (domain, ip_address, attempted_at) VALUES (?, ?, ?)");
        while ($row = $la_stmt->fetch(PDO::FETCH_ASSOC)) {
            $la_insert->execute([$row['domain'] ?? $dom, $row['ip_address'], $row['attempted_at'] ?? date('Y-m-d H:i:s')]);
        }

        // 3j. Table: site_settings (preserve per-domain and custom keys)
        $ss_stmt = $src_pdo->query("SELECT * FROM site_settings");
        $ss_insert = $target_pdo->prepare("INSERT OR IGNORE INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)");
        while ($row = $ss_stmt->fetch(PDO::FETCH_ASSOC)) {
            $ss_insert->execute([$row['setting_key'], $row['setting_value'], $row['updated_at'] ?? date('Y-m-d H:i:s')]);
        }

        $target_pdo->commit();
        echo "  ✓ Successfully merged data from {$src_path}\n";
    } catch (Exception $e) {
        if ($target_pdo->inTransaction()) {
            $target_pdo->rollBack();
        }
        fwrite(STDERR, "  ⚠ Warning: Error merging {$src_path}: " . $e->getMessage() . "\n");
    }
}

// 4. Update configurations of all clients if requested
$target_messages_dir = dirname($target_db);
if ($update_configs) {
    echo "\nUpdating client db_config.php files and registry to target shared database...\n";
    foreach ($installs as &$ins) {
        $ins['db_path'] = $target_db;
        $ins['messages_dir'] = $target_messages_dir;

        $w = $ins['webroot'] ?? '';
        if ($w !== '' && is_dir($w)) {
            $cfg_file = rtrim($w, '/\\') . '/db_config.php';
            $cfg_content = "<?php\n" .
                "// Generated by addr.bar Database Merger Utility\n" .
                "\$messages_dir = '" . addslashes($target_messages_dir) . "';\n" .
                "\$data_dir     = '" . addslashes($target_messages_dir) . "';\n" .
                "\$sqlite_path  = '" . addslashes($target_db) . "';\n";
            @file_put_contents($cfg_file, $cfg_content);
            echo "  ✓ Updated db_config.php: {$cfg_file}\n";
        }

        // Update /etc/tricurse/<domain>/contact.conf
        $dom = $ins['domain'] ?? '';
        if ($dom !== '' && is_dir("/etc/tricurse/{$dom}")) {
            $conf_file = "/etc/tricurse/{$dom}/contact.conf";
            $conf_content = "<?php\n" .
                "\$domain        = '" . addslashes($dom) . "';\n" .
                "\$webroot       = '" . addslashes($w) . "';\n" .
                "\$messages_dir  = '" . addslashes($target_messages_dir) . "';\n" .
                "\$sqlite_path   = '" . addslashes($target_db) . "';\n" .
                "\$server_role   = '" . addslashes($ins['role'] ?? 'main') . "';\n" .
                "\$subscribed_to = '" . addslashes($ins['subscribed_to'] ?? '') . "';\n";
            @file_put_contents($conf_file, $conf_content);
            @file_put_contents("/etc/tricurse/{$dom}.conf", $conf_content);
        }
    }
    unset($ins);

    // Save updated installations.json
    foreach ($registry_files as $rf) {
        if (file_exists($rf) || is_dir(dirname($rf))) {
            @mkdir(dirname($rf), 0755, true);
            @file_put_contents($rf, json_encode(array_values($installs), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            echo "  ✓ Updated installation registry: {$rf}\n";
        }
    }

    // Save server-level database configuration (/etc/tricurse/server.json)
    $server_conf_dir = is_dir('/etc/tricurse') ? '/etc/tricurse' : (getenv('HOME') . '/.local/share/tricurse');
    @mkdir($server_conf_dir, 0755, true);
    $server_json_file = "{$server_conf_dir}/server.json";
    $server_conf = [
        'shared_database'  => true,
        'shared_db_path'   => $target_db,
        'messages_dir'     => $target_messages_dir,
        'last_merged_at'   => date('Y-m-d H:i:s')
    ];
    @file_put_contents($server_json_file, json_encode($server_conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "  ✓ Saved server configuration: {$server_json_file}\n";
}

// Ensure correct permissions on the shared database
if (function_exists('posix_getpwnam')) {
    $www_user = file_exists('/etc/debian_version') ? 'www-data' : 'nginx';
    $u_info = @posix_getpwnam($www_user);
    if ($u_info) {
        @chown($target_dir, $www_user);
        @chgrp($target_dir, $www_user);
        @chmod($target_dir, 0775);
        if (file_exists($target_db)) {
            @chown($target_db, $www_user);
            @chgrp($target_db, $www_user);
            @chmod($target_db, 0664);
        }
    }
}

echo "\n==================================================================\n";
echo "✓ Database Merge Complete!\n";
echo "  - Total Users Imported:          {$total_users}\n";
echo "  - Total Inbox Messages Imported: {$total_in}\n";
echo "  - Total Sent Messages Imported:  {$total_sent}\n";
echo "  - Total Push Subscriptions:      {$total_push}\n";
echo "  - Total Site Subscriptions:      {$total_subs}\n";
echo "  - Consolidated Database:         {$target_db}\n";
echo "==================================================================\n\n";
