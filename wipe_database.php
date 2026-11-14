#!/usr/bin/env php
<?php
// ==============================================================================
// Database Wipe Utility (wipe_database.php)
// addr.bar Remote Server Directory & Federated Correspondence Suite
// ==============================================================================
// Command-line only utility to wipe the database.
// All messages (incoming messages, sent copies, archives, read tracking,
// rollups, and deletions) will be deleted, sequences reset, and the database vacuumed.
// Web server access is strictly forbidden (HTTP 403).
// ==============================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("403 Forbidden: wipe_database is a command-line utility and cannot be accessed from the web server.\n");
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

// Parse command line arguments
$force = false;
$target_db = null;
$scope_domain = null;
$wipe_full = false;

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '-y' || $arg === '--yes' || $arg === '-f' || $arg === '--force') {
        $force = true;
    } elseif ($arg === '--all' || $arg === '--full') {
        $wipe_full = true;
    } elseif (($arg === '-d' || $arg === '--database' || $arg === '--db-path') && isset($argv[$i + 1])) {
        $target_db = trim($argv[++$i]);
    } elseif (($arg === '--domain') && isset($argv[$i + 1])) {
        $scope_domain = strtolower(trim($argv[++$i]));
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "{$ansi['bold']}Database Wipe Utility & Message Purger{$ansi['reset']}\n";
        echo "Usage: php wipe_database.php [options]\n\n";
        echo "Options:\n";
        echo "  -y, --yes, -f, --force     Skip confirmation prompt and wipe immediately\n";
        echo "  -d, --database <path>      Specify custom SQLite database file to wipe\n";
        echo "  --domain <domain>          Only delete messages belonging to specific domain\n";
        echo "  --all, --full              Also wipe login attempts and blocked sites logs\n";
        echo "  -h, --help                 Display this help screen\n\n";
        exit(0);
    }
}

// Include database helper
$db_helper = dirname(__FILE__) . '/db.php';
if (!file_exists($db_helper)) {
    fwrite(STDERR, "{$ansi['red']}Error: db.php not found at {$db_helper}{$ansi['reset']}\n");
    exit(1);
}
require_once $db_helper;

$db = null;
$db_path = null;

if ($target_db) {
    if (!file_exists($target_db)) {
        fwrite(STDERR, "{$ansi['red']}Error: Specified database file not found: {$target_db}{$ansi['reset']}\n");
        exit(1);
    }
    try {
        $db = new PDO("sqlite:" . $target_db, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db_path = realpath($target_db) ?: $target_db;
    } catch (Exception $e) {
        fwrite(STDERR, "{$ansi['red']}Error connecting to {$target_db}: " . $e->getMessage() . "{$ansi['reset']}\n");
        exit(1);
    }
} else {
    $db = function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? ($pdo ?? null));
    $db_path = $GLOBALS['db_file'] ?? ($db_file ?? 'unknown');
    if ($db_path !== 'unknown' && file_exists($db_path)) {
        $db_path = realpath($db_path) ?: $db_path;
    }
}

if (!$db) {
    fwrite(STDERR, "{$ansi['red']}Error: Could not connect to SQLite database.{$ansi['reset']}\n");
    exit(1);
}

// Print confirmation prompt unless --yes / --force is given
if (!$force) {
    echo "\n{$ansi['bold']}{$ansi['yellow']}⚠  DATABASE WIPE CONFIRMATION{$ansi['reset']}\n";
    echo "=================================================================\n";
    echo "Database: {$ansi['cyan']}{$db_path}{$ansi['reset']}\n";
    if ($scope_domain) {
        echo "Target:   {$ansi['yellow']}Domain: {$scope_domain}{$ansi['reset']}\n";
        echo "Action:   {$ansi['red']}All messages belonging to '{$scope_domain}' will be deleted.{$ansi['reset']}\n";
    } else {
        echo "Target:   {$ansi['red']}ENTIRE DATABASE{$ansi['reset']}\n";
        echo "Action:   {$ansi['red']}ALL messages (inbox, sent, archive, rollups, reads) will be permanently deleted!{$ansi['reset']}\n";
    }
    if ($wipe_full) {
        echo "Scope:    {$ansi['yellow']}--all specified: login attempts and blocked sites will also be wiped.{$ansi['reset']}\n";
    }
    echo "=================================================================\n";
    echo "Are you sure you want to proceed? [y/N]: ";
    
    $handle = fopen("php://stdin", "r");
    $input = trim(fgets($handle));
    fclose($handle);
    
    if (strtolower($input) !== 'y' && strtolower($input) !== 'yes') {
        echo "\n{$ansi['yellow']}Operation cancelled. No messages were deleted.{$ansi['reset']}\n";
        exit(0);
    }
}

echo "\n{$ansi['bold']}{$ansi['cyan']}Wiping database messages...{$ansi['reset']}\n";

$counts = [
    'incoming_messages' => 0,
    'sent_messages'     => 0,
    'message_archives'  => 0,
    'message_deletions' => 0,
    'message_reads'     => 0,
    'message_rollups'   => 0,
    'login_attempts'    => 0,
    'blocked_sites'     => 0,
];

try {
    $existing_tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    // Count before deleting
    $tables = ['incoming_messages', 'sent_messages', 'message_archives', 'message_deletions', 'message_reads', 'message_rollups'];
    if ($wipe_full) {
        $tables[] = 'login_attempts';
        $tables[] = 'blocked_sites';
    }

    foreach ($tables as $t) {
        if (!in_array($t, $existing_tables, true)) {
            $counts[$t] = 0;
            continue;
        }
        try {
            $cnt_stmt = $db->query("SELECT count(*) FROM {$t}");
            if ($cnt_stmt) {
                $counts[$t] = (int)$cnt_stmt->fetchColumn();
            }
        } catch (Exception $e) {
            // Table might not exist yet
            $counts[$t] = 0;
        }
    }

    // Begin wipe transaction
    $db->beginTransaction();

    if ($scope_domain) {
        $base = strtolower(trim(preg_replace('/:[0-9]+$/', '', $scope_domain)));
        
        if (in_array('incoming_messages', $existing_tables, true)) {
            $db->prepare("DELETE FROM incoming_messages WHERE target_domain = ? OR target_domain = ? OR sender_host = ? OR sender_host = ?")
                ->execute([$scope_domain, $base, $scope_domain, $base]);
        }
        if (in_array('sent_messages', $existing_tables, true)) {
            $db->prepare("DELETE FROM sent_messages WHERE destination_url LIKE ? OR destination_url LIKE ? OR all_recipients LIKE ? OR all_recipients LIKE ?")
                ->execute(["%{$scope_domain}%", "%{$base}%", "%{$scope_domain}%", "%{$base}%"]);
        }
        if (in_array('message_archives', $existing_tables, true)) {
            $db->prepare("DELETE FROM message_archives WHERE domain = ? OR domain = ?")
                ->execute([$scope_domain, $base]);
        }
        if (in_array('message_deletions', $existing_tables, true)) {
            $db->prepare("DELETE FROM message_deletions WHERE domain = ? OR domain = ?")
                ->execute([$scope_domain, $base]);
        }
        if (in_array('message_reads', $existing_tables, true)) {
            $db->prepare("DELETE FROM message_reads WHERE domain = ? OR domain = ?")
                ->execute([$scope_domain, $base]);
        }
        if (in_array('message_rollups', $existing_tables, true)) {
            $db->prepare("DELETE FROM message_rollups WHERE domain = ? OR domain = ?")
                ->execute([$scope_domain, $base]);
        }
    } else {
        foreach (['incoming_messages', 'sent_messages', 'message_archives', 'message_deletions', 'message_reads', 'message_rollups'] as $t) {
            if (in_array($t, $existing_tables, true)) {
                $db->exec("DELETE FROM {$t};");
            }
        }

        // Reset SQLite auto-increment counters for wiped message tables
        if (in_array('sqlite_sequence', $existing_tables, true)) {
            $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('incoming_messages', 'sent_messages', 'message_reads', 'message_deletions');");
        }

        if ($wipe_full) {
            if (in_array('login_attempts', $existing_tables, true)) $db->exec("DELETE FROM login_attempts;");
            if (in_array('blocked_sites', $existing_tables, true)) $db->exec("DELETE FROM blocked_sites;");
            if (in_array('sqlite_sequence', $existing_tables, true)) {
                $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('login_attempts', 'blocked_sites');");
            }
        }
    }

    $db->commit();

    // Reclaim disk space and optimize DB structure
    try {
        $db->exec("VACUUM;");
    } catch (Exception $e) {
        // VACUUM might fail if temp disk full or read-only; non-fatal
    }

    // Restore safe permissions
    if (file_exists($db_path)) {
        @chmod($db_path, 0666);
    }
    if (file_exists($db_path . '-wal')) {
        @chmod($db_path . '-wal', 0666);
    }
    if (file_exists($db_path . '-shm')) {
        @chmod($db_path . '-shm', 0666);
    }

    echo "{$ansi['green']}✔ Database wiped successfully.{$ansi['reset']}\n\n";
    echo "{$ansi['bold']}Summary of deleted records:{$ansi['reset']}\n";
    echo "  - Incoming messages deleted: {$ansi['yellow']}{$counts['incoming_messages']}{$ansi['reset']}\n";
    echo "  - Sent outbox copies deleted: {$ansi['yellow']}{$counts['sent_messages']}{$ansi['reset']}\n";
    echo "  - Archived messages cleared:  {$ansi['yellow']}{$counts['message_archives']}{$ansi['reset']}\n";
    echo "  - Message deletion records:   {$ansi['yellow']}{$counts['message_deletions']}{$ansi['reset']}\n";
    echo "  - Message read markers:       {$ansi['yellow']}{$counts['message_reads']}{$ansi['reset']}\n";
    echo "  - Message rollups cleared:    {$ansi['yellow']}{$counts['message_rollups']}{$ansi['reset']}\n";
    if ($wipe_full) {
        echo "  - Login attempts cleared:     {$ansi['yellow']}{$counts['login_attempts']}{$ansi['reset']}\n";
        echo "  - Blocked sites cleared:      {$ansi['yellow']}{$counts['blocked_sites']}{$ansi['reset']}\n";
    }

    $total_msgs = $counts['incoming_messages'] + $counts['sent_messages'] + $counts['message_archives'];
    echo "\n{$ansi['bold']}{$ansi['green']}All messages deleted ({$total_msgs} total message records removed).{$ansi['reset']}\n";
    echo "Database: {$ansi['cyan']}{$db_path}{$ansi['reset']} (vacuumed and optimized)\n\n";
    exit(0);

} catch (Exception $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "{$ansi['red']}Error during database wipe: " . $e->getMessage() . "{$ansi['reset']}\n");
    exit(1);
}
