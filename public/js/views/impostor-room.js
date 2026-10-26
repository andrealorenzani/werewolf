import { pollState, sendCommand, fetchAvatars, joinUrl, qrDataUrl } from '../api.js';
import { getSession, forgetSession } from '../store.js';
import { esc, setHtml, toast, $ } from '../util.js';
import { avatarHtml, profileFields, bindProfile, closePhotoDialog } from '../avatar.js';
import { buildImpostorSetup, impostorSettingsLines } from './impostor-setup.js';
import { bindRulesLinks, closeRules } from '../rules.js';

const PHASE_LABEL = { lobby: 'Lobby', writing: 'Writing questions', answer: 'Answer', reveal: 'Read out', vote: 'Vote', unveil: 'Unveil', result: 'Result', ended: 'Game over' };

/**
 * The Impostor room: lobby + whole game. Same polling contract as the classic room (api.php?a=state with the last
 * version); the server sends a per-player view, so everything shown here is already allowed for this player.
 */
export async function openImpostorRoom(app, gameId, navigate, def) {
  const session = getSession(gameId);
  const L = def.limits;
  const avatars = {};
  const requested = new Set();
  let state = null;
  let closed = false;
  let polling = false;
  let pollTimer = null;
  let version = -1;
  let offset = 0;
  let lastSeq = -1;
  let voteSel = null;           // chosen vote target, local until confirmed
  let draft = { normal: '', impostor: '', answer: '' };

  app.innerHTML = `
    <div class="room-head">
      <div><h1 data-title style="margin:0"></h1><span class="muted small" data-desc></span></div>
      <div class="row"><span class="badge" data-phase></span><span class="timer" data-timer></span>
        <a href="how-to-play" data-rules>Rules</a><button data-edit-profile>My profile</button><button data-home>Home</button></div>
    </div>
    <div data-profile></div>
    <div class="room-grid imp-grid">
      <div class="stack">
        <div data-main class="stack"></div>
        <div data-form class="stack"></div>
        <div data-settings></div>
        <div data-god class="stack"></div>
      </div>
      <div class="stack">
        <div class="panel stack"><h3 data-players-title>Players</h3><div class="players" data-players></div></div>
        <div data-history class="stack"></div>
      </div>
    </div>`;
  bindRulesLinks($('.room-head', app));

  const player = (id) => state.players.find((p) => p.id === id);
  const nameOf = (id) => player(id)?.name ?? '?';
  const av = (p) => avatarHtml(p.name, p.hasAvatar ? avatars[p.id] : null);
  const me = () => state.me;
  const playing = () => state.players.filter((p) => !p.spectator);
  const isGone = (e) => /not found|expired|removed/i.test(e.message);

  function applyState(next) {
    version = next.version;
    offset = next.now - Date.now();
    state = next;
    render();
    loadMissingAvatars();
  }

  async function send(cmd) {
    try {
      const r = await sendCommand(gameId, session.token, cmd);
      if (r.left) { leave(); return true; }
      if (r.state) applyState(r.state);
      return true;
    } catch (e) {
      if (isGone(e)) leave(e.message); else toast(e.message);
      return false;
    }
  }

  async function loadMissingAvatars() {
    const need = state.players.filter((p) => p.hasAvatar && !requested.has(`${p.id}:${p.avatarV}`));
    if (!need.length) return;
    need.forEach((p) => requested.add(`${p.id}:${p.avatarV}`));
    try {
      const r = await fetchAvatars(gameId, session.token, need.map((p) => p.id));
      Object.assign(avatars, r.avatars);
      render();
    } catch {
      need.forEach((p) => requested.delete(`${p.id}:${p.avatarV}`));
      if (!closed) setTimeout(() => { if (!closed && state) loadMissingAvatars(); }, 3000);
    }
  }

  async function poll() {
    if (closed || polling) return;
    polling = true;
    try {
      const r = await pollState(gameId, session.token, version);
      if (r.state) applyState(r.state);
      else if (r.now) offset = r.now - Date.now();
    } catch (e) {
      if (isGone(e)) { polling = false; return leave(e.message); }
    }
    polling = false;
    if (!closed) pollTimer = setTimeout(poll, document.hidden ? 5000 : state?.phase === 'lobby' ? 2000 : 1200);
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden && !closed) { clearTimeout(pollTimer); poll(); } });

  function leave(message) {
    stop();
    forgetSession(gameId);
    if (message) toast(message);
    navigate('');
  }

  // ---------- rendering ----------
  function render() {
    if (state.seq !== lastSeq) { lastSeq = state.seq; voteSel = null; }
    $('[data-title]', app).textContent = `${def.icon} ${state.name}`;
    $('[data-desc]', app).textContent = state.description;
    const badge = $('[data-phase]', app);
    const live = ['answer', 'reveal', 'vote', 'unveil', 'result'].includes(state.phase);
    badge.textContent = live ? `${PHASE_LABEL[state.phase]} · turn ${state.round}/${state.turns}` : PHASE_LABEL[state.phase];
    badge.className = `badge ${live ? 'day' : ''}`;
    renderMain();
    renderForm();
    renderPlayers();
    renderHistory();
    renderGod();
    closeStaleEditors();
    updateTimer();
  }

  const doneBy = (n, total) => `${n}/${total}`;
  const pill = (text) => `<span class="badge">${esc(text)}</span>`;

  /** Reminder of the question this player got (only the player's own variant is ever sent). */
  function questionBox() {
    const m = me();
    if (m.god || m.question == null) return '';
    return `<div class="panel stack imp-question ${m.impostor ? 'imp-you' : ''}">
      ${m.impostor ? '<b class="team-evil">🎭 You are the Impostor.</b> <span class="muted small">Nobody else knows. Your question is different: blend in.</span>' : '<span class="muted small">Your question</span>'}
      <p class="imp-q">${esc(m.question)}</p>${m.answer ? `<p class="small">Your answer: <b>${esc(m.answer)}</b></p>` : ''}</div>`;
  }

  const hostBtn = (attr, label, cls = 'danger') => (me().isHost ? `<button type="button" class="${cls}" ${attr}>${label} (host)</button>` : '');

  function answersList(rows, highlight = null) {
    return `<ol class="imp-answers">${rows.map((a) => `<li class="${a.id === highlight ? 'speaking' : ''}"><b>${esc(nameOf(a.id))}</b>: ${esc(a.text)}</li>`).join('')}</ol>`;
  }

  function renderMain() {
    const el = $('[data-main]', app);
    const m = me();
    const t = state.turn;
    const ph = state.phase;
    let html = '';
    if (ph === 'lobby') html = lobbyHtml();
    else if (ph === 'writing' && state.settings.source === 'host') {
      html = hostWritingHtml();
    } else if (ph === 'writing') {
      const authors = playing().filter((p) => p.confirmed).length;
      html = `<div class="panel stack"><h2>Write the questions</h2>
        <p class="muted">Each author writes a pair of similar questions: one for everybody and a close one for the Impostor. ${authors}/${playing().length} confirmed.</p>
        ${m.isHost ? `<button type="button" class="${m.god ? '' : 'danger'}" data-forcestart>Start now with the confirmed pairs (host)</button>
          <p class="small muted">Unconfirmed pairs are left out; each confirmed pair is one turn.</p>` : ''}</div>`;
    } else if (ph === 'answer') {
      const n = playing().filter((p) => p.answered).length;
      html = `${questionBox()}<div class="panel stack"><h2>Answer</h2>
        <p class="muted">${m.god ? 'Everybody is answering privately.' : 'Answer privately. You can change your answer until everybody has answered.'} ${pill(`${doneBy(n, playing().length)} answered`)}</p>
        ${hostBtn('data-skip', 'Skip waiting')}</div>`;
    } else if (ph === 'reveal') {
      const speaker = t.speaker;
      html = `${questionBox()}<div class="panel stack"><h2>Read out the answers</h2>
        <p>${speaker === m.id ? '<b>Your turn.</b> Read your answer out loud (or explain it), then press Done.'
          : `<b>${esc(nameOf(speaker))}</b> is speaking (${t.index + 1}/${t.count}).`}</p>
        ${answersList(t.answers, speaker)}
        ${speaker === m.id ? '<button type="button" class="primary" data-done>Done</button>' : ''}
        <div class="row">${hostBtn('data-skip', 'Skip speaker')}${hostBtn('data-startvote', 'Open the vote now', '')}</div></div>`;
    } else if (ph === 'vote') {
      html = `${questionBox()}<div class="panel stack"><h2>🗳️ Who is the Impostor?</h2>${answersList(t.answers)}
        ${m.god ? `<p class="muted">${pill(`${playing().filter((p) => p.hasVoted).length}/${playing().length} voted`)}</p>${hostBtn('data-skip', 'End the vote')}` : voteHtml()}</div>`;
    } else if (ph === 'unveil') {
      html = `${questionBox()}<div class="panel stack"><h2>Time to unveil</h2>${answersList(t.answers)}
        ${m.impostor ? '<p>Everybody has voted. Reveal yourself and see how you did.</p><button type="button" class="primary" data-unveil>Unveil yourself</button>'
          : '<p class="muted">Waiting for the Impostor to unveil themselves…</p>'}
        ${hostBtn('data-skip', 'Unveil now')}</div>`;
    } else if (ph === 'result') {
      html = resultHtml(state.history[state.history.length - 1], true);
    } else if (ph === 'ended') {
      html = endedHtml();
    }
    setHtml(el, html);
  }

  /** Host mode, writing phase: the host sees the pairs written so far; the players just wait. */
  function hostWritingHtml() {
    const m = me();
    if (!m.isHost) {
      return '<div class="panel stack"><h2>Waiting for the host</h2><p class="muted">The host is preparing the questions. The game starts as soon as they are ready.</p></div>';
    }
    const need = state.settings.turns;
    const pairs = state.god?.pairs ?? [];
    const ready = pairs.length >= need;
    return `<div class="panel stack"><h2>Write the questions</h2>
      <p class="muted">Write pairs of similar questions: one for everybody and a close one for the Impostor. Only you can see them. Each game turn uses one pair, picked at random.</p>
      <p><span class="badge ${ready ? 'day' : ''}">${pairs.length} of ${need} needed</span></p>
      ${pairs.length ? `<ol class="small imp-pairs">${pairs.map((p) => `<li>${esc(p.normal)}<br><span class="muted">${esc(p.impostor)}</span>
        <span class="link" role="button" tabindex="0" data-hostdel="${p.index}" title="Delete this pair">✕</span></li>`).join('')}</ol>` : ''}
      <button type="button" class="primary" data-forcestart ${ready ? '' : 'disabled'}>${ready ? 'Start the game' : `Write at least ${need} pairs to start`}</button></div>`;
  }

  function voteHtml() {
    const m = me();
    const mine = m.voted ? m.vote : undefined;
    const chosen = voteSel ?? (m.voted ? m.vote : null);
    const grid = playing().filter((p) => p.id !== m.id).map((p) => `<button type="button" class="target ${chosen === p.id ? 'selected' : ''}" data-target="${esc(p.id)}">${av(p)}
      <span class="name">${esc(p.name)}</span></button>`).join('');
    const n = playing().filter((p) => p.hasVoted).length;
    return `<p class="small muted">${mine ? `Your vote: ${esc(nameOf(mine))}` : mine === null ? 'You abstained.' : 'You have not voted yet.'} ${pill(`${doneBy(n, playing().length)} voted`)}</p>
      <div class="target-grid">${grid}</div>
      <div class="row"><button type="button" class="primary" data-vote ${chosen && chosen !== mine ? '' : 'disabled'}>Confirm vote</button>
        <button type="button" data-abstain>Abstain</button>${hostBtn('data-skip', 'End the vote')}</div>
      <p class="small muted">You can change your vote until everybody has voted or the time is up.</p>`;
  }

  function resultHtml(h, withNext) {
    if (!h) return '';
    const m = me();
    const verdict = h.cancelled ? `<p class="error" style="min-height:0">The Impostor left: this turn is cancelled and nobody scores.</p>`
      : h.discovered ? `<p><b class="team-good">Discovered!</b> ${h.awards?.length ? `${h.awards.length} player${h.awards.length === 1 ? '' : 's'} found the Impostor and score${h.awards.length === 1 ? 's' : ''} ${h.playerPoints} each (${h.awards.map((a) => esc(a.name)).join(', ')}).` : 'The group found the Impostor.'} The Impostor gets no points.</p>`
        : `<p><b class="team-evil">The Impostor escaped!</b> ${esc(h.impostorName)} scores ${h.points} point${h.points === 1 ? '' : 's'}.</p>`;
    const tally = h.tally.map((x) => `${esc(x.name)}: ${x.count}`).join(' · ') || 'nobody received a vote';
    const votes = h.votes.map((v) => `${esc(v.name)} → ${v.targetName ? esc(v.targetName) : 'abstained'}`).join(' · ');
    return `<div class="panel stack"><h2>Turn ${h.n} result</h2>
      <p>The Impostor was <b class="team-evil">🎭 ${esc(h.impostorName)}</b>${h.authorName ? ` (questions by ${esc(h.authorName)})` : ''}.</p>${verdict}
      <div class="imp-qs"><div><span class="muted small">Question for everybody</span><p class="imp-q">${esc(h.normal)}</p></div>
        <div><span class="muted small">The Impostor's question</span><p class="imp-q">${esc(h.impostor)}</p></div></div>
      <ol class="imp-answers">${h.answers.map((a) => `<li class="${a.id === h.impostorId ? 'speaking' : ''}"><b>${esc(a.name)}</b>${a.id === h.impostorId ? ' 🎭' : ''}: ${esc(a.text)}</li>`).join('')}</ol>
      <p class="small"><b>Votes received:</b> ${tally}</p><p class="small muted">${votes}</p>
      ${withNext ? `<button type="button" class="primary" data-next>${state.round >= state.turns ? 'Finish the game' : 'Next turn'}</button>` : ''}
      ${m.god && withNext ? '' : ''}</div>`;
  }

  function endedHtml() {
    const rows = [...playing()].sort((a, b) => b.score - a.score);
    const win = new Set(state.winners);
    const names = rows.filter((p) => win.has(p.id)).map((p) => esc(p.name));
    return `<div class="panel winner-banner stack"><h2>🏆 Game over</h2>
      <p>${names.length ? (names.length > 1 ? `Shared win: <b>${names.join(', ')}</b> with ${rows[0].score} points!` : `<b>${names[0]}</b> wins with ${rows[0].score} points!`) : 'Nobody scored: no winner this time.'}</p>
      <ol class="imp-board">${rows.map((p) => `<li class="${win.has(p.id) ? 'speaking' : ''}">${esc(p.name)} <b>${p.score}</b></li>`).join('')}</ol>
      <button type="button" class="primary" data-home>Back to home</button></div>`;
  }

  function lobbyHtml() {
    const m = me();
    const link = joinUrl(state.id);
    const qr = renderMain.qr?.link === link ? renderMain.qr.src : (renderMain.qr = { link, src: qrDataUrl(link) }).src;
    const n = playing().length;
    const canStart = n >= L.seatsMin;
    return `<div class="panel stack">
      <div class="row">
        <img class="qr" src="${esc(qr)}" alt="QR code to join the game">
        <div class="stack grow">
          <div><span class="muted small">Game code</span><div class="code">${esc(state.id)}</div></div>
          <div class="small" style="word-break:break-all"><a href="${esc(link)}">${esc(link)}</a></div>
          <button data-copy="${esc(link)}">Copy link</button>
        </div>
      </div>
      <p><b>${n}/${state.total}</b> players joined.</p>
      <ul class="settings-list small">${impostorSettingsLines(state.settings, state.total).map((l) => `<li>${esc(l)}</li>`).join('')}</ul>
      ${m.isHost
        ? `<button class="primary" data-start ${canStart ? '' : 'disabled'}>${canStart ? `Start with ${n} players` : `Waiting for players (at least ${L.seatsMin})…`}</button>
           <button data-edit-settings>Edit settings</button>`
        : `<p class="muted">${canStart ? 'Waiting for the host to start…' : 'Waiting for more players…'}</p><button data-leave>Leave game</button>`}
    </div>`;
  }

  // ---------- forms (rebuilt only when their key changes, so polling never wipes typing) ----------
  const formBox = $('[data-form]', app);
  function setForm(key, html) {
    if (formBox.dataset.key === key) return;
    formBox.dataset.key = key;
    formBox.innerHTML = html;
  }

  function renderForm() {
    const m = me();
    const ph = state.phase;
    if (ph === 'writing' && state.settings.source === 'host') {
      if (!m.isHost) return setForm('none', '');
      return setForm('hostwrite', `<form class="panel stack" data-host-form>
        <h3>Add a pair</h3>
        <div><label for="hq-n">Question for everybody (${L.questionMin}-${L.questionMax} characters)</label>
          <textarea id="hq-n" name="normal" rows="2" maxlength="${L.questionMax}" required></textarea><span class="small muted" data-count="normal"></span></div>
        <div><label for="hq-i">Similar question for the Impostor (${L.questionMin}-${L.questionMax} characters)</label>
          <textarea id="hq-i" name="impostor" rows="2" maxlength="${L.questionMax}" required></textarea><span class="small muted" data-count="impostor"></span></div>
        <button type="button" class="primary" data-host-add>Add this pair</button></form>`);
    }
    if (ph === 'writing' && (!m.god || m.isHost)) {
      const pr = m.pair;
      if (pr?.confirmed) {
        return setForm('written:1', `<div class="panel stack"><h3>Your questions are locked in</h3>
          <p><span class="muted small">For everybody</span><br>${esc(pr.normal)}</p><p><span class="muted small">For the Impostor</span><br>${esc(pr.impostor)}</p></div>`);
      }
      draft.normal ||= pr?.normal ?? '';
      draft.impostor ||= pr?.impostor ?? '';
      return setForm('write:0', `<form class="panel stack" data-write-form>
        <h3>${m.god ? 'Optional: write a pair yourself' : 'Your pair of questions'}</h3>
        <div><label for="q-n">Question for everybody (${L.questionMin}-${L.questionMax} characters)</label>
          <textarea id="q-n" name="normal" rows="2" maxlength="${L.questionMax}" required>${esc(draft.normal)}</textarea><span class="small muted" data-count="normal"></span></div>
        <div><label for="q-i">Similar question for the Impostor (${L.questionMin}-${L.questionMax} characters)</label>
          <textarea id="q-i" name="impostor" rows="2" maxlength="${L.questionMax}" required>${esc(draft.impostor)}</textarea><span class="small muted" data-count="impostor"></span></div>
        <p class="small muted">Example: "What is your favourite fruit?" and "What is your favourite vegetable?". The questions must be close enough for answers to overlap. You will answer the first one in your own turn.</p>
        <div class="row"><button type="button" data-save-draft>Save draft</button><button class="primary" data-confirm-pair>Confirm questions</button></div></form>`);
    }
    if (ph === 'answer' && !m.god) {
      draft.answer = m.answer ?? draft.answer;
      return setForm(`answer:${state.seq}`, `<form class="panel stack" data-answer-form>
        <label for="ans">Your answer (up to ${L.answerMax} characters)</label>
        <input id="ans" name="text" maxlength="${L.answerMax}" autocomplete="off" required value="${esc(draft.answer)}">
        <div class="row"><button class="primary">Send answer</button><span class="small muted" data-count="text"></span></div></form>`);
    }
    setForm('none', '');
  }

  function updateCounts(form) {
    form.querySelectorAll('[data-count]').forEach((c) => {
      const f = form.elements[c.dataset.count];
      c.textContent = f ? `${f.value.length} characters` : '';
    });
  }

  // ---------- players ----------
  function renderPlayers() {
    $('[data-players-title]', app).textContent = state.phase === 'lobby' ? 'Players' : 'Scores';
    const html = state.players.map((p) => {
      let status = '';
      if (p.id === state.hostId) status = p.spectator ? '👑 host (moderator)' : '👑 host';
      if (state.phase === 'writing') status = p.confirmed ? '✓ questions ready' : '✍ writing';
      else if (state.phase === 'answer' && !p.spectator) status = p.answered ? '✓ answered' : '…';
      else if (state.phase === 'vote' && !p.spectator) status = p.hasVoted ? '✓ voted' : '…';
      else if (state.phase === 'reveal' && state.turn?.speaker === p.id) status = '🎤 speaking';
      const kick = state.me.isHost && p.id !== state.me.id && state.phase !== 'ended'
        ? ` <span class="link" role="button" tabindex="0" data-kick="${esc(p.id)}" title="Remove">✕</span>` : '';
      const score = state.phase !== 'lobby' && !p.spectator ? `<span class="tally">${p.score}</span>` : '';
      return `<div class="player ${p.id === state.me.id ? 'me' : ''}">${av(p)}<span class="meta"><span class="name">${esc(p.name)}</span>
        <span class="small muted"><span class="dot ${p.connected ? '' : 'off'}"></span>${status}${kick}</span></span>${score}</div>`;
    }).join('');
    setHtml($('[data-players]', app), html);
  }

  function renderHistory() {
    const past = state.phase === 'result' ? state.history.slice(0, -1) : state.history;
    setHtml($('[data-history]', app), past.length ? `<details class="panel stack" ${state.phase === 'ended' ? 'open' : ''}><summary><b>Previous turns (${past.length})</b></summary>
      ${past.map((h) => resultHtml(h, false)).join('')}</details>` : '');
  }

  function renderGod() {
    const el = $('[data-god]', app);
    const g = state.god;
    if (!g) return setHtml(el, '');
    const tr = g.turn;
    const live = tr ? `<h4>This turn (live)</h4>
      <p class="small">Impostor: <b class="team-evil">🎭 ${esc(nameOf(tr.impostorId))}</b></p>
      <p class="small"><span class="muted">For everybody:</span> ${esc(tr.normal)}<br><span class="muted">For the Impostor:</span> ${esc(tr.impostor)}</p>
      <p class="small"><b>Answers:</b> ${Object.entries(tr.answers).map(([id, t]) => `${esc(nameOf(id))}: ${esc(t)}`).join(' · ') || 'none yet'}</p>
      <p class="small"><b>Votes:</b> ${Object.entries(tr.votes).map(([id, t]) => `${esc(nameOf(id))} → ${t ? esc(nameOf(t)) : 'abstain'}`).join(' · ') || 'none yet'}</p>` : '';
    const pairs = g.pairs.length && !(state.phase === 'writing' && state.settings.source === 'host') ? `<h4>All question pairs</h4><ol class="small imp-pairs">${g.pairs.map((p) => `<li>${p.turn ? `<b>Turn ${p.turn}</b> ` : '<b>Not scheduled</b> '}${p.author ? `by ${esc(p.author)} ` : ''}${p.confirmed ? '' : '(draft) '}<br>${esc(p.normal)}<br><span class="muted">${esc(p.impostor)}</span></li>`).join('')}</ol>` : '';
    setHtml(el, `<div class="panel stack"><h3>🔭 God mode</h3><p class="small muted">Only you, the moderator, see this.</p>${live}${pairs}</div>`);
  }

  // ---------- editors ----------
  const settingsBox = $('[data-settings]', app);
  const profileBox = $('[data-profile]', app);
  const closeSettings = () => { settingsBox.innerHTML = ''; };
  const closeProfile = () => { profileBox.innerHTML = ''; };
  function closeStaleEditors() {
    if (settingsBox.firstChild && (state.phase !== 'lobby' || !state.me.isHost)) closeSettings();
  }

  function openSettings() {
    if (!state || settingsBox.firstChild) return;
    const form = buildImpostorSetup(def, { name: state.name, description: state.description, seats: state.total, settings: state.settings });
    const wrap = document.createElement('div');
    wrap.className = 'stack';
    wrap.appendChild(form.el);
    wrap.insertAdjacentHTML('beforeend', `<p class="error" data-settings-error></p>
      <div class="row"><button class="primary" data-save-settings>Save settings</button><button data-cancel-settings>Cancel</button></div>`);
    wrap.addEventListener('click', async (e) => {
      if (e.target.closest('[data-cancel-settings]')) return closeSettings();
      if (!e.target.closest('[data-save-settings]')) return;
      const problem = form.validate();
      if (problem) { wrap.querySelector('[data-settings-error]').textContent = problem; return; }
      if (await send({ t: 'settings', ...form.values() })) closeSettings();
    });
    settingsBox.appendChild(wrap);
  }

  function openProfile() {
    if (!state || profileBox.firstChild) return;
    const p = player(state.me.id);
    const wrap = document.createElement('form');
    wrap.className = 'panel stack';
    const cur = { name: p.name, avatar: p.hasAvatar ? avatars[p.id] ?? null : null };
    wrap.innerHTML = `<h3>Edit my profile</h3>${profileFields(cur)}
      <div class="row"><button class="primary" data-save-profile>Save</button><button type="button" data-cancel-profile>Cancel</button></div>`;
    const values = bindProfile(wrap, cur);
    wrap.addEventListener('click', (e) => { if (e.target.closest('[data-cancel-profile]')) closeProfile(); });
    wrap.addEventListener('submit', async (e) => {
      e.preventDefault();
      const v = values();
      const cmd = { t: 'profile', name: v.name };
      if (values.avatarChanged()) cmd.avatar = v.avatar;
      if (await send(cmd)) closeProfile();
    });
    profileBox.appendChild(wrap);
  }

  // ---------- timer ----------
  function updateTimer() {
    let text = '';
    if (state?.deadline) {
      const s = Math.max(0, Math.ceil((state.deadline - (Date.now() + offset)) / 1000));
      text = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
    }
    $('[data-timer]', app).textContent = text;
  }
  const tick = setInterval(() => { if (!app.isConnected) return stop(); updateTimer(); }, 500);
  function stop() { closed = true; closePhotoDialog(); closeRules(); clearInterval(tick); clearTimeout(pollTimer); }

  // ---------- events ----------
  app.addEventListener('click', (e) => {
    const t = e.target.closest('[data-start],[data-copy],[data-kick],[data-leave],[data-home],[data-edit-settings],[data-edit-profile],[data-skip],[data-done],[data-startvote],[data-target],[data-vote],[data-abstain],[data-unveil],[data-next],[data-forcestart],[data-hostdel],[data-host-add],[data-save-draft],[data-confirm-pair]');
    if (!t) return;
    const d = t.dataset;
    if ('start' in d) send({ t: 'start' });
    else if ('editSettings' in d) openSettings();
    else if ('editProfile' in d) openProfile();
    else if ('copy' in d) navigator.clipboard?.writeText(d.copy).then(() => toast('Link copied'));
    else if ('kick' in d) {
      if (state.phase === 'lobby' || confirm(`Remove ${nameOf(d.kick)} from the game?`)) send({ t: 'kick', playerId: d.kick });
    }
    else if ('leave' in d) { send({ t: 'leave' }); leave(); }
    else if ('home' in d) { stop(); navigate(''); }
    else if ('skip' in d) send({ t: 'skip' });
    else if ('done' in d) send({ t: 'done' });
    else if ('startvote' in d) send({ t: 'startvote' });
    else if ('target' in d) { voteSel = d.target; renderMain(); }
    else if ('vote' in d) send({ t: 'vote', target: voteSel });
    else if ('abstain' in d) send({ t: 'vote', target: null });
    else if ('unveil' in d) send({ t: 'unveil' });
    else if ('next' in d) send({ t: 'next' });
    else if ('forcestart' in d) send({ t: 'forcestart' });
    else if ('hostdel' in d) send({ t: 'hostdel', index: Number(d.hostdel) });
    else if ('hostAdd' in d) {
      const f = formBox.querySelector('[data-host-form]');
      send({ t: 'hostadd', normal: f.normal.value, impostor: f.impostor.value }).then((ok) => {
        if (ok) { f.normal.value = ''; f.impostor.value = ''; updateCounts(f); f.normal.focus(); }
      });
    }
    else if ('saveDraft' in d || 'confirmPair' in d) {
      e.preventDefault();
      const f = formBox.querySelector('[data-write-form]');
      draft.normal = f.normal.value; draft.impostor = f.impostor.value;
      (async () => {
        if (!(await send({ t: 'write', normal: draft.normal, impostor: draft.impostor }))) return;
        if ('confirmPair' in d) { if (await send({ t: 'confirm' })) { draft.normal = ''; draft.impostor = ''; } } else toast('Draft saved');
      })();
    }
  });
  app.addEventListener('input', (e) => {
    const f = e.target.form;
    if (!f) return;
    if (f.matches('[data-write-form]')) { draft.normal = f.normal.value; draft.impostor = f.impostor.value; }
    if (f.matches('[data-answer-form]')) draft.answer = f.text.value;
    updateCounts(f);
  });
  app.addEventListener('submit', (e) => {
    if (e.target.matches('[data-answer-form]')) { e.preventDefault(); send({ t: 'answer', text: e.target.text.value }); }
    else if (e.target.matches('[data-write-form],[data-host-form]')) e.preventDefault();
  });
  new MutationObserver(() => { const f = formBox.querySelector('form'); if (f) updateCounts(f); }).observe(formBox, { childList: true });

  poll();
}
