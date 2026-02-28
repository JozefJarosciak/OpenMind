/**
 * OpenMind — Chat
 *
 * Chat panel for communicating with the OpenClaw agent.
 * Supports session persistence, markdown rendering, and typing indicators.
 */

var chatSessionId = localStorage.getItem('chatSessionId') || '';
if (!chatSessionId) {
  chatSessionId = 'xxxxxxxx-xxxx-4xxx'.replace(/[x]/g, function() {
    return (Math.random() * 16 | 0).toString(16);
  });
  localStorage.setItem('chatSessionId', chatSessionId);
}
var chatBusy = false;

// ── Markdown Renderer ───────────────────────────────────────────────────────
function renderMd(text) {
  var h = text
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/```(\w*)\n([\s\S]*?)```/g, function(_, lang, code) { return '<pre><code>' + code.trim() + '</code></pre>'; })
    .replace(/`([^`\n]+)`/g, '<code>$1</code>')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/^[-*]\s+(.+)$/gm, '<li>$1</li>')
    .replace(/^\d+\.\s+(.+)$/gm, '<li>$1</li>');
  h = h.replace(/((?:<li>.*?<\/li>\s*)+)/g, '<ul>$1</ul>');
  h = h.replace(/\n\n/g, '<br><br>');
  h = h.replace(/\n/g, '<br>');
  return h;
}

// ── Chat Bubble ─────────────────────────────────────────────────────────────
function addChatBubble(type, content, meta) {
  var msgs = document.getElementById('chat-messages');
  var welcome = msgs.querySelector('.chat-welcome');
  if (welcome) welcome.remove();

  var bubble = document.createElement('div');
  bubble.className = 'chat-bubble ' + type;
  if (type === 'user') {
    bubble.textContent = content;
  } else if (type === 'assistant') {
    bubble.innerHTML = renderMd(content);
    if (meta) {
      var metaEl = document.createElement('div');
      metaEl.className = 'chat-meta';
      var parts = [];
      if (meta.model) parts.push(meta.model);
      if (meta.durationMs) parts.push((meta.durationMs / 1000).toFixed(1) + 's');
      metaEl.textContent = parts.join(' \u00B7 ');
      bubble.appendChild(metaEl);
    }
  } else if (type === 'error') {
    bubble.textContent = content;
  }
  msgs.appendChild(bubble);
  msgs.scrollTop = msgs.scrollHeight;
  return bubble;
}

// ── Typing Indicator ────────────────────────────────────────────────────────
function showTyping() {
  var msgs = document.getElementById('chat-messages');
  var el = document.createElement('div');
  el.className = 'chat-typing';
  el.id = 'chat-typing-indicator';
  el.innerHTML = '<span></span><span></span><span></span>';
  msgs.appendChild(el);
  msgs.scrollTop = msgs.scrollHeight;
}

function hideTyping() {
  var el = document.getElementById('chat-typing-indicator');
  if (el) el.remove();
}

// ── Send Chat Message ───────────────────────────────────────────────────────
window.sendChat = function() {
  if (chatBusy) return;
  var input = document.getElementById('chat-input');
  var message = input.value.trim();
  if (!message) return;

  input.value = '';
  input.style.height = 'auto';
  chatBusy = true;
  document.getElementById('chat-send').disabled = true;

  addChatBubble('user', message);
  showTyping();

  fetch(location.pathname, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'chat', message: message, sessionId: chatSessionId })
  })
  .then(function(res) {
    if (res.status === 401) {
      throw new Error('Session expired. Please refresh the page and log in again.');
    }
    var ct = (res.headers.get('content-type') || '');
    if (ct.indexOf('application/json') === -1) {
      throw new Error('Server returned an unexpected response. Try refreshing the page.');
    }
    return res.json();
  })
  .then(function(r) {
    hideTyping();
    if (r.success) {
      if (r.sessionId) {
        chatSessionId = r.sessionId;
        localStorage.setItem('chatSessionId', chatSessionId);
      }
      addChatBubble('assistant', r.response, { model: r.model, durationMs: r.durationMs });
    } else {
      addChatBubble('error', r.error || 'Failed to get response');
    }
  })
  .catch(function(err) {
    hideTyping();
    addChatBubble('error', err.message);
  })
  .finally(function() {
    chatBusy = false;
    document.getElementById('chat-send').disabled = false;
    document.getElementById('chat-input').focus();
  });
};

// Enter to send, Shift+Enter for newline
document.getElementById('chat-input').addEventListener('keydown', function(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendChat();
  }
});

// Auto-resize textarea
document.getElementById('chat-input').addEventListener('input', function() {
  this.style.height = 'auto';
  this.style.height = Math.min(this.scrollHeight, (window.APP_CONFIG || {}).chatTextareaMaxHeight || 120) + 'px';
});
