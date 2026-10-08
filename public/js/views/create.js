import { gameDefs, createGame } from '../api.js';
import { getProfile, saveProfile, saveSession } from '../store.js';
import { esc, toast } from '../util.js';
import { profileFields, bindProfile } from '../avatar.js';

export async function renderCreate(app, type, navigate) {
  const def = (await gameDefs()).find((d) => d.id === type);
  if (!def) return navigate('');
  const profile = getProfile();
  const counts = Object.fromEntries(def.roles.map((r) => [r.id, r.def]));

  app.innerHTML = `
    <form class="stack" data-form>
      <div class="row spread"><h1>${def.icon} New ${esc(def.title)} game</h1><button type="button" data-back>← Back</button></div>
      <section class="panel stack">
        <h2>Game</h2>
        <div><label for="gname">Name</label><input id="gname" maxlength="40" required value="${esc(def.title)} night"></div>
        <div><label for="gdesc">Description</label><textarea id="gdesc" maxlength="300" rows="2" placeholder="Optional"></textarea></div>
      </section>
      <section class="panel stack"><h2>You (the host)</h2>${profileFields(profile)}</section>
      <section class="panel stack">
        <div class="row spread"><h2>Characters</h2><b data-total></b></div>
        <p class="muted small">The number of players is the sum of all characters. Everyone gets one card at random.</p>
        <div class="role-grid">${def.roles.map((r) => `
          <div class="role-row">
            <span style="font-size:1.8rem">${r.icon}</span>
            <div class="info"><b class="team-${r.team}">${esc(r.name)}</b><span class="muted small">${esc(r.desc)}</span></div>
            <div class="stepper"><button type="button" data-dec="${r.id}" aria-label="Fewer ${esc(r.name)}">−</button>
              <output data-count="${r.id}"></output><button type="button" data-inc="${r.id}" aria-label="More ${esc(r.name)}">+</button></div>
          </div>`).join('')}</div>
        <div class="muted small" data-check></div>
      </section>
      <section class="panel stack">
        <h2>Timers &amp; options</h2>
        <div class="form-grid">
          <div><label for="s-d">Discussion (seconds)</label><input id="s-d" type="number" min="15" max="900" value="120"></div>
          <div><label for="s-v">Vote (seconds)</label><input id="s-v" type="number" min="10" max="300" value="45"></div>
          <div><label for="s-n">Night action (seconds)</label><input id="s-n" type="number" min="15" max="300" value="45"></div>
        </div>
        <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="s-r" checked style="width:auto">
          Reveal the character of eliminated players</label>
      </section>
      <p class="error" data-error></p>
      <button class="primary" data-submit>Create game</button>
    </form>`;

  const getProfileValues = bindProfile(app, profile);
  const roleOf = (id) => def.roles.find((r) => r.id === id);
  const refresh = () => {
    let total = 0, good = 0, evil = 0;
    for (const r of def.roles) {
      app.querySelector(`[data-count="${r.id}"]`).textContent = counts[r.id];
      total += counts[r.id];
      (r.team === 'evil' ? (evil += counts[r.id]) : (good += counts[r.id]));
    }
    app.querySelector('[data-total]').textContent = `${total} players`;
    const problem = total < 4 ? 'At least 4 players are needed.' : evil < 1 ? `Add at least one ${def.teams.evil.name}.`
      : good <= evil ? `${def.teams.good.name} must outnumber ${def.teams.evil.name}.` : '';
    app.querySelector('[data-check]').textContent = problem || `${good} ${def.teams.good.name} vs ${evil} ${def.teams.evil.name}`;
    app.querySelector('[data-submit]').disabled = !!problem;
  };
  app.addEventListener('click', (e) => {
    const inc = e.target.dataset.inc, dec = e.target.dataset.dec;
    if (inc) counts[inc] = Math.min(roleOf(inc).max, counts[inc] + 1);
    if (dec) counts[dec] = Math.max(roleOf(dec).min, counts[dec] - 1);
    if (inc || dec) refresh();
  });
  app.querySelector('[data-back]').addEventListener('click', () => navigate(''));
  refresh();

  app.querySelector('[data-form]').addEventListener('submit', async (e) => {
    e.preventDefault();
    const player = getProfileValues();
    const err = app.querySelector('[data-error]');
    err.textContent = '';
    try {
      saveProfile(player);
      const g = await createGame({
        type, name: app.querySelector('#gname').value, description: app.querySelector('#gdesc').value,
        roles: counts, player,
        settings: {
          discussionSeconds: app.querySelector('#s-d').value, voteSeconds: app.querySelector('#s-v').value,
          nightSeconds: app.querySelector('#s-n').value, revealRoles: app.querySelector('#s-r').checked,
        },
      });
      saveSession(g.id, { token: g.token, playerId: g.playerId });
      navigate(`?game=${g.id}`);
    } catch (ex) { err.textContent = ex.message; toast(ex.message); }
  });
}
