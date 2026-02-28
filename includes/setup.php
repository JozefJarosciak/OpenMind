<?php
/**
 * OpenMind — First-Run Setup
 *
 * Shown when config.php does not exist. Guides the user through initial configuration.
 */

require_once __DIR__ . '/defaults.php';
$defaults = get_defaults();

$setupError = '';
$setupSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'setup') {
    $workspace = trim($_POST['workspace_path'] ?? '');
    if (!$workspace) {
        $setupError = 'Workspace path is required.';
    } elseif (!is_dir($workspace)) {
        $setupError = 'Workspace path does not exist: ' . htmlspecialchars($workspace);
    } else {
        $newConfig = [
            'workspace_path'      => rtrim($workspace, '/'),
            'backup_path'         => $defaults['backup_path'],
            'network_restriction' => $defaults['network_restriction'],
            'allowed_ips'         => $defaults['allowed_ips'],
            'session_lifetime'    => $defaults['session_lifetime'],
            'app_title'           => trim($_POST['app_title'] ?? '') ?: $defaults['app_title'],
        ];

        $configFile = __DIR__ . '/../config.php';
        $export = "<?php\nreturn " . var_export($newConfig, true) . ";\n";
        if (file_put_contents($configFile, $export) !== false) {
            $setupSuccess = true;
        } else {
            $setupError = 'Failed to write config.php. Check file permissions on: ' . dirname($configFile);
        }
    }
}

$err = $setupError ? '<div class="error">' . htmlspecialchars($setupError) . '</div>' : '';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OpenMind Setup</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#1e1e2e;color:#cdd6f4;font-family:'Segoe UI',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem}
.setup-card{background:#181825;border:1px solid #45475a;border-radius:16px;padding:2.5rem;width:100%;max-width:500px;box-shadow:0 8px 32px rgba(0,0,0,.4)}
h1{text-align:center;color:#89b4fa;font-size:1.4rem;margin-bottom:.3rem}
.subtitle{text-align:center;color:#7f849c;font-size:.85rem;margin-bottom:2rem}
label{display:block;font-size:.82rem;color:#a6adc8;margin-bottom:.3rem;font-weight:500}
input[type=text]{width:100%;padding:.65rem .8rem;border:1px solid #45475a;border-radius:8px;background:#313244;color:#cdd6f4;font-size:.9rem;outline:none;transition:border-color .2s}
input:focus{border-color:#89b4fa}
.field{margin-bottom:1.2rem}
.hint{font-size:.72rem;color:#7f849c;margin-top:.2rem}
button{width:100%;padding:.7rem;border:none;border-radius:8px;background:linear-gradient(135deg,#89b4fa,#74c7ec);color:#1e1e2e;font-size:.9rem;font-weight:600;cursor:pointer;transition:all .2s}
button:hover{box-shadow:0 4px 16px rgba(137,180,250,.3);transform:translateY(-1px)}
.error{background:#f38ba822;color:#f38ba8;border:1px solid #f38ba844;border-radius:8px;padding:.6rem .8rem;font-size:.82rem;margin-bottom:1.2rem;text-align:center}
.success{background:#a6e3a122;color:#a6e3a1;border:1px solid #a6e3a144;border-radius:8px;padding:1rem;text-align:center;margin-bottom:1rem}
.success a{color:#89b4fa;font-weight:600}
.step{font-size:.82rem;color:#7f849c;background:#313244;border-radius:8px;padding:.8rem;margin-top:1.5rem;line-height:1.6}
.step code{background:#45475a;padding:.15rem .4rem;border-radius:4px;font-size:.8rem}
</style>
</head>
<body>
<div class="setup-card">
  <h1>OpenMind Setup</h1>
  <p class="subtitle">Configure your OpenMind installation</p>

<?php if ($setupSuccess): ?>
  <div class="success">
    <strong>Setup complete!</strong><br>
    Now create your first user via the command line:<br><br>
    <code>php setup/manage_users.php add admin</code><br><br>
    Then <a href="./">click here to log in</a>.
  </div>
<?php else: ?>
  <?= $err ?>
  <form method="POST" action="">
    <input type="hidden" name="action" value="setup">
    <div class="field">
      <label for="workspace_path">OpenClaw Workspace Path *</label>
      <input type="text" id="workspace_path" name="workspace_path" value="<?= htmlspecialchars($_POST['workspace_path'] ?? $defaults['workspace_path']) ?>" required>
      <div class="hint">Directory containing your .md workspace files</div>
    </div>
    <div class="field">
      <label for="app_title">App Title</label>
      <input type="text" id="app_title" name="app_title" value="<?= htmlspecialchars($_POST['app_title'] ?? $defaults['app_title']) ?>">
    </div>
    <button type="submit">Complete Setup</button>
  </form>
  <div class="step">
    <strong>After setup:</strong><br>
    Create your first user with:<br>
    <code>php setup/manage_users.php add admin</code>
  </div>
<?php endif; ?>
</div>
</body>
</html>
