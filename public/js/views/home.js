import { gameDefs, getGame } from '../api.js';
import { getSessions, forgetSession } from '../store.js';
import { esc } from '../util.js';

export async function renderHome(app, navigate) {
  const defs = await gameDefs();
  const sessions = Object.entries(getSessions());
  app.innerHTML = `
    <div class="stack">
      <header><h1>🌙 Werewolf &amp; Mafia</h1>
        <p class="muted">Social deduction games for your phone or browser. Pick a game, share the code, and play.</p></header>
      <section class="cards">
        ${defs.map((d) => `
          <button class="panel game-card" data-create="${d.id}">
            <div class="big">${d.icon}</div><h2>${esc(d.title)}</h2>
            <p class="muted">${esc(d.tagline)}</p>
            <span class="team-good">${esc(d.teams.good.name)}</span> vs <span class="team-evil">${esc(d.teams.evil.name)}</span>
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
        <button data-open="${esc(id)}">Rejoin</button></div>`);
    } catch { forgetSession(id); }
  }
  if (!box.children.length) box.closest('section').hidden = true;
  box.addEventListener('click', (e) => {
    const id = e.target.dataset.open;
    if (id) navigate(`?game=${id}`);
  });
}
