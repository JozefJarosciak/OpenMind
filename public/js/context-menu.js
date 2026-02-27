/**
 * OpenMind — Context Menu
 *
 * Right-click context menu for creating, renaming, and deleting files.
 * Depends on: jm, nodeDataMap, nodesContainer (from app.js)
 */

var ctxNodeId = null;
var ctxMenu = document.getElementById('ctx-menu');
var ctxRenameItem = document.getElementById('ctx-rename');
var ctxDeleteItem = document.getElementById('ctx-delete');
var ctxCreateItem = document.getElementById('ctx-create');
var ctxSepDelete = document.getElementById('ctx-sep-delete');

nodesContainer.addEventListener('contextmenu', function(e) {
  var nodeEl = e.target.closest('jmnode');
  if (!nodeEl) return;
  e.preventDefault();
  var nodeId = nodeEl.getAttribute('nodeid');
  var data = nodeDataMap[nodeId];
  var isFile = !!(data && data.file);
  var isRoot = nodeId === 'root';

  // Heading sub-nodes: no context menu
  if (!isFile && !isRoot) return;

  ctxCreateItem.classList.remove('ctx-hidden');
  ctxRenameItem.classList.toggle('ctx-hidden', !isFile);
  ctxDeleteItem.classList.toggle('ctx-hidden', !isFile);
  ctxSepDelete.classList.toggle('ctx-hidden', !isFile);

  ctxNodeId = nodeId;

  var mx = Math.min(e.clientX, window.innerWidth - 190);
  var my = Math.min(e.clientY, window.innerHeight - 60);
  ctxMenu.style.left = mx + 'px';
  ctxMenu.style.top = my + 'px';
  ctxMenu.classList.remove('hidden');
});

document.addEventListener('click', function() { ctxMenu.classList.add('hidden'); });
document.addEventListener('contextmenu', function(e) {
  if (!e.target.closest('jmnode') && !e.target.closest('#ctx-menu')) ctxMenu.classList.add('hidden');
});

// ── Rename ──────────────────────────────────────────────────────────────────
window.ctxRename = function() {
  ctxMenu.classList.add('hidden');
  if (!ctxNodeId) return;
  var data = nodeDataMap[ctxNodeId];
  if (!data || !data.file) return;

  var oldFile = data.file;
  var oldBasename = oldFile.split('/').pop().replace(/\.md$/i, '');
  var newName = prompt('Rename file (without .md):', oldBasename);
  if (!newName || newName === oldBasename) return;

  document.getElementById('status').textContent = 'Renaming\u2026';
  fetch(location.pathname, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'renameFile', oldFile: oldFile, newName: newName })
  })
  .then(function(res) { return res.json(); })
  .then(function(r) {
    if (r.success) {
      document.getElementById('status').textContent = '\u2713 Renamed to ' + (r.newFile || newName);
      setTimeout(function() { location.reload(); }, 800);
    } else {
      document.getElementById('status').textContent = '\u2717 ' + (r.error || 'Rename failed');
    }
  })
  .catch(function() {
    document.getElementById('status').textContent = '\u2717 Rename error';
  });
};

// ── Delete ──────────────────────────────────────────────────────────────────
window.ctxDelete = function() {
  ctxMenu.classList.add('hidden');
  if (!ctxNodeId) return;
  var data = nodeDataMap[ctxNodeId];
  if (!data || !data.file) return;

  var file = data.file;
  var basename = file.split('/').pop();
  var wsPath = (window.APP_CONFIG || {}).workspacePath || '';
  var ok = confirm(
    'Delete "' + basename + '"?\n\n' +
    'This will permanently remove this file from the workspace:\n' +
    '  ' + wsPath + '/' + file + '\n\n' +
    'A backup will be created before deletion.\n' +
    'This cannot be undone from the UI.'
  );
  if (!ok) return;

  document.getElementById('status').textContent = 'Deleting\u2026';
  fetch(location.pathname, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'deleteFile', file: file })
  })
  .then(function(res) { return res.json(); })
  .then(function(r) {
    if (r.success) {
      document.getElementById('status').textContent = '\u2713 Deleted ' + basename;
      setTimeout(function() { location.reload(); }, 800);
    } else {
      document.getElementById('status').textContent = '\u2717 ' + (r.error || 'Delete failed');
    }
  })
  .catch(function() {
    document.getElementById('status').textContent = '\u2717 Delete error';
  });
};

// ── Create ──────────────────────────────────────────────────────────────────
window.ctxCreate = function() {
  ctxMenu.classList.add('hidden');
  if (!ctxNodeId) return;
  var data = nodeDataMap[ctxNodeId];
  var isRoot = ctxNodeId === 'root';
  var isFile = !!(data && data.file);

  if (!isRoot && !isFile) return;

  // Determine target directory
  var directory = '';
  if (isFile) {
    var parts = data.file.split('/');
    if (parts.length > 1) {
      directory = parts.slice(0, -1).join('/');
    } else {
      directory = data.file.replace(/\.md$/i, '');
    }
  }

  var dirLabel = directory ? directory + '/' : '(workspace root)';
  var newName = prompt('Create new file in ' + dirLabel + '\n\nEnter filename (without .md):');
  if (!newName || !newName.trim()) return;

  document.getElementById('status').textContent = 'Creating\u2026';
  fetch(location.pathname, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'createFile', name: newName.trim(), directory: directory })
  })
  .then(function(res) { return res.json(); })
  .then(function(r) {
    if (r.success) {
      document.getElementById('status').textContent = '\u2713 Created ' + (r.file || newName);
      setTimeout(function() { location.reload(); }, 800);
    } else {
      document.getElementById('status').textContent = '\u2717 ' + (r.error || 'Create failed');
    }
  })
  .catch(function() {
    document.getElementById('status').textContent = '\u2717 Create error';
  });
};
