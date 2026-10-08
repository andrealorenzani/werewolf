export const esc = (s) => String(s ?? '').replace(/[&<>"']/g,
  (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

let toastTimer;
export function toast(msg) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 3500);
}

/** Set innerHTML only when it changed, so focus/scroll/selection survive re-renders. */
export function setHtml(el, html) {
  if (el && el.__html !== html) { el.innerHTML = html; el.__html = html; }
}

export const $ = (sel, root = document) => root.querySelector(sel);
