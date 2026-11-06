#!/usr/bin/env php
<?php
// ==============================================================================
// Simultaneous Multi-Client Contact Suite Updater (contact_update_all.php)
// addr.bar Remote Server Directory & Federated Correspondence Suite
// ==============================================================================

// Locate install.sh
$possible_installers = [
    dirname(__FILE__) . '/install.sh',
    dirname(__FILE__) . '/tricurse/contact/install.sh',
    '/addr.bar/tricurse/contact/install.sh',
    '/addr.bar/install.sh',
    '/var/www/html/contact/install.sh'
];

$installer = null;
foreach ($possible_installers as $cand) {
    if (file_exists($cand) && is_executable($cand)) {
        $installer = $cand;
        break;
    }
}

if (!$installer) {
    if (file_exists('./install.sh') && is_executable('./install.sh')) {
        $installer = realpath('./install.sh');
    }
}

if (!$installer) {
    fwrite(STDERR, "Error: install.sh could not be located to perform simultaneous update.\n");
    exit(1);
}

// Forward all command line arguments to install.sh --update-all
$args = array_slice($argv, 1);
$cmd = escapeshellarg($installer) . ' --update-all ' . implode(' ', array_map('escapeshellarg', $args));

passthru($cmd, $exitCode);
exit($exitCode);
