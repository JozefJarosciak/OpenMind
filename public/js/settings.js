/**
 * OpenMind — Settings Modal
 *
 * Handles the settings modal with tabs: Profile, OpenClaw, Security, App.
 * Profile tab includes password change with real-time validation.
 */

// ── Settings Modal Toggle ───────────────────────────────────────────────────
window.toggleSettings = function() {
  var m = document.getElementById('settings-modal');
  m.classList.toggle('hidden');
  if (!m.classList.contains('hidden')) {
    // Reset profile tab
    document.getElementById('current-pw').value = '';
    document.getElementById('new-pw').value = '';
    document.getElementById('confirm-pw').value = '';
    document.getElementById('settings-msg').className = '';
    document.getElementById('settings-msg').textContent = '';
    ['req-len', 'req-upper', 'req-lower', 'req-num', 'req-special'].forEach(function(id) {
      document.getElementById(id).className = '';
    });
    // Load current settings into form
    loadSettingsForm();
    // Switch to first tab
    switchSettingsTab('profile');
  }
};

// ── Settings Tab Switching ──────────────────────────────────────────────────
window.switchSettingsTab = function(tab) {
  document.querySelectorAll('.settings-tab').forEach(function(t) {
    t.classList.toggle('active', t.dataset.stab === tab);
  });
  document.querySelectorAll('.settings-pane').forEach(function(p) {
    p.classList.toggle('active', p.id === 'stab-' + tab);
  });
};

// ── Password Validation (config-driven) ─────────────────────────────────────
var _pwCfg = window.APP_CONFIG || {};
var _pwMinLen = _pwCfg.passwordMinLength || 8;
var _pwRules = _pwCfg.passwordRules || {
  uppercase: '/[A-Z]/', lowercase: '/[a-z]/',
  number: '/[0-9]/', special: '/[^A-Za-z0-9]/'
};
function _toRegex(phpRegex) {
  return new RegExp(phpRegex.replace(/^\/|\/$/g, ''));
}
var _pwRuleMap = {
  len:     function(pw) { return pw.length >= _pwMinLen; },
  upper:   function(pw) { return _toRegex(_pwRules.uppercase || '/[A-Z]/').test(pw); },
  lower:   function(pw) { return _toRegex(_pwRules.lowercase || '/[a-z]/').test(pw); },
  num:     function(pw) { return _toRegex(_pwRules.number || '/[0-9]/').test(pw); },
  special: function(pw) { return _toRegex(_pwRules.special || '/[^A-Za-z0-9]/').test(pw); }
};
document.getElementById('new-pw').addEventListener('input', function() {
  var pw = this.value;
  document.getElementById('req-len').className = _pwRuleMap.len(pw) ? 'met' : '';
  document.getElementById('req-upper').className = _pwRuleMap.upper(pw) ? 'met' : '';
  document.getElementById('req-lower').className = _pwRuleMap.lower(pw) ? 'met' : '';
  document.getElementById('req-num').className = _pwRuleMap.num(pw) ? 'met' : '';
  document.getElementById('req-special').className = _pwRuleMap.special(pw) ? 'met' : '';
});

// ── Change Password ─────────────────────────────────────────────────────────
window.changePassword = function() {
  var current = document.getElementById('current-pw').value;
  var newPw = document.getElementById('new-pw').value;
  var confirmPw = document.getElementById('confirm-pw').value;
  var msgEl = document.getElementById('settings-msg');

  if (!current) { msgEl.textContent = 'Enter your current password'; msgEl.className = 'msg-error'; return; }
  if (newPw !== confirmPw) { msgEl.textContent = 'New passwords do not match'; msgEl.className = 'msg-error'; return; }

  var errs = [];
  if (!_pwRuleMap.len(newPw)) errs.push(_pwMinLen + '+ chars');
  if (!_pwRuleMap.upper(newPw)) errs.push('uppercase');
  if (!_pwRuleMap.lower(newPw)) errs.push('lowercase');
  if (!_pwRuleMap.num(newPw)) errs.push('number');
  if (!_pwRuleMap.special(newPw)) errs.push('special char');
  if (errs.length) { msgEl.textContent = 'Still need: ' + errs.join(', '); msgEl.className = 'msg-error'; return; }

  msgEl.textContent = 'Changing\u2026'; msgEl.className = ''; msgEl.style.display = 'block';

  fetch(location.pathname, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'changePassword', currentPassword: current, newPassword: newPw })
  })
  .then(function(res) { return res.json(); })
  .then(function(r) {
    if (r.success) {
      msgEl.textContent = 'Password changed successfully!';
      msgEl.className = 'msg-success';
      document.getElementById('current-pw').value = '';
      document.getElementById('new-pw').value = '';
      document.getElementById('confirm-pw').value = '';
      ['req-len', 'req-upper', 'req-lower', 'req-num', 'req-special'].forEach(function(id) {
        document.getElementById(id).className = '';
      });
    } else {
      msgEl.textContent = r.error || 'Error changing password';
      msgEl.className = 'msg-error';
    }
  });
};

// ── Load Settings into Form ─────────────────────────────────────────────────
function loadSettingsForm() {
  fetch('?getSettings=1')
    .then(function(res) { return res.json(); })
    .then(function(r) {
      if (!r.success) return;
      var c = r.config;
      var f = function(id, val) { var el = document.getElementById(id); if (el) el.value = val || ''; };
      f('set-workspace', c.workspace_path);
      f('set-backup', c.backup_path);
      f('set-command', c.openclaw_command);
      f('set-agent', c.openclaw_agent);
      f('set-runas', c.openclaw_run_as);
      f('set-network', c.network_restriction);
      f('set-ips', c.allowed_ips);
      f('set-session', c.session_lifetime);
      f('set-title', c.app_title);
      // Toggle IP field visibility
      var ipField = document.getElementById('ip-field');
      if (ipField) ipField.style.display = c.network_restriction === 'custom' ? 'block' : 'none';
    })
    .catch(function() {});
}

// ── Save Settings ───────────────────────────────────────────────────────────
window.saveSettings = function() {
  var msgEl = document.getElementById('settings-msg');
  var settings = {
    workspace_path:      document.getElementById('set-workspace').value,
    backup_path:         document.getElementById('set-backup').value,
    openclaw_command:    document.getElementById('set-command').value,
    openclaw_agent:      document.getElementById('set-agent').value,
    openclaw_run_as:     document.getElementById('set-runas').value,
    network_restriction: document.getElementById('set-network').value,
    allowed_ips:         document.getElementById('set-ips').value,
    session_lifetime:    document.getElementById('set-session').value,
    app_title:           document.getElementById('set-title').value,
  };

  msgEl.textContent = 'Saving\u2026'; msgEl.className = ''; msgEl.style.display = 'block';

  fetch(location.pathname, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'saveSettings', settings: settings })
  })
  .then(function(res) { return res.json(); })
  .then(function(r) {
    if (r.success) {
      msgEl.textContent = 'Settings saved! Reload the page for changes to take effect.';
      msgEl.className = 'msg-success';
    } else {
      msgEl.textContent = r.error || 'Failed to save settings';
      msgEl.className = 'msg-error';
    }
  });
};

// ── Network restriction change handler ──────────────────────────────────────
document.getElementById('set-network').addEventListener('change', function() {
  var ipField = document.getElementById('ip-field');
  if (ipField) ipField.style.display = this.value === 'custom' ? 'block' : 'none';
});

// ── Close modal on overlay click / Escape ───────────────────────────────────
document.getElementById('settings-modal').addEventListener('click', function(e) {
  if (e.target === this) toggleSettings();
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && !document.getElementById('settings-modal').classList.contains('hidden')) toggleSettings();
});
