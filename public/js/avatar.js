import { esc } from './util.js';

/** Crop to a square and shrink to a small JPEG data URL (kept in localStorage, sent on join). */
export function fileToAvatar(file, size = 96) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      const side = Math.min(img.width, img.height);
      const c = document.createElement('canvas');
      c.width = c.height = size;
      c.getContext('2d').drawImage(img, (img.width - side) / 2, (img.height - side) / 2, side, side, 0, 0, size, size);
      URL.revokeObjectURL(url);
      resolve(c.toDataURL('image/jpeg', 0.8));
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Could not read that image.')); };
    img.src = url;
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
        <label for="pfile" style="margin-top:8px">Avatar (optional, stored in this browser)</label>
        <input id="pfile" type="file" accept="image/*">
      </div>
    </div>`;
}

export function bindProfile(root, profile) {
  const state = { ...profile };
  const preview = root.querySelector('[data-avatar-preview]');
  const name = root.querySelector('#pname');
  name.addEventListener('input', () => {
    state.name = name.value;
    if (!state.avatar) preview.innerHTML = avatarHtml(state.name, null, 'lg');
  });
  root.querySelector('#pfile').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    try {
      state.avatar = await fileToAvatar(file);
      preview.innerHTML = avatarHtml(state.name, state.avatar, 'lg');
    } catch (err) { import('./util.js').then((u) => u.toast(err.message)); }
  });
  return () => ({ name: name.value.trim(), avatar: state.avatar });
}
