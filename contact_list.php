#!/usr/bin/env php
<?php
// ==============================================================================
// Contact Suite Installation Registry & Lister (contact_list.php)
// addr.bar Remote Server Directory & Federated Correspondence Suite
// ==============================================================================

$registry_files = array_filter([
    getenv('REG_FILE') ?: '',
    getenv('TRICURSE_REGISTRY') ?: '',
    '/etc/tricurse/installations.json',
    getenv('HOME') ? (getenv('HOME') . '/.local/share/tricurse/installations.json') : ''
]);

$installs = [];

foreach ($registry_files as $rf) {
    if (file_exists($rf)) {
        $data = @json_decode(@file_get_contents($rf), true);
        if (is_array($data)) {
            foreach ($data as $item) {
                $d = strtolower(trim(preg_replace('/:[0-9]+$/', '', $item['domain'] ?? '')));
                if ($d === '' || filter_var($d, FILTER_VALIDATE_IP) || preg_match('/^[0-9\.\:]+$/', $d) || $d === 'localhost') continue;
                $key = $d . '::' . ($item['webroot'] ?? '');
                $installs[$key] = $item;
            }
        }
    }
}

// Also scan /etc/tricurse/*/contact.conf for any installations not yet in json
if (is_dir('/etc/tricurse')) {
    $dirs = @glob('/etc/tricurse/*/contact.conf');
    if ($dirs) {
        foreach ($dirs as $cf) {
            $dom = strtolower(trim(preg_replace('/:[0-9]+$/', '', basename(dirname($cf)))));
            if ($dom === '' || filter_var($dom, FILTER_VALIDATE_IP) || preg_match('/^[0-9\.\:]+$/', $dom) || $dom === 'localhost') continue;
            $key_prefix = $dom . '::';
            $already_listed = false;
            foreach ($installs as $k => $v) {
                if (strpos($k, $key_prefix) === 0) {
                    $already_listed = true;
                    break;
                }
            }
            if (!$already_listed) {
                // Parse conf
                $messages_dir = '';
                $sqlite_path = '';
                $webroot = '';
                $server_role = 'main';
                $subscribed_to = '';
                @include $cf;
                $installs[$dom . '::' . $webroot] = [
                    'domain'        => $dom,
                    'webroot'       => $webroot ?: '(configured in ' . $cf . ')',
                    'messages_dir'  => $messages_dir,
                    'db_path'       => $sqlite_path ?: ($messages_dir ? $messages_dir . '/contact.sqlite' : ''),
                    'role'          => $server_role ?: 'main',
                    'subscribed_to' => $subscribed_to ?: '',
                    'config_file'   => $cf,
                    'installed_at'  => date('Y-m-d H:i:s', filemtime($cf)),
                    'installed_by'  => 'system'
                ];
            }
        }
    }
}

// Check /etc/hosts content for loopback status
$hosts_content = is_readable('/etc/hosts') ? @file_get_contents('/etc/hosts') : '';

echo "\n========================================================================================\n";
echo "    addr.bar / Tricurse Contact Suite — Recorded Server Installations\n";
echo "========================================================================================\n";

if (empty($installs)) {
    echo "No recorded installations found on this server.\n";
    echo "Installations are registered automatically when running sudo ./install.sh.\n\n";
    exit(0);
}

printf("%-24s | %-16s | %-12s | %-18s | %-24s | %-10s\n", "Domain / Subdomain", "Name", "Role", "Subscribed To", "Website Directory", "/etc/hosts");
echo str_repeat("-", 115) . "\n";

$db_counts = [];
foreach ($installs as $ins) {
    $p = $ins['db_path'] ?? '';
    if ($p !== '') {
        $db_counts[$p] = ($db_counts[$p] ?? 0) + 1;
    }
}

foreach ($installs as $ins) {
    $dom  = $ins['domain'] ?? 'localhost';
    $role = ($ins['role'] ?? 'main') === 'client' ? 'Client' : 'Main Point';
    $sub  = $ins['subscribed_to'] ?? '';
    if ($sub === '' && ($ins['role'] ?? 'main') === 'main') {
        $sub = '(Broadcasting)';
    } elseif ($sub === '') {
        $sub = '-';
    }
    $web  = $ins['webroot'] ?? '';
    $db   = $ins['db_path'] ?? '';
    
    // Resolve client name
    $c_name = $ins['client_name'] ?? '';
    if ($c_name === '' && !empty($ins['config_file']) && file_exists($ins['config_file'])) {
        $client_name = '';
        @include $ins['config_file'];
        if (!empty($client_name)) $c_name = $client_name;
    }
    if ($c_name === '' && !empty($db) && file_exists($db)) {
        try {
            $pdo_list = new PDO("sqlite:" . $db);
            $stmt = $pdo_list->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
            $stmt->execute([$dom . '::client_name']);
            $v = $stmt->fetchColumn();
            if ($v) $c_name = $v;
        } catch (Exception $e) {}
    }
    if ($c_name === '') {
        $c_name = '(not set)';
    }

    // Check if domain is in /etc/hosts
    $in_hosts = false;
    if ($hosts_content !== '' && preg_match('/^\s*(?:127\.\d+\.\d+\.\d+|::1)\s+.*\b' . preg_quote($dom, '/') . '\b/mi', $hosts_content)) {
        $in_hosts = true;
    }

    $hosts_label = $in_hosts ? "✓ Loopback" : "○ Remote";

    $has_index = (!empty($web) && is_dir($web) && file_exists($web . '/index.php'));
    $display_web = $web ?: '(none)';
    if (!$has_index) {
        $display_web .= ' ⚠️ (no index.php)';
    }

    printf("%-24s | %-16s | %-12s | %-18s | %-24s | %-10s\n",
        substr($dom, 0, 24),
        substr($c_name, 0, 16),
        substr($role, 0, 12),
        substr($sub, 0, 18),
        substr($display_web, 0, 24),
        $hosts_label
    );
}

echo "========================================================================================\n";
echo "Total installations recorded: " . count($installs) . "\n";
echo "Outside / Inside rate limits: local server loopback domains receive the inside quota.\n";
echo "To update all installations simultaneously: sudo contact_update_all (or sudo ./install.sh --update-all)\n";
echo "To remove an old or relocated client:       sudo remove_client (or sudo remove_client --clean-orphans)\n\n";
