// Everything the browser remembers: the player's profile (name + avatar) and per-game sessions.
const read = (key, fallback) => {
  try { return JSON.parse(localStorage.getItem(key)) ?? fallback; } catch { return fallback; }
};
const write = (key, value) => {
  try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* storage unavailable */ }
};

export const getProfile = () => read('wm.profile', { name: '', avatar: null });
export const saveProfile = (profile) => write('wm.profile', profile);

export const getSessions = () => read('wm.sessions', {});
export const getSession = (gameId) => getSessions()[gameId] ?? null;
export function saveSession(gameId, session) {
  write('wm.sessions', { ...getSessions(), [gameId]: { ...session, savedAt: Date.now() } });
}
export function forgetSession(gameId) {
  const s = getSessions();
  delete s[gameId];
  write('wm.sessions', s);
}
