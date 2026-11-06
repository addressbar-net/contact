#!/usr/bin/env php
<?php
// ==============================================================================
// Contact Suite Installation Registry & Lister (contact_list.php)
// addr.bar Remote Server Directory & Federated Correspondence Suite
// ==============================================================================

$registry_files = [
    '/etc/tricurse/installations.json',
    getenv('HOME') . '/.local/share/tricurse/installations.json'
];

$installs = [];

foreach ($registry_files as $rf) {
    if (file_exists($rf)) {
        $data = @json_decode(@file_get_contents($rf), true);
        if (is_array($data)) {
            foreach ($data as $item) {
                $key = strtolower($item['domain'] ?? '') . '::' . ($item['webroot'] ?? '');
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
            $dom = basename(dirname($cf));
            $key_prefix = strtolower($dom) . '::';
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
                @include $cf;
                $installs[$dom . '::' . $webroot] = [
                    'domain'       => $dom,
                    'webroot'      => $webroot ?: '(configured in ' . $cf . ')',
                    'messages_dir' => $messages_dir,
                    'db_path'      => $sqlite_path ?: ($messages_dir ? $messages_dir . '/contact.sqlite' : ''),
                    'config_file'  => $cf,
                    'installed_at' => date('Y-m-d H:i:s', filemtime($cf)),
                    'installed_by' => 'system'
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

printf("%-26s | %-28s | %-10s | %-16s\n", "Domain / Subdomain", "Website Directory", "/etc/hosts", "Database Storage");
echo str_repeat("-", 90) . "\n";

foreach ($installs as $ins) {
    $dom = $ins['domain'] ?? 'localhost';
    $web = $ins['webroot'] ?? '';
    $db  = $ins['db_path'] ?? '';
    
    // Check if domain is in /etc/hosts
    $in_hosts = false;
    if ($hosts_content !== '' && preg_match('/^\s*(?:127\.\d+\.\d+\.\d+|::1)\s+.*\b' . preg_quote($dom, '/') . '\b/mi', $hosts_content)) {
        $in_hosts = true;
    }

    $hosts_label = $in_hosts ? "✓ Loopback" : "○ Remote";

    printf("%-26s | %-28s | %-10s | %-16s\n",
        substr($dom, 0, 26),
        substr($web, 0, 28),
        $hosts_label,
        substr(basename(dirname($db)) . '/' . basename($db), 0, 16)
    );
}

echo "========================================================================================\n";
echo "Total installations recorded: " . count($installs) . "\n";
echo "Outside / Inside rate limits: local server loopback domains receive the inside quota.\n";
echo "To update all installations simultaneously: sudo contact_update_all (or sudo ./install.sh --update-all)\n\n";
