<?php
/**
 * OpenMind for OpenClaw — Configuration
 *
 * Copy this file to config.php and adjust the values for your setup:
 *   cp config.sample.php config.php
 *
 * Only values you want to override need to be here.
 * All other settings use defaults from includes/defaults.php.
 */
return [
    // Path to your OpenClaw workspace directory
    'workspace_path'      => '/root/.openclaw/workspace',

    // Where file backups are stored before edits/deletes
    'backup_path'         => __DIR__ . '/backups',

    // Path to the openclaw CLI binary
    'openclaw_command'    => '/usr/bin/openclaw',

    // Which OpenClaw agent to use for chat
    'openclaw_agent'      => 'main',

    // Run openclaw as a different user (via sudo -u). Leave empty to run as the web server user.
    // Example: 'alice'
    'openclaw_run_as'     => '',

    // Network restriction: 'none', 'tailscale', or 'custom'
    'network_restriction' => 'none',

    // Comma-separated CIDRs for 'custom' network restriction
    // Example: '192.168.1.0/24, 10.0.0.0/8'
    'allowed_ips'         => '',

    // Session lifetime in seconds (default 24 hours)
    'session_lifetime'    => 86400,

    // Application title shown in the header and browser tab
    'app_title'           => 'OpenMind',
];
