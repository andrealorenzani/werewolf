import { esc } from '../util.js';

/** Human-readable lines for an Impostor game's settings. */
export function impostorSettingsLines(st, total) {
  return [
    `Seats: ${total}`, `Host plays: ${st.hostPlays ? 'yes' : 'no (host sees everything)'}`,
    `Questions: ${st.source === 'players' ? 'written by the players' : `built-in pool, ${st.turns} turn${st.turns === 1 ? '' : 's'}`}`,
    `Vote time: ${st.voteSeconds} s`, `Points for an undiscovered Impostor: ${st.points}`,
  ];
}

/**
 * Impostor setup form, shared by "create" and the host's lobby "Edit settings".
 * initial: {name, description, seats, settings{hostPlays, source, turns, voteSeconds, points}}. Same contract as
 * buildSetupForm: {el, values(), validate()}; limits come from the server (def.limits).
 */
export function buildImpostorSetup(def, initial, onChange = () => {}) {
  const L = def.limits;
  const st = initial.settings ?? {};
  const el = document.createElement('div');
  el.className = 'stack';
  const num = (key, label, min, max, value) => `<div><label for="imp-${key}">${esc(label)} (${min}-${max})</label>
    <input id="imp-${key}" type="number" data-f="${key}" min="${min}" max="${max}" value="${esc(value)}"></div>`;
  el.innerHTML = `
    <section class="panel stack">
      <h2>Game</h2>
      <div><label>Name</label><input data-name maxlength="40" required value="${esc(initial.name)}"></div>
      <div><label>Description</label><textarea data-desc maxlength="300" rows="2" placeholder="Optional">${esc(initial.description ?? '')}</textarea></div>
    </section>
    <section class="panel stack">
      <h2>Rules of this game</h2>
      <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-host-plays style="width:auto" ${st.hostPlays === false ? '' : 'checked'}>
        I play too</label>
      <p class="muted small">If you do not play, you moderate and see everything: both questions, the Impostor, all answers and votes, and every question pair.</p>
      <div><label for="imp-source">Questions</label>
        <select id="imp-source" data-f="source"><option value="pool" ${st.source === 'players' ? '' : 'selected'}>Built-in pool of questions</option>
          <option value="players" ${st.source === 'players' ? 'selected' : ''}>Written by the players</option></select></div>
      <div class="form-grid">
        <div data-turns-box>${num('turns', 'Turns', L.turnsMin, L.turnsMax, st.turns ?? L.turnsDef)}</div>
        ${num('voteSeconds', 'Vote time (seconds)', L.voteMin, L.voteMax, st.voteSeconds ?? L.voteDef)}
        ${num('points', 'Points for an undiscovered Impostor', L.pointsMin, L.pointsMax, st.points ?? L.pointsDef)}
        ${num('seats', 'Seats (players)', L.seatsMin, L.seatsMax, initial.seats ?? L.seatsDef)}
      </div>
      <p class="muted small" data-source-note></p>
      <p class="muted small" data-check></p>
    </section>`;
  const q = (s) => el.querySelector(s);
  const f = (k) => q(`[data-f="${k}"]`);
  const range = [['turns', L.turnsMin, L.turnsMax, 'Turns'], ['voteSeconds', L.voteMin, L.voteMax, 'Vote time'],
    ['points', L.pointsMin, L.pointsMax, 'Points'], ['seats', L.seatsMin, L.seatsMax, 'Seats']];

  function validate() {
    const players = f('source').value === 'players';
    q('[data-turns-box]').hidden = players;
    q('[data-source-note]').textContent = players
      ? 'Every player writes one pair of similar questions; each pair is one turn.' : 'Questions are drawn from the built-in pool without repeating.';
    for (const [key, lo, hi, label] of range) {
      if (key === 'turns' && players) continue;
      const n = Number(f(key).value);
      if (!Number.isInteger(n) || n < lo || n > hi) {
        const text = key === 'voteSeconds' ? `${label} must be between ${lo} and ${hi} seconds.` : `${label} must be between ${lo} and ${hi}.`;
        q('[data-check]').textContent = text;
        return text;
      }
    }
    q('[data-check]').textContent = `${f('seats').value} seats, at least ${L.seatsMin} players are needed to start.`;
    return '';
  }

  el.addEventListener('input', () => { validate(); onChange(); });
  el.addEventListener('change', () => { validate(); onChange(); });
  const values = () => ({
    name: q('[data-name]').value, description: q('[data-desc]').value, seats: f('seats').value,
    settings: { hostPlays: q('[data-host-plays]').checked, source: f('source').value, turns: f('turns').value,
      voteSeconds: f('voteSeconds').value, points: f('points').value },
  });
  validate();
  return { el, values, validate };
}
