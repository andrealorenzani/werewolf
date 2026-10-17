import { fetchRules } from './api.js';
import { setHtml, esc, toast } from './util.js';

let current = null; // {overlay, opener, onKey, prevOverflow}

/** Close the popup if open (also called when the screen changes). */
export function closeRules() {
  if (!current) return;
  const { overlay, opener, onKey, prevOverflow } = current;
  document.removeEventListener('keydown', onKey, true);
  overlay.remove();
  document.body.style.overflow = prevOverflow;
  current = null;
  if (opener?.isConnected) opener.focus();
}

/** Rules popup over the current screen (game, lobby, home). The overlay lives on document.body, outside any re-rendered region. */
export async function openRules(opener = null) {
  if (current) return;
  const overlay = document.createElement('div');
  overlay.className = 'dialog-overlay rules-overlay';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-labelledby', 'rules-title');
  overlay.innerHTML = `<div class="dialog-box rules-box stack" tabindex="-1">
    <div class="row spread"><h1 id="rules-title" style="margin:0;font-size:1.3rem">How to play</h1>
      <button type="button" class="link" data-rules-close aria-label="Close">✕</button></div>
    <div data-rules-body class="stack"><p class="muted">Loading…</p></div></div>`;
  const box = overlay.firstElementChild;
  const onKey = (e) => {
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeRules(); return; }
    if (e.key !== 'Tab') return;
    const f = [...box.querySelectorAll('a[href],button:not([disabled])')];
    if (!f.length) { e.preventDefault(); box.focus(); return; }
    const first = f[0], last = f[f.length - 1];
    if (!box.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
    else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  };
  current = { overlay, opener, onKey, prevOverflow: document.body.style.overflow };
  document.body.style.overflow = 'hidden';
  document.body.appendChild(overlay);
  document.addEventListener('keydown', onKey, true);
  overlay.addEventListener('click', (e) => { if (e.target === overlay || e.target.closest('[data-rules-close]')) closeRules(); });
  box.focus();
  const body = overlay.querySelector('[data-rules-body]');
  try {
    // trusted markup built and escaped by the server (Wm\Rules::html)
    setHtml(body, await fetchRules());
  } catch {
    setHtml(body, `<p>${esc('The rules could not be loaded.')} <a href="how-to-play" target="_blank" rel="noopener">Open the rules page</a>.</p>`);
    toast('Could not load the rules.');
  }
}

/** Turn real links (a[data-rules], href="how-to-play") into the popup; modified clicks keep the normal navigation. */
export function bindRulesLinks(root) {
  root.querySelectorAll('a[data-rules]').forEach((a) => a.addEventListener('click', (e) => {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
    e.preventDefault();
    openRules(a);
  }));
}
