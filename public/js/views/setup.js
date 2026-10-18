import { esc } from '../util.js';

// Optional timers (checkbox). The night timer is mandatory: see NIGHT.
const TIMERS = [
  { key: 'discussionSeconds', label: 'Limit discussion time', min: 15, max: 900, def: 120 },
  { key: 'voteSeconds', label: 'Limit vote time', min: 10, max: 300, def: 45 },
];
const NIGHT = { key: 'nightSeconds', label: 'Night action time per step (seconds)', min: 30, max: 90, def: 60 };

/** Highest allowed count of a role; mirrors Config::capFor in PHP (the server is the authority). */
export function capFor(def, role, counts) {
  let cap = role.max;
  if (role.cap?.nonEvil) {
    const nonEvil = def.roles.filter((r) => r.team !== 'evil' && !r.legacy).reduce((n, r) => n + (counts[r.id] ?? 0), 0);
    cap = Math.min(cap, Math.ceil(nonEvil / role.cap.nonEvil));
  } else if (role.cap?.count) {
    cap = Math.min(cap, counts[role.cap.count] ?? 0);
  }
  return cap;
}

/** Why a role is limited, for the hint under its counter. */
function capReason(def, role, cap) {
  if (role.cap?.nonEvil) return `one per ${role.cap.nonEvil} players that are not ${def.teams.evil.name}`;
  if (role.cap?.count) return `no more than the number of ${def.roles.find((r) => r.id === role.cap.count)?.name ?? role.cap.count}`;
  return `at most ${cap}`;
}

/** Human-readable lines for the game's settings (timers, reveal, chat in the story). */
export function settingsLines(st, ghosts = null) {
  const time = (s) => (s > 0 ? `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}` : 'no limit');
  return [
    `Discussion: ${time(st.discussionSeconds)}`, `Vote: ${time(st.voteSeconds)}`, `Night: ${time(st.nightSeconds || NIGHT.def)} per step`,
    ...(ghosts == null ? [] : [`Ghosts: ${ghosts}`]),
    `Reveal character on death: ${st.revealRoles === false ? 'no' : 'yes'}`,
    `Public chat in the final story: ${st.storyPublicChat ? 'yes' : 'no'}`,
    `Private chat in the final story: ${st.storyPrivateChat ? 'yes' : 'no'}`,
  ];
}

/**
 * Game setup form shared by "create" and the host's lobby "Edit settings".
 * initial: {name, description, roles{id:n}, ghosts, settings{...}}. Returns {el, values(), validate()};
 * validate() returns a problem text ('' when fine) and refreshes the summary. onChange() is called
 * after every edit.
 */
export function buildSetupForm(def, initial, onChange = () => {}) {
  const roles = def.roles.filter((r) => !r.legacy); // legacy cards (the old Ghost) cannot be picked any more
  const counts = Object.fromEntries(roles.map((r) => [r.id, initial.roles?.[r.id] ?? r.def]));
  const st = initial.settings ?? {};
  const gh = def.ghost; // secret trait, not a card: 0..number of werewolves, not counted as players
  let ghosts = gh ? Math.max(0, initial.ghosts ?? gh.def ?? 0) : 0;
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
      <div class="role-grid">${roles.map((r) => `
        <div class="role-row">
          <span style="font-size:1.8rem">${r.icon}</span>
          <div class="info"><b class="team-${r.allegiance === 'evil' ? 'evil' : r.team}">${esc(r.name)}</b><span class="muted small">${esc(r.desc)}</span>
            <span class="small cap-note" data-cap-note="${r.id}"></span></div>
          <div class="stepper"><button type="button" data-dec="${r.id}" aria-label="Fewer ${esc(r.name)}">−</button>
            <output data-count="${r.id}"></output><button type="button" data-inc="${r.id}" aria-label="More ${esc(r.name)}">+</button></div>
        </div>`).join('')}</div>
      ${gh ? `<div class="role-row" data-ghost-row>
          <span style="font-size:1.8rem">${gh.icon}</span>
          <div class="info"><b class="ghost-name">${esc(gh.name)}</b><span class="muted small">${esc(gh.desc)}</span>
            <span class="muted small">Not extra players: one of the non-${esc((def.roles.find((r) => r.id === gh.cap?.count)?.name ?? def.teams.evil.name).toLowerCase())} players secretly becomes a ghost.</span>
            <span class="small cap-note" data-ghost-note></span></div>
          <div class="stepper"><button type="button" data-ghost-dec aria-label="Fewer ${esc(gh.name)}">−</button>
            <output data-ghost-count></output><button type="button" data-ghost-inc aria-label="More ${esc(gh.name)}">+</button></div>
        </div>` : ''}
      <div class="muted small" data-check></div>
    </section>
    <section class="panel stack">
      <h2>Timers &amp; options</h2>
      <p class="muted small">Without a time limit a phase ends when everyone is done, or when the host skips it. The night always has a time limit per step.</p>
      <div class="form-grid">
        <div><label for="night-secs">${NIGHT.label} (${NIGHT.min}-${NIGHT.max})</label>
          <input id="night-secs" type="number" data-secs="${NIGHT.key}" min="${NIGHT.min}" max="${NIGHT.max}" value="${st.nightSeconds > 0 ? st.nightSeconds : NIGHT.def}"></div>
        ${TIMERS.map((t) => `
        <div><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-limit="${t.key}" style="width:auto" ${st[t.key] > 0 ? 'checked' : ''}> ${t.label}</label>
          <input type="number" data-secs="${t.key}" min="${t.min}" max="${t.max}" value="${st[t.key] > 0 ? st[t.key] : t.def}" ${st[t.key] > 0 ? '' : 'disabled'} aria-label="${t.label} (seconds)"></div>`).join('')}
      </div>
      <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-reveal ${st.revealRoles === false ? '' : 'checked'} style="width:auto">
        Reveal the character of eliminated players</label>
      <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-story-public ${st.storyPublicChat ? 'checked' : ''} style="width:auto">
        Include the public chat in the final story</label>
      <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-story-private ${st.storyPrivateChat ? 'checked' : ''} style="width:auto">
        Include private chat (private messages, wolf night chat, graveyard) in the final story</label>
    </section>`;

  const q = (s) => el.querySelector(s);
  const roleOf = (id) => def.roles.find((r) => r.id === id);

  function validate() {
    let total = 0, good = 0, evil = 0, neutral = 0;
    const over = [];
    for (const r of roles) {
      const cap = capFor(def, r, counts);
      q(`[data-count="${r.id}"]`).textContent = counts[r.id];
      total += counts[r.id];
      if (r.team === 'evil') evil += counts[r.id]; else if (r.team === 'neutral') neutral += counts[r.id]; else good += counts[r.id];
      const atCap = counts[r.id] >= cap;
      q(`[data-inc="${r.id}"]`).disabled = atCap;
      q(`[data-dec="${r.id}"]`).disabled = counts[r.id] <= (r.min ?? 0);
      const limited = !!r.cap || cap < r.max;
      q(`[data-cap-note="${r.id}"]`).textContent = limited && (atCap || counts[r.id] > cap)
        ? `Limit reached: ${cap} (${capReason(def, r, cap)}).` : '';
      if (counts[r.id] > cap) over.push(`${r.name}: at most ${cap} (${capReason(def, r, cap)})`);
      else if (counts[r.id] < (r.min ?? 0)) over.push(`At least ${r.min} ${r.name} needed`);
    }
    if (gh) {
      const gcap = capFor(def, gh, counts);
      if (ghosts > gcap) ghosts = gcap; // werewolves were lowered
      q('[data-ghost-count]').textContent = ghosts;
      q('[data-ghost-inc]').disabled = ghosts >= gcap;
      q('[data-ghost-dec]').disabled = ghosts <= (gh.min ?? 0);
      q('[data-ghost-note]').textContent = ghosts >= gcap ? `Limit reached: ${gcap} (${capReason(def, gh, gcap)}).` : '';
    }
    q('[data-total]').textContent = `${total} players`;
    const problem = over.length ? `${over[0]}.` : total < 4 ? 'At least 4 players are needed.' : evil < 1 ? `Add at least one ${def.teams.evil.name}.`
      : good <= evil ? `${def.teams.good.name} must outnumber ${def.teams.evil.name}.` : '';
    q('[data-check]').textContent = problem || `${good} ${def.teams.good.name} vs ${evil} ${def.teams.evil.name}${neutral ? ` (+${neutral} neutral)` : ''}`;
    return problem;
  }

  el.addEventListener('click', (e) => {
    const gb = gh && e.target.closest('[data-ghost-inc],[data-ghost-dec]');
    if (gb) {
      ghosts = gb.hasAttribute('data-ghost-inc') ? Math.min(capFor(def, gh, counts), ghosts + 1) : Math.max(gh.min ?? 0, ghosts - 1);
      validate();
      onChange();
      return;
    }
    const b = e.target.closest('[data-inc],[data-dec]');
    if (!b) return;
    const inc = b.dataset.inc, dec = b.dataset.dec;
    if (inc) counts[inc] = Math.min(capFor(def, roleOf(inc), counts), counts[inc] + 1);
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
    name: q('[data-name]').value, description: q('[data-desc]').value, roles: { ...counts }, ghosts,
    settings: {
      ...Object.fromEntries(TIMERS.map((t) => [t.key, q(`[data-limit="${t.key}"]`).checked ? q(`[data-secs="${t.key}"]`).value : 0])),
      [NIGHT.key]: q(`[data-secs="${NIGHT.key}"]`).value || NIGHT.def,
      revealRoles: q('[data-reveal]').checked,
      storyPublicChat: q('[data-story-public]').checked,
      storyPrivateChat: q('[data-story-private]').checked,
    },
  });
  validate();
  return { el, values, validate };
}
