import { esc, toast } from './util.js';

/** Centre-crop a drawable (img/video) to a square and shrink it to a small JPEG data URL. */
export function canvasToAvatar(source, w, h, size = 96) {
  const side = Math.min(w, h);
  const c = document.createElement('canvas');
  c.width = c.height = size;
  c.getContext('2d').drawImage(source, (w - side) / 2, (h - side) / 2, side, side, 0, 0, size, size);
  return c.toDataURL('image/jpeg', 0.8);
}

/** Crop to a square and shrink to a small JPEG data URL (kept in localStorage, sent on join). */
export function fileToAvatar(file, size = 96) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      const data = canvasToAvatar(img, img.width, img.height, size);
      URL.revokeObjectURL(url);
      resolve(data);
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Could not read that image.')); };
    img.src = url;
  });
}

/** Fallback when the camera cannot be opened: the phone's camera app via <input capture>. */
function pickWithCaptureInput() {
  return new Promise((resolve) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.setAttribute('capture', 'user');
    input.addEventListener('change', async () => {
      const f = input.files[0];
      if (!f) return resolve(null);
      try { resolve(await fileToAvatar(f)); } catch (e) { toast(e.message); resolve(null); }
    });
    input.addEventListener('cancel', () => resolve(null));
    input.click();
  });
}

let closeOpenDialog = null;

/** Tear down the camera dialog (and release the camera) if one is open, e.g. when the room view is left. */
export function closePhotoDialog() { if (closeOpenDialog) closeOpenDialog(); }

/** Live camera preview in a dialog; resolves with a 96 px JPEG data URL, or null when cancelled. */
export async function takePhotoDialog() {
  closePhotoDialog();
  if (!navigator.mediaDevices?.getUserMedia) return pickWithCaptureInput();
  let stream;
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
  } catch { return pickWithCaptureInput(); }
  return new Promise((resolve) => {
    const overlay = document.createElement('div');
    overlay.className = 'modal';
    overlay.innerHTML = `<div class="panel stack modal-box" role="dialog" aria-label="Take a photo">
      <video class="cam" autoplay playsinline muted></video>
      <div class="row"><button class="primary" data-snap>Take photo</button><button data-cancel>Cancel</button></div></div>`;
    const video = overlay.querySelector('video');
    video.srcObject = stream;
    const done = (value) => {
      document.removeEventListener('keydown', onKey);
      if (closeOpenDialog === cancel) closeOpenDialog = null;
      stream.getTracks().forEach((t) => t.stop()); // always release the camera
      video.srcObject = null;
      overlay.remove();
      resolve(value);
    };
    const cancel = () => done(null);
    const onKey = (e) => { if (e.key === 'Escape') cancel(); };
    document.addEventListener('keydown', onKey);
    closeOpenDialog = cancel;
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay || e.target.closest('[data-cancel]')) done(null);
      else if (e.target.closest('[data-snap]')) {
        if (!video.videoWidth) return;
        done(canvasToAvatar(video, video.videoWidth, video.videoHeight));
      }
    });
    document.body.appendChild(overlay);
  });
}

export function avatarHtml(name, src, cls = '') {
  if (src) return `<img class="avatar ${cls}" src="${esc(src)}" alt="">`;
  return `<span class="avatar ${cls}" aria-hidden="true">${esc([...String(name || '?')][0]?.toUpperCase())}</span>`;
}

/** Profile form fragment (name + avatar picker); call bindProfile(root) after inserting it. */
export function profileFields(profile) {
  return `
    <div class="avatar-pick">
      <span data-avatar-preview>${avatarHtml(profile.name, profile.avatar, 'lg')}</span>
      <div class="grow">
        <label for="pname">Your name</label>
        <input id="pname" maxlength="20" value="${esc(profile.name)}" autocomplete="nickname">
        <label for="pfile" style="margin-top:8px">Avatar (optional)</label>
        <input id="pfile" type="file" accept="image/*">
        <div class="row" style="margin-top:8px"><button type="button" data-photo>Take photo</button>
          <button type="button" data-remove-photo>Remove photo</button></div>
      </div>
    </div>`;
}

/** Returns values(): {name, avatar}; values.avatarChanged() tells whether the avatar was touched. */
export function bindProfile(root, profile) {
  const state = { ...profile };
  let touched = false;
  const preview = root.querySelector('[data-avatar-preview]');
  const name = root.querySelector('#pname');
  const setAvatar = (a) => { state.avatar = a; touched = true; preview.innerHTML = avatarHtml(name.value, a, 'lg'); };
  name.addEventListener('input', () => {
    state.name = name.value;
    if (!state.avatar) preview.innerHTML = avatarHtml(state.name, null, 'lg');
  });
  root.querySelector('#pfile').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    try { setAvatar(await fileToAvatar(file)); } catch (err) { toast(err.message); }
  });
  root.querySelector('[data-photo]').addEventListener('click', async () => {
    const a = await takePhotoDialog();
    if (a) setAvatar(a);
  });
  root.querySelector('[data-remove-photo]').addEventListener('click', () => setAvatar(null));
  const values = () => ({ name: name.value.trim(), avatar: state.avatar ?? null });
  values.avatarChanged = () => touched;
  return values;
}
