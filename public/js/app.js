/**
 * OpenMind — Core Application
 *
 * Initializes jsMind, builds nodeDataMap, handles node clicks,
 * manages the editor panel, themes, and design styles.
 */

// ── Parse mindmap data ──────────────────────────────────────────────────────
var raw       = JSON.parse(document.getElementById('mindmap-data').textContent);
var fileCount = raw.data.children.length;

// Build lookup map of node data (jsMind may not preserve custom fields)
var nodeDataMap = {};
(function buildMap(node) {
  if (node.id) nodeDataMap[node.id] = node.data || {};
  if (node.children) node.children.forEach(buildMap);
})(raw.data);

// ── TUI Markdown Editor (lazy init) ─────────────────────────────────────────
var tuiEditor = null;
function resetEditorScroll() {
  if (!tuiEditor) return;
  function scrollAllToTop() {
    // Scroll every possible container to the top
    ['#panel-editor .toastui-editor-ww-container',
     '#panel-editor .ProseMirror',
     '#panel-editor .toastui-editor-md-container .toastui-editor',
     '#panel-body'
    ].forEach(function(sel) {
      var el = document.querySelector(sel);
      if (el) el.scrollTop = 0;
    });
  }
  // 1. Move cursor to start of document using editor API
  try {
    tuiEditor.moveCursorToStart();
  } catch(e) {
    // Fallback: collapse selection to beginning of ProseMirror
    try {
      var pm = document.querySelector('#panel-editor .ProseMirror');
      if (pm && pm.firstChild) {
        var range = document.createRange();
        range.setStart(pm.firstChild, 0);
        range.collapse(true);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
      }
    } catch(e2) {}
  }
  // 2. Blur so the browser doesn't scroll to follow the cursor
  tuiEditor.blur();
  // 3. Aggressively reset scroll at increasing intervals
  scrollAllToTop();
  setTimeout(scrollAllToTop, 30);
  setTimeout(scrollAllToTop, 100);
  setTimeout(scrollAllToTop, 250);
  setTimeout(scrollAllToTop, 500);
}
function ensureEditor(cb) {
  if (tuiEditor) { cb(); return; }
  requestAnimationFrame(function() {
    tuiEditor = new toastui.Editor({
      el: document.getElementById('panel-editor'),
      height: '100%',
      initialEditType: 'wysiwyg',
      previewStyle: 'tab',
      initialValue: '',
      theme: document.body.dataset.theme === 'dark' ? 'dark' : undefined,
      usageStatistics: false,
      toolbarItems: [
        ['heading', 'bold', 'italic', 'strike'],
        ['hr', 'quote'],
        ['ul', 'ol', 'task'],
        ['table', 'link'],
        ['code', 'codeblock']
      ]
    });
    cb();
  });
}

// ── jsMind Initialization ───────────────────────────────────────────────────
var _cfg = window.APP_CONFIG || {};
var editorEl = document.getElementById('editor');
var options = {
  container: editorEl.id,
  editable: false,
  theme: 'asphalt',
  layout: {
    hspace: _cfg.layoutHspace || 200,
    vspace: _cfg.layoutVspace || 50,
    pspace: _cfg.layoutPspace || 20
  },
  view: {
    engine: 'canvas',
    hmargin: _cfg.viewHmargin || 100,
    vmargin: _cfg.viewVmargin || 50,
    line_width: _cfg.viewLineWidth || 3,
    line_color: _cfg.viewLineColor || '#555',
    draggable: true,
    node_overflow: 'wrap',
    expander_style: 'char'
  },
  shortcut: { enable: false }
};
var jm = new jsMind(options);
jm.show(raw);
document.getElementById('status').textContent = fileCount + ' MD files \u2022 click node to edit';

// ── Design Styles ───────────────────────────────────────────────────────────
function applyDesignStyles() {
  document.querySelectorAll('jmnode').forEach(function(el) {
    var nodeId = el.getAttribute('nodeid');
    var data = nodeDataMap[nodeId];
    if (data && data['branch-color']) {
      el.style.setProperty('--bc', data['branch-color']);
    } else if (nodeId === 'root') {
      el.style.setProperty('--bc', '#6366f1');
    }
  });
}
var _designObserver = new MutationObserver(function() { applyDesignStyles(); });
_designObserver.observe(document.getElementById('editor'), { childList: true, subtree: true });
applyDesignStyles();

// ── Node Click Handling ─────────────────────────────────────────────────────
var clickStart = 0, startX = 0, startY = 0;
var clickedNodeEl = null;
var nodesContainer = jm.view.e_nodes;

nodesContainer.addEventListener('mousedown', function(e) {
  if (e.button !== 0) return;
  var nodeEl = e.target.closest('jmnode');
  if (nodeEl) {
    clickStart = Date.now();
    startX = e.clientX;
    startY = e.clientY;
    clickedNodeEl = nodeEl;
    e.stopPropagation();
  }
}, true);

document.addEventListener('mouseup', function(e) {
  if (!clickedNodeEl) return;
  var nodeEl = clickedNodeEl;
  clickedNodeEl = null;
  var nodeid = nodeEl.getAttribute('nodeid');
  var node = jm.get_node(nodeid);
  if (!node) return;
  var dt = Date.now() - clickStart;
  var dx = Math.abs(e.clientX - startX);
  var dy = Math.abs(e.clientY - startY);
  if (dt < 300 && dx < 10 && dy < 10) {
    if (node.children && node.children.length > 0 && node.id !== 'root') {
      jm.toggle_node(node.id);
    }
    openPanel(node);
  }
});

// ── Refresh Branch ──────────────────────────────────────────────────────────
function refreshBranch(fileNodeId, file) {
  fetch('?refreshFile=' + encodeURIComponent(file))
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (!data.success || !data.tree) return;
      var node = jm.get_node(fileNodeId);
      if (!node) return;
      var oldChildren = [].concat(node.children || []);
      oldChildren.forEach(function(child) {
        try { jm.remove_node(child.id); } catch(e) {}
      });
      function removeFromMap(n) {
        delete nodeDataMap[n.id];
        if (n.children) n.children.forEach(removeFromMap);
      }
      oldChildren.forEach(removeFromMap);
      if (data.tree.data) {
        nodeDataMap[fileNodeId] = Object.assign(nodeDataMap[fileNodeId] || {}, data.tree.data);
      }
      var branchColor = (nodeDataMap[fileNodeId] || {})['branch-color'] || (nodeDataMap[fileNodeId] || {})['leading-line-color'] || '#555';
      function addChildren(parentId, children) {
        if (!children) return;
        children.forEach(function(child) {
          var childData = child.data || {};
          childData['leading-line-color'] = branchColor;
          childData['branch-color'] = branchColor;
          nodeDataMap[child.id] = childData;
          try {
            jm.add_node(jm.get_node(parentId), child.id, child.topic, childData);
          } catch(e) { console.warn('add_node failed:', child.id, e); }
          if (child.children && child.children.length > 0) {
            addChildren(child.id, child.children);
          }
        });
      }
      addChildren(fileNodeId, data.tree.children || []);
      applyDesignStyles();
    })
    .catch(function(err) { console.warn('Branch refresh failed:', err); });
}

// ── Open Panel ──────────────────────────────────────────────────────────────
var panelOriginalContent = '';
var workspacePath = (window.APP_CONFIG || {}).workspacePath || '';

function openPanel(node) {
  var data = nodeDataMap[node.id] || node.data || {};
  var topic = node.topic || node.id;
  document.getElementById('panel-title').textContent = topic;
  document.getElementById('panel').classList.remove('hidden');

  ensureEditor(function() {
    if (node.id === 'root') {
      document.getElementById('panel-file').textContent = '\uD83D\uDCC2 ' + workspacePath + '/';
      document.getElementById('panel-editor').dataset.file = '';
      var appTitle = (window.APP_CONFIG || {}).appTitle || 'OpenMind';
      var summary = [
        '# ' + appTitle,
        '',
        'This is the root of the knowledge base.',
        '',
        '**' + fileCount + ' files** loaded into this mind map.',
        '',
        '## Files',
      ];
      raw.data.children.forEach(function(ch) {
        var sections = (ch.children ? ch.children.length : 0);
        var fileLabel = ch.data && ch.data.file ? ' `' + ch.data.file + '`' : '';
        summary.push('- **' + ch.topic + '** \u2014 ' + sections + ' sections' + fileLabel);
      });
      summary.push('', '## Location', '`' + workspacePath + '/`');
      var text = summary.join('\n');
      tuiEditor.setMarkdown(text);
      panelOriginalContent = text;
      resetEditorScroll();
      return;
    }

    var file = data.file || '';
    document.getElementById('panel-file').textContent = file
      ? '\uD83D\uDCC2 ' + workspacePath + '/' + file
      : '';
    document.getElementById('panel-editor').dataset.file = file;
    if (file) {
      tuiEditor.setMarkdown('Loading\u2026');
      fetch('?file=' + encodeURIComponent(file))
        .then(function(res) { return res.json(); })
        .then(function(d) {
          tuiEditor.setMarkdown(d.content || '');
          panelOriginalContent = d.content || '';
          resetEditorScroll();
        });
    } else {
      var content = data.fullBody || data.body || '';
      tuiEditor.setMarkdown(content);
      panelOriginalContent = content;
      resetEditorScroll();
    }
  });
}

// ── Fit to Screen ──────────────────────────────────────────────────────────
document.getElementById('btn-fit').onclick = function() {
  var container = document.getElementById('editor');
  var panel = jm.view.e_panel;
  var nodes = panel.querySelectorAll('jmnode');
  if (!nodes.length) return;

  // Reset zoom to 1 for accurate measurement
  jm.view.actualZoom = 1;
  panel.style.zoom = 1;

  requestAnimationFrame(function() {
    var containerRect = container.getBoundingClientRect();
    var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;

    nodes.forEach(function(n) {
      if (n.style.display === 'none' || n.offsetWidth === 0) return;
      var rect = n.getBoundingClientRect();
      var x = rect.left - containerRect.left + container.scrollLeft;
      var y = rect.top - containerRect.top + container.scrollTop;
      minX = Math.min(minX, x);
      minY = Math.min(minY, y);
      maxX = Math.max(maxX, x + rect.width);
      maxY = Math.max(maxY, y + rect.height);
    });

    if (minX === Infinity) return;

    var contentW = maxX - minX;
    var contentH = maxY - minY;
    var viewW = container.clientWidth;
    var viewH = container.clientHeight;
    var padding = 60;
    var zoom = Math.min(
      (viewW - padding) / contentW,
      (viewH - padding) / contentH
    );
    zoom = Math.min(zoom, 1.5);
    zoom = Math.max(zoom, 0.1);
    zoom = Math.round(zoom * 100) / 100;

    jm.view.actualZoom = zoom;
    panel.style.zoom = zoom;

    // Center content after zoom
    requestAnimationFrame(function() {
      var cx = ((minX + maxX) / 2) * zoom;
      var cy = ((minY + maxY) / 2) * zoom;
      container.scrollLeft = cx - viewW / 2;
      container.scrollTop = cy - viewH / 2;
    });
  });
};

// ── Reload / Expand / Collapse ──────────────────────────────────────────────
document.getElementById('btn-reload').onclick = function() {
  document.getElementById('status').textContent = 'Reloading\u2026';
  // Re-detect bot name (Telegram API), then full page reload to pick up all changes
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: 'refreshTitle'})
  }).catch(function(){}).finally(function() {
    location.reload();
  });
};
document.getElementById('btn-expand').onclick = function() {
  jm.expand_all();
  jm.view.relayout();
};
document.getElementById('btn-collapse').onclick = function() {
  jm.collapse_all();
  jm.view.relayout();
};

// ── Theme Toggle ────────────────────────────────────────────────────────────
function applyDarkLight(light) {
  document.body.dataset.theme = light ? 'light' : 'dark';
  document.getElementById('btn-theme').textContent = light ? '\uD83C\uDF19 Dark' : '\u2600 Light';
  localStorage.setItem('mapTheme', light ? 'light' : 'dark');
  if (tuiEditor) {
    var uiEl = document.querySelector('.toastui-editor-defaultUI');
    if (uiEl) uiEl.classList.toggle('toastui-editor-dark', !light);
  }
}
function applyDesign(design) {
  document.body.dataset.design = design;
  document.getElementById('map-design').value = design;
  localStorage.setItem('mapDesign', design);
  applyDesignStyles();
}
document.getElementById('map-design').onchange = function(e) {
  applyDesign(e.target.value);
};
var savedDesign = localStorage.getItem('mapDesign') || _cfg.defaultDesign || 'outline';
applyDesign(savedDesign);
var savedTheme = localStorage.getItem('mapTheme');
if (savedTheme === 'light') applyDarkLight(true);
document.getElementById('btn-theme').onclick = function() {
  applyDarkLight(document.body.dataset.theme !== 'light');
};

// ── Auto-select root on load ────────────────────────────────────────────────
setTimeout(function() {
  var rootNode = jm.get_node('root');
  if (rootNode) {
    jm.select_node('root');
    openPanel(rootNode);
  }
}, 300);

// ── Save / Cancel ───────────────────────────────────────────────────────────
window.saveNodeContent = function() {
  var file = document.getElementById('panel-editor').dataset.file;
  var content = tuiEditor ? tuiEditor.getMarkdown() : '';
  if (!file) {
    alert('No file associated with this node.');
    return;
  }
  document.getElementById('status').textContent = 'Saving\u2026';
  fetch(location.pathname, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'saveNode', file: file, content: content })
  })
  .then(function(res) { return res.json(); })
  .then(function(r) {
    document.getElementById('status').textContent = r.success ? '\u2713 Saved ' + file : '\u2717 Error: ' + (r.error || 'Unknown');
    if (r.success) panelOriginalContent = content;
  });
};

window.cancelEdit = function() {
  if (tuiEditor) tuiEditor.setMarkdown(panelOriginalContent);
  document.getElementById('status').textContent = 'Changes reverted';
};

// ── Resizable Panel ─────────────────────────────────────────────────────────
(function() {
  var handle = document.getElementById('resize-handle');
  var panel  = document.getElementById('panel');
  var resizing = false;
  var rStartX = 0, rStartW = 0;

  handle.addEventListener('mousedown', function(e) {
    resizing = true;
    rStartX = e.clientX;
    rStartW = panel.offsetWidth;
    handle.classList.add('dragging');
    document.body.style.userSelect = 'none';
    document.body.style.cursor = 'col-resize';

    function onMove(ee) {
      if (!resizing) return;
      var delta = rStartX - ee.clientX;
      var newW = Math.min(Math.max(rStartW + delta, _cfg.panelMinWidth || 220), window.innerWidth * (_cfg.panelMaxRatio || 0.8));
      panel.style.width = newW + 'px';
    }
    function onUp() {
      if (!resizing) return;
      resizing = false;
      localStorage.setItem('panelWidth', panel.style.width);
      handle.classList.remove('dragging');
      document.body.style.userSelect = '';
      document.body.style.cursor = '';
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
    }
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  });

  var savedWidth = localStorage.getItem('panelWidth');
  if (savedWidth) panel.style.width = savedWidth;
})();

