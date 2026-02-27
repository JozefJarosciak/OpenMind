<?php
/**
 * OpenMind — Settings API
 *
 * Handles reading and writing the application configuration.
 * GET ?getSettings  → returns current config as JSON
 * POST saveSettings → validates and writes config.php
 *
 * Expects $config (from get_config()) to be available.
 */

header('Content-Type: application/json');

// ── GET: Return current settings ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['getSettings'])) {
    echo json_encode([
        'success' => true,
        'config'  => [
            'workspace_path'      => $config['workspace_path'],
            'backup_path'         => $config['backup_path'],
            'openclaw_command'    => $config['openclaw_command'],
            'openclaw_agent'      => $config['openclaw_agent'],
            'openclaw_run_as'     => $config['openclaw_run_as'],
            'network_restriction' => $config['network_restriction'],
            'allowed_ips'         => $config['allowed_ips'],
            'session_lifetime'    => $config['session_lifetime'],
            'app_title'           => $config['app_title'],
        ]
    ]);
    exit;
}

// ── POST: Save settings ─────────────────────────────────────────────────────
$rawInput = file_get_contents('php://input');
$input = @json_decode($rawInput, true) ?: [];

if (($input['action'] ?? '') !== 'saveSettings') {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

$settings = $input['settings'] ?? [];

// Validate required fields
if (empty($settings['workspace_path'])) {
    echo json_encode(['success' => false, 'error' => 'Workspace path is required']);
    exit;
}

// Validate workspace path exists
if (!is_dir($settings['workspace_path'])) {
    error_log('OpenMind settings: workspace path does not exist: ' . $settings['workspace_path']);
    echo json_encode(['success' => false, 'error' => 'Workspace path does not exist or is not a directory']);
    exit;
}

// Validate network restriction value
$validRestrictions = ['none', 'tailscale', 'custom'];
if (!in_array($settings['network_restriction'] ?? 'none', $validRestrictions)) {
    echo json_encode(['success' => false, 'error' => 'Invalid network restriction']);
    exit;
}

// Read existing user config (sparse — only user-overridden keys)
$configFile = __DIR__ . '/../config.php';
$existingUserConfig = file_exists($configFile) ? (require $configFile) : [];
if (!is_array($existingUserConfig)) $existingUserConfig = [];

// Merge only the keys the settings form manages; preserve everything else
$formKeys = [
    'workspace_path'      => rtrim($settings['workspace_path'], '/'),
    'backup_path'         => $settings['backup_path'] ?: $config['backup_path'],
    'openclaw_command'    => $settings['openclaw_command'] ?: $config['openclaw_command'],
    'openclaw_agent'      => $settings['openclaw_agent'] ?: $config['openclaw_agent'],
    'openclaw_run_as'     => $settings['openclaw_run_as'] ?? '',
    'network_restriction' => $settings['network_restriction'] ?? 'none',
    'allowed_ips'         => $settings['allowed_ips'] ?? '',
    'session_lifetime'    => max($config['min_session_lifetime'], (int)($settings['session_lifetime'] ?? $config['session_lifetime'])),
    'app_title'           => $settings['app_title'] ?: $config['app_title'],
];

$newUserConfig = array_merge($existingUserConfig, $formKeys);

// Write config.php
$export = "<?php\nreturn " . var_export($newUserConfig, true) . ";\n";

if (file_put_contents($configFile, $export) !== false) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to write config file. Check file permissions.']);
}
exit;
