import { getGame, joinGame } from '../api.js';
import { getProfile, saveProfile, saveSession } from '../store.js';
import { esc } from '../util.js';
import { profileFields, bindProfile } from '../avatar.js';

export async function renderJoin(app, id, navigate) {
  let game;
  try { game = await getGame(id); } catch (e) {
    app.innerHTML = `<div class="panel stack"><h1>Game not found</h1><p class="muted">${esc(e.message)}</p>
      <button data-home>Back</button></div>`;
    app.querySelector('[data-home]').addEventListener('click', () => navigate(''));
    return;
  }
  const profile = getProfile();
  app.innerHTML = `
    <form class="panel stack" data-form>
      <h1>Join “${esc(game.name)}”</h1>
      <p class="muted">${esc(game.description)} <br>Players: ${game.joined}/${game.total} · Code <b>${esc(game.id)}</b></p>
      ${profileFields(profile)}
      <p class="error" data-error></p>
      <div class="row"><button class="primary" ${game.joinable ? '' : 'disabled'}>Join game</button>
        <button type="button" data-home>Cancel</button></div>
      ${game.joinable ? (game.phase !== 'lobby' ? '<p class="muted small">The game has started, but you can still take a seat on day 0.</p>' : '') : '<p class="error">' + (game.phase === 'lobby' ? 'This game is full.' : 'This game has already started.') + '</p>'}
    </form>`;
  const values = bindProfile(app, profile);
  app.querySelector('[data-home]').addEventListener('click', () => navigate(''));
  app.querySelector('[data-form]').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const player = values();
      saveProfile(player);
      const s = await joinGame(id, player);
      saveSession(s.id, { token: s.token, playerId: s.playerId });
      navigate(`?game=${s.id}`);
    } catch (ex) { app.querySelector('[data-error]').textContent = ex.message; }
  });
}
