import { gameDefs, createGame } from '../api.js';
import { getProfile, saveProfile, saveSession } from '../store.js';
import { esc, toast } from '../util.js';
import { profileFields, bindProfile } from '../avatar.js';
import { buildSetupForm } from './setup.js';
import { bindRulesLinks } from '../rules.js';

export async function renderCreate(app, type, navigate) {
  const def = (await gameDefs()).find((d) => d.id === type);
  if (!def) return navigate('');
  const profile = getProfile();

  app.innerHTML = `
    <form class="stack" data-form>
      <div class="row spread"><h1>${def.icon} New ${esc(def.title)} game</h1><span class="row"><a href="how-to-play" data-rules>Rules</a><button type="button" data-back>← Back</button></span></div>
      <section class="panel stack"><h2>You (the host)</h2>${profileFields(profile)}</section>
      <div data-setup></div>
      <p class="error" data-error></p>
      <button class="primary" data-submit>Create game</button>
    </form>`;

  bindRulesLinks(app);
  const getProfileValues = bindProfile(app, profile);
  const submit = app.querySelector('[data-submit]');
  const setup = buildSetupForm(def, { name: `${def.title} night`, description: '' }, () => { submit.disabled = !!setup.validate(); });
  app.querySelector('[data-setup]').appendChild(setup.el);
  submit.disabled = !!setup.validate();
  app.querySelector('[data-back]').addEventListener('click', () => navigate(''));

  app.querySelector('[data-form]').addEventListener('submit', async (e) => {
    e.preventDefault();
    const player = getProfileValues();
    const err = app.querySelector('[data-error]');
    err.textContent = '';
    try {
      saveProfile(player);
      const g = await createGame({ type, ...setup.values(), player });
      saveSession(g.id, { token: g.token, playerId: g.playerId, host: true });
      navigate(`?game=${g.id}`);
    } catch (ex) { err.textContent = ex.message; toast(ex.message); }
  });
}
