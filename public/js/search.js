/**
 * OpenMind — Search
 *
 * Full-text search across all mindmap nodes with keyboard navigation.
 * Depends on: jm, nodeDataMap, raw (from app.js)
 */

// ── Build Search Index ──────────────────────────────────────────────────────
var searchIndex = [];
(function buildSearchIndex(node, ancestors) {
  var data = node.data || {};
  var topic = node.topic || '';
  var body = (data.body || '') + ' ' + (data.fullBody || '');
  var file = data.file || '';
  var ancestorIds = ancestors.map(function(a) { return a.id; });
  var pathStr = ancestors.map(function(a) { return a.topic; }).join(' > ');
  searchIndex.push({ id: node.id, topic: topic, body: body, file: file, path: pathStr, ancestors: ancestorIds });
  if (node.children) {
    node.children.forEach(function(child) {
      buildSearchIndex(child, ancestors.concat([{ id: node.id, topic: topic }]));
    });
  }
})(raw.data, []);

var searchInput = document.getElementById('search');
var searchResultsEl = document.getElementById('search-results');
var activeResultIdx = -1;
var currentMatches = [];

function escHtml(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function highlightMatch(text, q) {
  var lower = text.toLowerCase();
  var idx = lower.indexOf(q);
  if (idx === -1) return escHtml(text);
  return escHtml(text.slice(0, idx)) + '<span class="sr-match">' + escHtml(text.slice(idx, idx + q.length)) + '</span>' + escHtml(text.slice(idx + q.length));
}

function getSnippet(body, q) {
  var lower = body.toLowerCase();
  var idx = lower.indexOf(q);
  if (idx === -1) return '';
  var start = Math.max(0, idx - 30);
  var end = Math.min(body.length, idx + q.length + 50);
  var snippet = (start > 0 ? '...' : '') + body.slice(start, end).replace(/\n/g, ' ') + (end < body.length ? '...' : '');
  return highlightMatch(snippet, q);
}

var _sCfg = window.APP_CONFIG || {};
searchInput.addEventListener('input', function() {
  var q = searchInput.value.trim().toLowerCase();
  if (q.length < (_sCfg.searchMinChars || 2)) {
    searchResultsEl.classList.remove('visible');
    searchResultsEl.innerHTML = '';
    currentMatches = [];
    return;
  }

  currentMatches = searchIndex.filter(function(item) {
    return item.id !== 'root' && (
      item.topic.toLowerCase().includes(q) ||
      item.body.toLowerCase().includes(q) ||
      item.file.toLowerCase().includes(q)
    );
  }).slice(0, _sCfg.searchMaxResults || 25);

  if (currentMatches.length === 0) {
    searchResultsEl.innerHTML = '<div class="sr-empty">No results found</div>';
    searchResultsEl.classList.add('visible');
    activeResultIdx = -1;
    return;
  }

  activeResultIdx = -1;
  searchResultsEl.innerHTML = currentMatches.map(function(m, i) {
    var topicHl = highlightMatch(m.topic, q);
    var pathHtml = m.path ? '<div class="sr-path">' + escHtml(m.path) + '</div>' : '';
    var bodySnippet = m.body.toLowerCase().includes(q) ? '<div class="sr-body-snippet">' + getSnippet(m.body, q) + '</div>' : '';
    return '<div class="sr-item" data-idx="' + i + '" data-nodeid="' + m.id + '">' + topicHl + pathHtml + bodySnippet + '</div>';
  }).join('');
  searchResultsEl.classList.add('visible');
});

// Click on search result
searchResultsEl.addEventListener('click', function(e) {
  var item = e.target.closest('.sr-item[data-nodeid]');
  if (!item) return;
  navigateToNode(item.dataset.nodeid);
});

// Keyboard navigation
searchInput.addEventListener('keydown', function(e) {
  var items = searchResultsEl.querySelectorAll('.sr-item[data-nodeid]');
  if (!items.length && e.key !== 'Escape') return;

  if (e.key === 'ArrowDown') {
    e.preventDefault();
    activeResultIdx = Math.min(activeResultIdx + 1, items.length - 1);
    updateActiveResult(items);
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    activeResultIdx = Math.max(activeResultIdx - 1, 0);
    updateActiveResult(items);
  } else if (e.key === 'Enter') {
    e.preventDefault();
    if (activeResultIdx >= 0 && items[activeResultIdx]) {
      navigateToNode(items[activeResultIdx].dataset.nodeid);
    } else if (items.length > 0) {
      navigateToNode(items[0].dataset.nodeid);
    }
  } else if (e.key === 'Escape') {
    searchResultsEl.classList.remove('visible');
    searchInput.blur();
  }
});

function updateActiveResult(items) {
  items.forEach(function(el, i) { el.classList.toggle('active', i === activeResultIdx); });
  if (items[activeResultIdx]) items[activeResultIdx].scrollIntoView({ block: 'nearest' });
}

// Navigate to node: expand ancestors, select, scroll, flash, open panel
function navigateToNode(nodeId) {
  searchResultsEl.classList.remove('visible');
  searchInput.value = '';
  var entry = searchIndex.find(function(e) { return e.id === nodeId; });
  if (!entry) return;

  entry.ancestors.forEach(function(aid) {
    try { jm.expand_node(aid); } catch(e) {}
  });
  try { jm.select_node(nodeId); } catch(e) {}

  setTimeout(function() {
    var nodeEl = document.querySelector('jmnode[nodeid="' + nodeId + '"]');
    if (nodeEl) {
      nodeEl.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
      nodeEl.style.transition = 'outline .15s, outline-offset .15s';
      nodeEl.style.outline = '3px solid var(--accent, #89b4fa)';
      nodeEl.style.outlineOffset = '3px';
      setTimeout(function() { nodeEl.style.outline = ''; nodeEl.style.outlineOffset = ''; }, 2000);
    }
    var node = jm.get_node(nodeId);
    if (node) openPanel(node);
  }, 200);
}

// Close search when clicking outside
document.addEventListener('mousedown', function(e) {
  if (!e.target.closest('#search-wrap')) {
    searchResultsEl.classList.remove('visible');
  }
});
