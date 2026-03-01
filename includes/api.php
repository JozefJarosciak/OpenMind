<?php
/**
 * OpenMind — File API Handlers
 *
 * Handles all file operations (save, rename, delete, create) and
 * GET requests for file content and branch refresh.
 * Expects $config, $authDb, and workspace.php functions to be available.
 */

require_once __DIR__ . '/workspace.php';

$workspace = rtrim($config['workspace_path'], '/');
$backupPath = rtrim($config['backup_path'], '/');

// ── GET: Fetch file content ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['file'])) {
    header('Content-Type: application/json');
    $abs = realpath($workspace . '/' . $_GET['file']);
    if ($abs && strpos($abs, realpath($workspace)) === 0 && file_exists($abs)) {
        echo json_encode(['content' => file_get_contents($abs)]);
    } else {
        echo json_encode(['content' => '']);
    }
    exit;
}

// ── GET: Refresh branch tree ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['refreshFile'])) {
    header('Content-Type: application/json');
    $rel = $_GET['refreshFile'];
    $abs = realpath($workspace . '/' . $rel);
    if ($abs && strpos($abs, realpath($workspace)) === 0 && file_exists($abs)) {
        $tree = buildFileNode($abs, $workspace, $config);
        echo json_encode(['success' => true, 'tree' => $tree]);
    } else {
        echo json_encode(['success' => false, 'error' => 'File not found']);
    }
    exit;
}

// ── POST handlers ───────────────────────────────────────────────────────────
$rawInput = file_get_contents('php://input');
$input = @json_decode($rawInput, true) ?: [];
$action = $_POST['action'] ?? ($input['action'] ?? '');

function makeBackup($filePath, $backupPath) {
    $backupDir = $backupPath . '/' . date('Y-m-d_H-i-s');
    if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);
    copy($filePath, $backupDir . '/' . basename($filePath));
}

// ── Refresh Title (re-detect bot name via Telegram API) ─────────────────────
if ($action === 'refreshTitle') {
    header('Content-Type: application/json');
    $newTitle = detectBotDisplayName($config);
    if (!$newTitle) $newTitle = detectAgentName($config);

    if ($newTitle && $newTitle !== ($config['app_title'] ?? '')) {
        $configFile = __DIR__ . '/../config.php';
        $existingConfig = file_exists($configFile) ? (require $configFile) : [];
        if (!is_array($existingConfig)) $existingConfig = [];
        $existingConfig['app_title'] = $newTitle;
        file_put_contents($configFile, "<?php\nreturn " . var_export($existingConfig, true) . ";\n");
    }

    echo json_encode(['success' => true, 'title' => $newTitle ?: ($config['app_title'] ?? 'OpenMind')]);
    exit;
}

// ── Save Node ───────────────────────────────────────────────────────────────
if ($action === 'saveNode') {
    header('Content-Type: application/json');
    $file    = $input['file'] ?? '';
    $content = $input['content'] ?? '';
    $abs = realpath($workspace . '/' . $file);
    if ($abs && strpos($abs, realpath($workspace)) === 0 && file_exists($abs)) {
        makeBackup($abs, $backupPath);
        file_put_contents($abs, $content);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid file']);
    }
    exit;
}

// ── Change Password ─────────────────────────────────────────────────────────
if ($action === 'changePassword') {
    header('Content-Type: application/json');
    $currentPw = $input['currentPassword'] ?? '';
    $newPw     = $input['newPassword'] ?? '';

    $pwErrors = validate_password($newPw);
    if (!empty($pwErrors)) {
        echo json_encode(['success' => false, 'error' => 'Password needs: ' . implode(', ', $pwErrors)]);
        exit;
    }

    $db = new SQLite3($authDb);
    $stmt = $db->prepare('SELECT password FROM users WHERE username = :u');
    $stmt->bindValue(':u', $_SESSION['user'], SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row || !password_verify($currentPw, $row['password'])) {
        $db->close();
        echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
        exit;
    }

    $hash = password_hash($newPw, PASSWORD_BCRYPT);
    $stmt2 = $db->prepare('UPDATE users SET password = :p WHERE username = :u');
    $stmt2->bindValue(':p', $hash, SQLITE3_TEXT);
    $stmt2->bindValue(':u', $_SESSION['user'], SQLITE3_TEXT);
    $stmt2->execute();
    $db->close();
    echo json_encode(['success' => true]);
    exit;
}

// ── Rename File ─────────────────────────────────────────────────────────────
if ($action === 'renameFile') {
    header('Content-Type: application/json');
    $oldFile = $input['oldFile'] ?? '';
    $newName = $input['newName'] ?? '';

    if (!$oldFile || !$newName) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }

    $newName = preg_replace('/[^a-zA-Z0-9._\- ]/', '', $newName);
    if (!$newName) {
        echo json_encode(['success' => false, 'error' => 'Invalid filename']);
        exit;
    }
    if (!preg_match('/\.md$/i', $newName)) $newName .= '.md';

    $dir = dirname($oldFile);
    $newFile = ($dir && $dir !== '.') ? $dir . '/' . $newName : $newName;
    $oldAbs = realpath($workspace . '/' . $oldFile);
    $newAbs = $workspace . '/' . $newFile;

    if (!$oldAbs || strpos($oldAbs, realpath($workspace)) !== 0 || !file_exists($oldAbs)) {
        echo json_encode(['success' => false, 'error' => 'Source file not found']);
        exit;
    }
    if (file_exists($newAbs)) {
        echo json_encode(['success' => false, 'error' => 'A file with that name already exists']);
        exit;
    }

    makeBackup($oldAbs, $backupPath);
    if (rename($oldAbs, $newAbs)) {
        echo json_encode(['success' => true, 'newFile' => $newFile]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Rename failed']);
    }
    exit;
}

// ── Delete File ─────────────────────────────────────────────────────────────
if ($action === 'deleteFile') {
    header('Content-Type: application/json');
    $file = $input['file'] ?? '';

    if (!$file) {
        echo json_encode(['success' => false, 'error' => 'Missing file parameter']);
        exit;
    }

    $abs = realpath($workspace . '/' . $file);
    if (!$abs || strpos($abs, realpath($workspace)) !== 0 || !file_exists($abs)) {
        echo json_encode(['success' => false, 'error' => 'File not found']);
        exit;
    }

    makeBackup($abs, $backupPath);
    if (unlink($abs)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Delete failed']);
    }
    exit;
}

// ── Create File ─────────────────────────────────────────────────────────────
if ($action === 'createFile') {
    header('Content-Type: application/json');
    $name      = $input['name'] ?? '';
    $directory = $input['directory'] ?? '';

    if (!$name) {
        echo json_encode(['success' => false, 'error' => 'Missing filename']);
        exit;
    }

    $name = preg_replace('/[^a-zA-Z0-9._\- ]/', '', $name);
    if (!$name) {
        echo json_encode(['success' => false, 'error' => 'Invalid filename']);
        exit;
    }
    if (!preg_match('/\.md$/i', $name)) $name .= '.md';

    $directory = preg_replace('/[^a-zA-Z0-9._\-\/]/', '', $directory);
    $directory = trim($directory, '/');

    $relPath = $directory ? $directory . '/' . $name : $name;
    $absPath = $workspace . '/' . $relPath;
    $dirAbs  = $workspace . ($directory ? '/' . $directory : '');

    if (!is_dir($dirAbs)) {
        if (!mkdir($dirAbs, 0750, true)) {
            echo json_encode(['success' => false, 'error' => 'Failed to create directory']);
            exit;
        }
    }

    $realDir = realpath($dirAbs);
    if (!$realDir || strpos($realDir, realpath($workspace)) !== 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid directory']);
        exit;
    }

    if (file_exists($absPath)) {
        echo json_encode(['success' => false, 'error' => 'A file with that name already exists']);
        exit;
    }

    $heading = basename($name, '.md');
    if (file_put_contents($absPath, "# " . $heading . "\n\n") !== false) {
        echo json_encode(['success' => true, 'file' => $relPath]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to create file']);
    }
    exit;
}

// ── Legacy fallback (full backup) ───────────────────────────────────────────
header('Content-Type: application/json');
$backupDir = $backupPath . '/' . date('Y-m-d_H-i-s');
if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);
foreach (glob($workspace . '/*.md') ?: [] as $f) copy($f, $backupDir . '/' . basename($f));
foreach (glob($workspace . '/memory/*.md') ?: [] as $f) copy($f, $backupDir . '/' . basename($f));
file_put_contents($backupDir . '/edit-log.json', json_encode($input, JSON_PRETTY_PRINT));
echo json_encode(['success' => true, 'backup' => basename($backupDir)]);
exit;
