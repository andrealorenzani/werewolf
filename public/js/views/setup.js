import { esc } from '../util.js';

const TIMERS = [
  { key: 'discussionSeconds', label: 'Limit discussion time', min: 15, max: 900, def: 120 },
  { key: 'voteSeconds', label: 'Limit vote time', min: 10, max: 300, def: 45 },
  { key: 'nightSeconds', label: 'Limit night action time', min: 15, max: 300, def: 45 },
];

/**
 * Game setup form shared by "create" and the host's lobby "Edit settings".
 * initial: {name, description, roles{id:n}, settings{...}}. Returns {el, values(), validate()};
 * validate() returns a problem text ('' when fine) and refreshes the summary. onChange() is called
 * after every edit.
 */
export function buildSetupForm(def, initial, onChange = () => {}) {
  const counts = Object.fromEntries(def.roles.map((r) => [r.id, initial.roles?.[r.id] ?? r.def]));
  const st = initial.settings ?? {};
  const el = document.createElement('div');
  el.className = 'stack';
  el.innerHTML = `
    <section class="panel stack">
      <h2>Game</h2>
      <div><label>Name</label><input data-name maxlength="40" required value="${esc(initial.name)}"></div>
      <div><label>Description</label><textarea data-desc maxlength="300" rows="2" placeholder="Optional">${esc(initial.description ?? '')}</textarea></div>
    </section>
    <section class="panel stack">
      <div class="row spread"><h2>Characters</h2><b data-total></b></div>
      <p class="muted small">Everyone gets one card at random. The game can start with fewer players than characters: the host chooses when.</p>
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
      <p class="muted small">Without a time limit a phase ends when everyone is done, or when the host skips it.</p>
      <div class="form-grid">${TIMERS.map((t) => `
        <div><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-limit="${t.key}" style="width:auto" ${st[t.key] > 0 ? 'checked' : ''}> ${t.label}</label>
          <input type="number" data-secs="${t.key}" min="${t.min}" max="${t.max}" value="${st[t.key] > 0 ? st[t.key] : t.def}" ${st[t.key] > 0 ? '' : 'disabled'} aria-label="${t.label} (seconds)"></div>`).join('')}
      </div>
      <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-reveal ${st.revealRoles === false ? '' : 'checked'} style="width:auto">
        Reveal the character of eliminated players</label>
    </section>`;

  const q = (s) => el.querySelector(s);
  const roleOf = (id) => def.roles.find((r) => r.id === id);

  function validate() {
    let total = 0, good = 0, evil = 0;
    for (const r of def.roles) {
      q(`[data-count="${r.id}"]`).textContent = counts[r.id];
      total += counts[r.id];
      if (r.team === 'evil') evil += counts[r.id]; else good += counts[r.id];
    }
    q('[data-total]').textContent = `${total} players`;
    const problem = total < 4 ? 'At least 4 players are needed.' : evil < 1 ? `Add at least one ${def.teams.evil.name}.`
      : good <= evil ? `${def.teams.good.name} must outnumber ${def.teams.evil.name}.` : '';
    q('[data-check]').textContent = problem || `${good} ${def.teams.good.name} vs ${evil} ${def.teams.evil.name}`;
    return problem;
  }

  el.addEventListener('click', (e) => {
    const b = e.target.closest('[data-inc],[data-dec]');
    if (!b) return;
    const inc = b.dataset.inc, dec = b.dataset.dec;
    if (inc) counts[inc] = Math.min(roleOf(inc).max, counts[inc] + 1);
    if (dec) counts[dec] = Math.max(roleOf(dec).min ?? 0, counts[dec] - 1);
    validate();
    onChange();
  });
  el.addEventListener('change', (e) => {
    const key = e.target.dataset.limit;
    if (key) q(`[data-secs="${key}"]`).disabled = !e.target.checked;
    onChange();
  });
  el.addEventListener('input', () => onChange());

  const values = () => ({
    name: q('[data-name]').value, description: q('[data-desc]').value, roles: { ...counts },
    settings: {
      ...Object.fromEntries(TIMERS.map((t) => [t.key, q(`[data-limit="${t.key}"]`).checked ? q(`[data-secs="${t.key}"]`).value : 0])),
      revealRoles: q('[data-reveal]').checked,
    },
  });
  validate();
  return { el, values, validate };
}
