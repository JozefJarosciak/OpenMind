<?php
/**
 * OpenMind for OpenClaw
 *
 * Interactive mindmap workspace viewer for OpenClaw.
 * https://github.com/JozefJarosciak/OpenMind
 */

// ── Load Configuration ──────────────────────────────────────────────────────
require __DIR__ . '/includes/defaults.php';

if (!file_exists(__DIR__ . '/config.php')) {
    // First-run: show setup page
    include __DIR__ . '/includes/setup.php';
    exit;
}

$config = get_config();

// ── Security Headers ─────────────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ── Authentication & Network Restriction ────────────────────────────────────
$authDb = __DIR__ . '/auth.db';
require __DIR__ . '/includes/auth.php';

// If we reach here, user is authenticated

// ── Route API Requests ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $jsonInput = @json_decode($rawInput, true);
    $action = $_POST['action'] ?? ($jsonInput['action'] ?? '');

    // Login/logout already handled in auth.php
    if ($action === 'login' || $action === 'logout') {
        exit;
    }

    if ($action === 'saveSettings' || $action === 'applyUpdate') {
        require __DIR__ . '/includes/settings-api.php';
        exit;
    }

    // All other POST actions (save, rename, delete, create, changePassword)
    require __DIR__ . '/includes/api.php';
    exit;
}

// ── GET API Endpoints ───────────────────────────────────────────────────────
if (isset($_GET['file']) || isset($_GET['refreshFile'])) {
    require __DIR__ . '/includes/api.php';
    exit;
}

if (isset($_GET['getSettings']) || isset($_GET['checkUpdate'])) {
    require __DIR__ . '/includes/settings-api.php';
    exit;
}

// ── Build Workspace Tree & Render Page ──────────────────────────────────────
require __DIR__ . '/includes/workspace.php';
$mindData = buildWorkspaceTree($config);
$jsonData = json_encode($mindData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'JSON generation failed: ' . json_last_error_msg()]);
    exit;
}

$appTitle = htmlspecialchars(getDisplayTitle($config));
$userName = htmlspecialchars($_SESSION['user']);
$workspacePath = $config['workspace_path'];
?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $appTitle ?></title>
<link rel="stylesheet" href="https://unpkg.com/jsmind@0.9.1/style/jsmind.css">
<link rel="stylesheet" href="https://uicdn.toast.com/editor/latest/toastui-editor.min.css">
<link rel="stylesheet" href="https://uicdn.toast.com/editor/latest/theme/toastui-editor-dark.min.css">
<link rel="stylesheet" href="public/css/themes.css">
<link rel="stylesheet" href="public/css/main.css">
<link rel="stylesheet" href="public/css/designs.css">
<link rel="stylesheet" href="public/css/components.css">
</head>
<body data-theme="dark" data-design="outline">
<header>
  <h1><?= $appTitle ?></h1>
  <button id="btn-reload">&circlearrowleft; Reload</button>
  <button id="btn-expand">&boxplus; Expand All</button>
  <button id="btn-collapse">&boxminus; Collapse All</button>
  <button id="btn-theme">&#9728; Light</button>
  <select id="map-design" title="Design style">
    <option value="classic">Classic</option>
    <option value="outline">Outline</option>
    <option value="orb">Soft Orb</option>
    <option value="rounded">Rounded</option>
    <option value="neon">Neon</option>
  </select>
  <div id="search-wrap">
    <input id="search" type="text" placeholder="Search nodes..." autocomplete="off" spellcheck="false">
    <div id="search-results"></div>
  </div>
  <span id="status">Loading&hellip;</span>
  <button onclick="toggleSettings()">&#9881; Settings</button>
  <span style="font-size:.78rem;opacity:.55;white-space:nowrap"><?= $userName ?></span>
  <form method="POST" style="display:inline"><input type="hidden" name="action" value="logout"><button type="submit">Logout</button></form>
</header>

<div id="main">
  <div id="editor">
    <button id="btn-fit" title="Fit to screen">&#x26F6;</button>
  </div>
  <div id="panel" class="hidden">
    <div id="resize-handle"></div>
    <div id="panel-header">
      <span id="panel-title">&mdash;</span>
      <button onclick="cancelEdit()" style="padding:.2rem .5rem;margin-left:.4rem">&times;</button>
    </div>
    <div id="panel-file"></div>
    <div id="panel-body">
      <div id="panel-editor"></div>
    </div>
    <div id="panel-actions">
      <button id="btn-save-node" onclick="saveNodeContent()">&#128190; Save to file</button>
      <button onclick="cancelEdit()">Cancel</button>
    </div>
  </div>
</div>

<!-- Context Menu -->
<div id="ctx-menu" class="ctx-menu hidden">
  <div class="ctx-item" id="ctx-create" onclick="ctxCreate()">&#10010; New file here</div>
  <div class="ctx-item" id="ctx-rename" onclick="ctxRename()">&#9998; Rename file</div>
  <div class="ctx-sep" id="ctx-sep-delete"></div>
  <div class="ctx-item ctx-danger" id="ctx-delete" onclick="ctxDelete()">&#128465; Delete file</div>
</div>

<!-- Settings Modal -->
<div id="settings-modal" class="modal-overlay hidden">
  <div class="modal-card">
    <div class="modal-hdr">
      <h2>&#9881; Settings</h2>
      <button onclick="toggleSettings()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="settings-tabs">
        <button class="settings-tab active" data-stab="profile" onclick="switchSettingsTab('profile')">Profile</button>
        <button class="settings-tab" data-stab="workspace" onclick="switchSettingsTab('workspace')">Workspace</button>
        <button class="settings-tab" data-stab="security" onclick="switchSettingsTab('security')">Security</button>
        <button class="settings-tab" data-stab="app" onclick="switchSettingsTab('app')">App</button>
        <button class="settings-tab" data-stab="update" onclick="switchSettingsTab('update')">Update</button>
      </div>

      <div id="settings-msg"></div>

      <!-- Profile Tab -->
      <div id="stab-profile" class="settings-pane active">
        <h3>Change Password</h3>
        <div class="field">
          <label for="current-pw">Current Password</label>
          <input type="password" id="current-pw">
        </div>
        <div class="field">
          <label for="new-pw">New Password</label>
          <input type="password" id="new-pw">
        </div>
        <div class="field">
          <label for="confirm-pw">Confirm New Password</label>
          <input type="password" id="confirm-pw">
        </div>
        <div class="pw-req">
          <p>Password must have:</p>
          <ul>
            <li id="req-len">At least <?= $config['password_min_length'] ?> characters</li>
            <li id="req-upper">One uppercase letter</li>
            <li id="req-lower">One lowercase letter</li>
            <li id="req-num">One number</li>
            <li id="req-special">One special character</li>
          </ul>
        </div>
        <button class="modal-btn" onclick="changePassword()">Change Password</button>
      </div>

      <!-- Workspace Tab -->
      <div id="stab-workspace" class="settings-pane">
        <h3>Workspace Configuration</h3>
        <div class="field">
          <label for="set-workspace">Workspace Path</label>
          <input type="text" id="set-workspace" placeholder="/root/.openclaw/workspace">
          <div class="field-hint">Path to your OpenClaw workspace directory containing .md files</div>
        </div>
        <button class="modal-btn" onclick="saveSettings()">Save Workspace Settings</button>
      </div>

      <!-- Security Tab -->
      <div id="stab-security" class="settings-pane">
        <h3>Network Security</h3>
        <div class="field">
          <label for="set-network">Network Restriction</label>
          <select id="set-network">
            <option value="none">None (allow all)</option>
            <option value="tailscale">Tailscale only</option>
            <option value="custom">Custom IP ranges</option>
          </select>
        </div>
        <div class="field" id="ip-field" style="display:none">
          <label for="set-ips">Allowed IP Ranges (CIDRs)</label>
          <input type="text" id="set-ips" placeholder="192.168.1.0/24, 10.0.0.0/8">
          <div class="field-hint">Comma-separated CIDR ranges</div>
        </div>
        <button class="modal-btn" onclick="saveSettings()">Save Security Settings</button>
      </div>

      <!-- App Tab -->
      <div id="stab-app" class="settings-pane">
        <h3>Application Settings</h3>
        <div class="field">
          <label for="set-title">App Title</label>
          <input type="text" id="set-title" placeholder="OpenMind">
        </div>
        <div class="field">
          <label for="set-backup">Backup Path</label>
          <input type="text" id="set-backup" placeholder="./backups">
          <div class="field-hint">Where file backups are stored before edits/deletes</div>
        </div>
        <div class="field">
          <label for="set-session">Session Lifetime (seconds)</label>
          <input type="number" id="set-session" placeholder="86400" min="<?= $config['min_session_lifetime'] ?>">
          <div class="field-hint">How long login sessions last (default: 86400 = 24 hours)</div>
        </div>
        <button class="modal-btn" onclick="saveSettings()">Save App Settings</button>
      </div>

      <!-- Update Tab -->
      <div id="stab-update" class="settings-pane">
        <h3>Software Update</h3>
        <div id="update-info" class="update-info">
          <div class="update-row">
            <span class="update-label">Installed version:</span>
            <span id="update-local" class="update-val">—</span>
          </div>
          <div class="update-row">
            <span class="update-label">Latest version:</span>
            <span id="update-remote" class="update-val">—</span>
          </div>
          <div id="update-status" class="update-status"></div>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button class="modal-btn" onclick="checkForUpdates()">Check for Updates</button>
          <button class="modal-btn" id="btn-apply-update" onclick="applyUpdate()" style="display:none">Apply Update</button>
        </div>
        <div id="update-output" class="update-output" style="display:none"></div>
      </div>
    </div>
  </div>
</div>

<!-- Data & Scripts -->
<script>
window.APP_CONFIG = <?= json_encode([
  'appTitle'              => getDisplayTitle($config),
  'workspacePath'         => $config['workspace_path'],
  'passwordMinLength'     => $config['password_min_length'],
  'passwordRules'         => $config['password_rules'],
  'layoutHspace'          => $config['layout_hspace'],
  'layoutVspace'          => $config['layout_vspace'],
  'layoutPspace'          => $config['layout_pspace'],
  'viewHmargin'           => $config['view_hmargin'],
  'viewVmargin'           => $config['view_vmargin'],
  'viewLineWidth'         => $config['view_line_width'],
  'viewLineColor'         => $config['view_line_color'],
  'defaultDesign'         => $config['default_design'],
  'searchMinChars'        => $config['search_min_chars'],
  'searchMaxResults'      => $config['search_max_results'],
  'panelMinWidth'         => $config['panel_min_width'],
  'panelMaxRatio'         => $config['panel_max_ratio'],
], JSON_HEX_TAG) ?>;
</script>
<script id="mindmap-data" type="application/json"><?= $jsonData ?></script>
<script src="https://unpkg.com/jsmind@0.9.1/es6/jsmind.js"></script>
<script src="https://uicdn.toast.com/editor/latest/toastui-editor-all.min.js"></script>
<script src="public/js/app.js"></script>
<script src="public/js/search.js"></script>
<script src="public/js/context-menu.js"></script>
<script src="public/js/settings.js"></script>
</body>
</html>
