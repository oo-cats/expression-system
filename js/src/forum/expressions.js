import app from 'flarum/forum/app';

export const api = (path) => app.forum.attribute('apiUrl') + '/expression-system/' + path;
const escaped = new Set(Array.from('\\-!#()*+.:<>@[]^_{|}~' + String.fromCharCode(96)));

export function escapeText(text) {
  return Array.from(text).map((char) => escaped.has(char) ? '\\' + char : char).join('');
}

export function tokenFor(item, mode) {
  if (item.kind !== 'image') return item.kind === 'unicode' ? item.text : escapeText(item.text);
  const sticker = (mode || item.defaultMode) === 'sticker';
  return (sticker ? '\n\n[msticker=' : '[memoji=') + item.id + (sticker ? ']\n\n' : ']');
}

function recentKey() {
  return 'expression-system:' + app.forum.attribute('apiUrl') + ':' + app.session.user.id();
}

export function recentKeys() {
  try {
    const value = JSON.parse(localStorage.getItem(recentKey()) || '[]');
    return Array.isArray(value) ? value.filter((key) => /^(image|text|unicode|kaomoji):[a-f0-9]{32}$/.test(key)).slice(0, 30) : [];
  } catch (_) { return []; }
}

export function remember(item) {
  try { localStorage.setItem(recentKey(), JSON.stringify([item.key, ...recentKeys().filter((key) => key !== item.key)].slice(0, 30))); }
  catch (_) { /* Storage may be disabled; insertion still works. */ }
}

export function groupStickers(element) {
  if (!element) return;
  element.querySelectorAll('.MediaLibrary-sticker').forEach((node) => {
    if (node.parentElement.classList.contains('MediaLibrary-stickerGroup')) return;
    const group = document.createElement('span');
    group.className = 'MediaLibrary-stickerGroup';
    node.before(group);
    while (node && (node.nodeType === 1 && node.classList.contains('MediaLibrary-sticker') || node.nodeType === 3 && !node.textContent.trim())) {
      const next = node.nextSibling;
      group.append(node);
      node = next;
    }
  });
}
