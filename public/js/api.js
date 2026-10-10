async function call(action, body) {
  const res = await fetch(`api.php?a=${action}`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body ?? {}),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || 'Request failed.');
  return data;
}

let defsPromise;
export const gameDefs = () => (defsPromise ??= fetch('api.php?a=defs').then((r) => r.json()).then((d) => d.games));
export const createGame = (payload) => call('create', payload);
export const getGame = (id) => fetch(`api.php?a=info&id=${encodeURIComponent(id)}`)
  .then(async (r) => { const d = await r.json(); if (!r.ok) throw new Error(d.error); return d; });
export const joinGame = (id, player) => call('join', { id, player });
export const pollState = (id, token, v) => call('state', { id, token, v });
export const fetchAvatars = (id, token, ids) => call('avatars', { id, token, ids });
export const deleteGame = (id, token) => call('delete', { id, token });
export const sendCommand = (id, token, cmd) => call('cmd', { id, token, cmd });

/** Absolute link other players open (or scan) to join a game; works in subdirectories. */
export const joinUrl = (id) => new URL(`?join=${id}`, location.href.split(/[?#]/)[0]).href;

/** QR code (GIF data URL) for a link, drawn client-side by the vendored qrcode-generator. */
export function qrDataUrl(text) {
  const qr = window.qrcode(0, 'M');
  qr.addData(text);
  qr.make();
  return qr.createDataURL(6, 4);
}
