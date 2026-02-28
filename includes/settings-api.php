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
            'network_restriction' => $config['network_restriction'],
            'allowed_ips'         => $config['allowed_ips'],
            'session_lifetime'    => $config['session_lifetime'],
            'app_title'           => $config['app_title'],
        ]
    ]);
    exit;
}

// ── GET: Check for updates ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['checkUpdate'])) {
    // Get local commit hash
    $appDir = dirname(__DIR__);
    $localHash = trim(shell_exec("git -C " . escapeshellarg($appDir) . " rev-parse HEAD 2>/dev/null") ?: '');
    $localShort = substr($localHash, 0, 7);
    $localDate = trim(shell_exec("git -C " . escapeshellarg($appDir) . " log -1 --format=%ci 2>/dev/null") ?: '');

    // Get remote latest commit
    $remoteHash = '';
    $remoteDate = '';
    $updateAvailable = false;

    // Fetch latest from remote without pulling
    shell_exec("git -C " . escapeshellarg($appDir) . " fetch origin main --quiet 2>/dev/null");
    $remoteHash = trim(shell_exec("git -C " . escapeshellarg($appDir) . " rev-parse origin/main 2>/dev/null") ?: '');
    $remoteShort = substr($remoteHash, 0, 7);
    $remoteDate = trim(shell_exec("git -C " . escapeshellarg($appDir) . " log -1 --format=%ci origin/main 2>/dev/null") ?: '');

    if ($localHash && $remoteHash && $localHash !== $remoteHash) {
        $updateAvailable = true;
    }

    echo json_encode([
        'success' => true,
        'local'   => ['hash' => $localShort, 'date' => $localDate],
        'remote'  => ['hash' => $remoteShort, 'date' => $remoteDate],
        'updateAvailable' => $updateAvailable,
    ]);
    exit;
}

// ── POST: Apply update ─────────────────────────────────────────────────────
$rawInput = file_get_contents('php://input');
$input = @json_decode($rawInput, true) ?: [];

if (($input['action'] ?? '') === 'applyUpdate') {
    $appDir = dirname(__DIR__);
    $isDocker = file_exists('/.dockerenv') || file_exists('/app/data');

    if ($isDocker) {
        // Inside Docker — can only pull code, container rebuild needed from host
        $output = shell_exec("git -C " . escapeshellarg($appDir) . " pull --ff-only origin main 2>&1");
        $ok = strpos($output, 'Already up to date') !== false || strpos($output, 'Fast-forward') !== false;
        echo json_encode([
            'success' => $ok,
            'output'  => trim($output),
            'message' => $ok
                ? 'Code updated. Refresh the page to see changes. If the update includes Docker/config changes, rebuild the container from the host.'
                : 'Update failed. You may need to rebuild from the host: cd ~/openmind && git pull && docker compose up -d --build',
        ]);
    } else {
        // Bare metal — just pull
        $output = shell_exec("git -C " . escapeshellarg($appDir) . " pull --ff-only origin main 2>&1");
        $ok = strpos($output, 'Already up to date') !== false || strpos($output, 'Fast-forward') !== false;
        echo json_encode([
            'success' => $ok,
            'output'  => trim($output),
            'message' => $ok ? 'Updated successfully. Refresh the page.' : 'Update failed: ' . trim($output),
        ]);
    }
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
