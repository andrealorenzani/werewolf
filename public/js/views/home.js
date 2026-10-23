import { gameDefs, getGame, deleteGame } from '../api.js';
import { getSessions, getSession, forgetSession } from '../store.js';
import { esc, toast } from '../util.js';
import { bindRulesLinks } from '../rules.js';

export async function renderHome(app, navigate) {
  const defs = await gameDefs();
  const sessions = Object.entries(getSessions());
  app.innerHTML = `
    <div class="stack">
      <header><h1>🌙 Werewolf, Mafia &amp; Impostor</h1>
        <p class="muted">Social deduction games for your phone or browser. Pick a game, share the code, and play.</p>
        <p><a href="how-to-play" data-rules>Rules and characters</a></p></header>
      <section class="cards">
        ${defs.map((d) => `
          <button class="panel game-card" data-create="${d.id}">
            <div class="big">${d.icon}</div><h2>${esc(d.title)}</h2>
            <p class="muted">${esc(d.tagline)}</p>
            ${d.engine === 'impostor' ? '<span class="team-evil">One Impostor</span> vs <span class="team-good">everyone else</span>'
              : `<span class="team-good">${esc(d.teams.good.name)}</span> vs <span class="team-evil">${esc(d.teams.evil.name)}</span>`}
          </button>`).join('')}
      </section>
      <section class="panel stack">
        <h2>Join a game</h2>
        <form class="row" data-join>
          <input class="grow" name="code" placeholder="Game code, e.g. K7M2QX" maxlength="6" autocapitalize="characters" required style="max-width:260px">
          <button class="primary">Join</button>
        </form>
      </section>
      <section class="panel stack" ${sessions.length ? '' : 'hidden'}>
        <h2>Your games</h2><div data-sessions></div>
      </section>
    </div>`;
  bindRulesLinks(app);
  app.querySelectorAll('[data-create]').forEach((b) =>
    b.addEventListener('click', () => navigate(`?create=${b.dataset.create}`)));
  app.querySelector('[data-join]').addEventListener('submit', (e) => {
    e.preventDefault();
    navigate(`?join=${new FormData(e.target).get('code').trim().toUpperCase()}`);
  });
  // list saved sessions, dropping the ones whose game no longer exists
  const box = app.querySelector('[data-sessions]');
  for (const [id] of sessions) {
    try {
      const g = await getGame(id);
      box.insertAdjacentHTML('beforeend', `<div class="row spread"><span><b>${esc(g.name)}</b>
        <span class="muted small">(${esc(id)} · ${esc(g.phase)})</span></span>
        <span class="row"><button data-open="${esc(id)}">Rejoin</button>
        <button class="danger" data-remove="${esc(id)}">Remove</button></span></div>`);
    } catch { forgetSession(id); }
  }
  if (!box.children.length) box.closest('section').hidden = true;
  box.addEventListener('click', async (e) => {
    const id = e.target.dataset.open;
    if (id) return navigate(`?game=${id}`);
    const rm = e.target.closest('[data-remove]')?.dataset.remove;
    if (!rm) return;
    const s = getSession(rm);
    const isHost = s?.host !== false; // sessions saved before the flag existed: try, the server decides
    const g = e.target.closest('.row.spread').querySelector('b')?.textContent ?? rm;
    if (!confirm(s?.host === undefined
      ? `Remove this game? If you are the host it is deleted for everyone; otherwise it is only removed from your list.`
      : isHost
        ? `Delete "${g}" for everyone? This removes the game for all players and cannot be undone.`
        : `Remove "${g}" from this device? You can rejoin later if you still have the code.`)) return;
    if (isHost && s) {
      try { await deleteGame(rm, s.token); } catch (ex) {
        // already gone, session expired or not the host: only forget it locally
        if (!/not found|expired|removed|Only the host/i.test(ex.message)) return toast(ex.message);
      }
    }
    forgetSession(rm);
    e.target.closest('.row.spread').remove();
    if (!box.children.length) box.closest('section').hidden = true;
  });
}
