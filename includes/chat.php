<?php
/**
 * OpenMind — OpenClaw Chat Handler
 *
 * Handles the 'chat' POST action by invoking the OpenClaw CLI agent
 * and returning the response as JSON.
 * Expects $config to be available.
 */

header('Content-Type: application/json');
set_time_limit($config['chat_timeout']);

$rawInput = file_get_contents('php://input');
$input = @json_decode($rawInput, true) ?: [];

$message   = trim($input['message'] ?? '');
$sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['sessionId'] ?? '');

if (!$message) {
    echo json_encode(['success' => false, 'error' => 'Empty message']);
    exit;
}

if (!$sessionId) {
    $sessionId = 'web-' . bin2hex(random_bytes(8));
}

// Build the openclaw command from config
$clawCmd  = $config['openclaw_command'];
$agent    = $config['openclaw_agent'];
$runAs    = $config['openclaw_run_as'];

$escapedMsg   = escapeshellarg($message);
$escapedSid   = escapeshellarg('webchat-' . $sessionId);
$escapedAgent = escapeshellarg($agent);
$escapedCmd   = escapeshellarg($clawCmd);

// Set OPENCLAW_HOME for Docker installs (gateway config lives in /app/data/.openclaw)
$envPrefix = '';
$openclawHome = '/app/data/.openclaw';
if (is_dir($openclawHome)) {
    $envPrefix = 'OPENCLAW_HOME=' . escapeshellarg($openclawHome) . ' ';
}

if ($runAs) {
    $escapedUser = escapeshellarg($runAs);
    $cmd = "{$envPrefix}sudo -u $escapedUser $escapedCmd agent --agent $escapedAgent --session-id $escapedSid --message $escapedMsg --json 2>&1";
} else {
    $cmd = "{$envPrefix}$escapedCmd agent --agent $escapedAgent --session-id $escapedSid --message $escapedMsg --json 2>&1";
}

$output = shell_exec($cmd);
$json = @json_decode($output, true);

if ($json && ($json['status'] ?? '') === 'ok') {
    $text = '';
    foreach (($json['result']['payloads'] ?? []) as $p) {
        if (!empty($p['text'])) $text .= $p['text'] . "\n";
    }
    echo json_encode([
        'success'    => true,
        'response'   => trim($text),
        'sessionId'  => $sessionId,
        'model'      => $json['result']['meta']['agentMeta']['model'] ?? '',
        'durationMs' => $json['result']['meta']['durationMs'] ?? 0,
    ]);
} else {
    // Log the raw output server-side only; never expose it to the client
    if ($output) error_log('OpenClaw error output: ' . substr(trim($output), 0, 500));
    $errMsg = 'Agent error';
    if ($json && isset($json['error'])) $errMsg = $json['error'];
    echo json_encode(['success' => false, 'error' => $errMsg, 'sessionId' => $sessionId]);
}
exit;
