import { gameDefs, pollState, sendCommand, fetchAvatars, joinUrl, qrDataUrl } from '../api.js';
import { getSession, forgetSession } from '../store.js';
import { esc, setHtml, toast, $ } from '../util.js';
import { avatarHtml } from '../avatar.js';

const PHASE_LABEL = { lobby: 'Lobby', discussion: 'Discussion', vote: 'Vote', night: 'Night', hunter: "Hunter's shot", ended: 'Game over' };

/**
 * The room screen: lobby + whole game. Polls the server (api.php?a=state) with the last seen
 * version; when it changed the server returns the full per-player state, which re-renders
 * independent regions. Commands are POSTed and answer with the fresh state.
 */
export async function openRoom(app, gameId, navigate) {
  const session = getSession(gameId);
  const defs = await gameDefs();
  const avatars = {};           // playerId -> data URL
  let state = null;
  let def = null;
  let closed = false;
  let polling = false;
  let pollTimer = null;
  let version = -1;
  const requested = new Set();  // avatar keys already fetched or being fetched
  let showRole = false;
  let sel = null;               // selected target (night action)
  let witch = { heal: false, poison: '' };
  let lastSeq = -1;
  let offset = 0;               // server clock - client clock

  app.innerHTML = `
    <div class="room-head">
      <div><h1 data-title style="margin:0"></h1><span class="muted small" data-desc></span></div>
      <div class="row"><span class="badge" data-phase></span><span class="timer" data-timer></span>
        <button data-home>Home</button></div>
    </div>
    <div data-banner></div>
    <div class="room-grid">
      <div class="stack"><div data-role></div><div data-action class="panel stack"></div><div data-notes></div></div>
      <div class="stack"><div data-lobby></div><div class="panel stack" data-players-box><h3>Players</h3><div class="players" data-players></div></div></div>
      <div class="stack">
        <div class="panel"><h3>Story</h3><div class="log" data-log></div></div>
        <div class="panel"><h3>Chat</h3><div class="chat-list" data-chat></div>
          <form class="chat-form" data-chat-form><input name="text" maxlength="300" autocomplete="off" placeholder="Say something…"><button>Send</button></form></div>
      </div>
    </div>`;

  function applyState(next) {
    version = next.version;
    offset = next.now - Date.now();
    state = next;
    render();
    loadMissingAvatars();
  }

  /** Send a command; the server answers with the new state (or an error to show). */
  async function send(cmd) {
    try {
      const r = await sendCommand(gameId, session.token, cmd);
      if (r.left) return leave();
      if (r.state) applyState(r.state);
    } catch (e) {
      if (isGone(e)) return leave(e.message);
      toast(e.message);
    }
  }

  const isGone = (e) => /not found|expired|removed/i.test(e.message);

  async function loadMissingAvatars() {
    const need = state.players.filter((p) => p.hasAvatar && !requested.has(`${p.id}:${p.avatarV}`));
    if (!need.length) return;
    need.forEach((p) => requested.add(`${p.id}:${p.avatarV}`));
    try {
      const r = await fetchAvatars(gameId, session.token, need.map((p) => p.id));
      Object.assign(avatars, r.avatars);
      render();
    } catch { need.forEach((p) => requested.delete(`${p.id}:${p.avatarV}`)); }
  }

  /** Short polling: fast while a decision is pending, slower in the lobby; paused while the tab is hidden. */
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
  const player = (id) => state.players.find((p) => p.id === id);
  const av = (p) => avatarHtml(p.name, avatars[p.id]);
  const roleDef = (id) => def.roles.find((r) => r.id === id);

  function render() {
    def = defs.find((d) => d.id === state.type);
    if (state.seq !== lastSeq) { lastSeq = state.seq; sel = null; witch = { heal: false, poison: '' }; }
    $('[data-title]', app).textContent = `${def.icon} ${state.name}`;
    $('[data-desc]', app).textContent = state.description;
    const badge = $('[data-phase]', app);
    badge.textContent = (state.round && state.phase !== 'lobby' && state.phase !== 'ended' ? `${PHASE_LABEL[state.phase]} · round ${state.round}` : PHASE_LABEL[state.phase]);
    badge.className = `badge ${state.phase === 'night' ? 'night' : state.phase === 'discussion' || state.phase === 'vote' ? 'day' : ''}`;
    renderBanner(); renderRole(); renderAction(); renderNotes(); renderLobby(); renderPlayers(); renderLog(); renderChat();
    updateTimer();
  }

  function renderBanner() {
    let html = '';
    if (state.phase === 'ended') {
      const team = def.teams[state.winner];
      const won = state.me.team === state.winner;
      html = `<div class="panel winner-banner team-${state.winner}">🏆 ${esc(team.name)} win! <span class="muted">${won ? 'You won!' : 'You lost.'}</span></div>`;
    }
    setHtml($('[data-banner]', app), html);
  }

  function renderRole() {
    const el = $('[data-role]', app);
    const me = state.me;
    if (state.phase === 'lobby' || !me.role) return setHtml(el, '');
    const r = roleDef(me.role);
    const status = me.alive ? '' : '<p class="error">You are out of the game. You can still watch and chat with other eliminated players.</p>';
    setHtml(el, showRole
      ? `<div class="panel role-card ${r.team}" data-toggle-role title="Tap to hide"><div class="icon">${r.icon}</div><h2>${esc(r.name)}</h2>
         <div class="team-${r.team}">${esc(def.teams[r.team].name)}</div><p class="muted">${esc(r.desc)}</p>
         <p class="small muted">Goal: ${esc(def.teams[r.team].goal)}</p>${status}</div>`
      : `<div class="panel role-card hidden" data-toggle-role><div class="icon">🂠</div><h2>Your character</h2><p class="muted">Tap to reveal (make sure no one is looking)</p>${status}</div>`);
  }


  function renderAction() {
    const el = $('[data-action]', app);
    const me = state.me;
    el.hidden = state.phase === 'lobby' && !me.isHost;
    const a = me.action;
    let html = '';
    if (state.phase === 'lobby') {
      html = ''; el.hidden = true;
    } else if (state.phase === 'ended') {
      html = `<button class="primary" data-home>Back to home</button>`;
    } else if (!me.alive && state.phase !== 'hunter') {
      html = `<p class="muted">You are eliminated. Watch the game unfold.</p>`;
    } else if (state.phase === 'discussion') {
      const self = player(me.id);
      html = `<h3>Discussion</h3><p class="muted">${state.round === 0 ? 'Get to know each other. Nobody knows anything yet.' : 'Share your suspicions.'}</p>
        <button class="${self.ready ? '' : 'primary'}" data-ready>${self.ready ? '✓ Ready (tap to undo)' : "I'm done talking"}</button>
        <p class="small muted">${state.players.filter((p) => p.alive && p.ready).length}/${state.players.filter((p) => p.alive).length} ready</p>
        ${me.isHost ? '<button data-skip class="danger">Skip discussion (host)</button>' : ''}`;
    } else if (state.phase === 'vote') {
      const mine = state.votes?.[me.id];
      html = `<h3>Vote</h3><p>Tap a player to vote for their elimination. You can change your vote until everyone has voted.</p>
        <p>${mine ? `Your vote: <b>${esc(player(mine).name)}</b>` : mine === null ? 'You abstained.' : 'You have not voted yet.'}</p>
        <button data-abstain>Abstain</button>
        ${me.isHost ? '<button data-skip class="danger">End vote (host)</button>' : ''}`;
    } else if (state.phase === 'hunter' && a) {
      html = actionForm(a);
    } else if (state.phase === 'hunter') {
      html = `<h3>Hunter</h3><p class="muted">The hunter is taking a last shot…</p>`;
    } else if (state.phase === 'night') {
      html = a && !a.waiting ? actionForm(a)
        : `<h3>Night</h3><p class="muted">${a ? 'Waiting for the others… keep your eyes closed.' : 'You sleep. Eyes closed… the village is quiet.'}</p>`;
    }
    setHtml(el, html);
  }

  function actionForm(a) {
    const chosen = sel ?? a.choice?.target ?? null;
    if (a.kind === 'witch') {
      const opts = a.targets.map((id) => `<option value="${id}" ${witch.poison === id ? 'selected' : ''}>${esc(player(id).name)}</option>`).join('');
      return `<h3>${esc(a.prompt)}</h3>
        <p>${a.victim ? `The victim tonight is <b>${esc(a.victim)}</b>.` : 'Nobody was attacked tonight.'}</p>
        ${a.canHeal ? `<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-heal style="width:auto" ${witch.heal ? 'checked' : ''}> Use healing potion on ${esc(a.victim)}</label>` : '<p class="muted small">Healing potion unavailable.</p>'}
        ${a.canPoison ? `<label>Poison</label><select data-poison><option value="">— nobody —</option>${opts}</select>` : '<p class="muted small">Poison already used.</p>'}
        <button class="primary" data-witch>${a.done ? 'Update' : 'Confirm'}</button>
        ${a.done ? '<p class="small muted">Submitted. Waiting for the night to end.</p>' : ''}`;
    }
    const team = a.teamChoices
      ? Object.entries(a.teamChoices).map(([id, t]) => `<div class="small muted">${esc(player(id)?.name)} → ${t ? esc(player(t).name) : 'nobody'}</div>`).join('') : '';
    return `<h3>${esc(a.prompt)}</h3>
      <p>${chosen ? `Selected: <b>${esc(player(chosen).name)}</b>` : 'Tap a highlighted player.'}</p>${team}
      <div class="row"><button class="primary" data-confirm ${chosen ? '' : 'disabled'}>${a.done ? 'Update' : 'Confirm'}</button>
      <button data-pass>${a.kind === 'hunter' ? "Don't shoot" : 'Skip'}</button></div>
      ${a.done ? `<p class="small muted">Submitted${a.choice?.target ? '' : ' (skip)'}. Waiting for the night to end.</p>` : ''}`;
  }

  function renderNotes() {
    const notes = state.me.notes;
    setHtml($('[data-notes]', app), notes.length
      ? `<div class="panel"><h3>Your private notes</h3>${notes.map((n) => `<div class="note"><span class="muted small">Night ${n.round}</span><br>${esc(n.text)}</div>`).join('')}</div>` : '');
  }

  function renderLobby() {
    const el = $('[data-lobby]', app);
    if (state.phase !== 'lobby') return setHtml(el, '');
    const link = joinUrl(state.id);
    const qr = renderLobby.qr?.link === link ? renderLobby.qr.src : (renderLobby.qr = { link, src: qrDataUrl(link) }).src;
    const full = state.players.length === state.total;
    const comp = def.roles.filter((r) => state.roleCounts[r.id]).map((r) => `${r.icon} ${state.roleCounts[r.id]} ${esc(r.name)}`).join(' · ');
    setHtml(el, `<div class="panel stack">
      <div class="row">
        <img class="qr" src="${esc(qr)}" alt="QR code to join the game">
        <div class="stack grow">
          <div><span class="muted small">Game code</span><div class="code">${esc(state.id)}</div></div>
          <div class="small" style="word-break:break-all"><a href="${esc(link)}">${esc(link)}</a></div>
          <button data-copy="${esc(link)}">Copy link</button>
        </div>
      </div>
      <p><b>${state.players.length}/${state.total}</b> players joined.</p>
      <p class="small muted">${comp}</p>
      ${state.me.isHost
        ? `<button class="primary" data-start ${full ? '' : 'disabled'}>${full ? 'Start the game' : 'Waiting for players…'}</button>`
        : `<p class="muted">${full ? 'Waiting for the host to start…' : 'Waiting for more players…'}</p>`}
      ${!state.me.isHost ? '<button data-leave>Leave game</button>' : ''}
    </div>`);
  }

  function renderPlayers() {
    const a = state.me.action;
    const canTarget = (state.phase === 'night' || state.phase === 'hunter') && a && !a.waiting && a.kind !== 'witch' ? new Set(a.targets) : new Set();
    const voting = state.phase === 'vote' && state.me.alive;
    const tally = {};
    for (const t of Object.values(state.votes ?? {})) if (t) tally[t] = (tally[t] ?? 0) + 1;
    const chosen = sel ?? a?.choice?.target ?? null;
    const html = state.players.map((p) => {
      const selectable = (voting && p.alive && p.id !== state.me.id) || canTarget.has(p.id);
      const picked = voting ? state.votes?.[state.me.id] === p.id : chosen === p.id;
      const r = p.role ? roleDef(p.role) : null;
      const status = state.phase === 'lobby' ? (p.id === state.hostId ? '👑 host' : '')
        : !p.alive ? `☠ ${r ? esc(r.name) : 'out'}` : r ? `${r.icon} ${esc(r.name)}`
        : state.phase === 'vote' ? (p.hasVoted ? '✓ voted' : '…') : state.phase === 'discussion' && p.ready ? '✓ ready' : '';
      const kick = state.phase === 'lobby' && state.me.isHost && p.id !== state.me.id
        ? ` <span class="link" role="button" tabindex="0" data-kick="${p.id}" title="Remove">✕</span>` : '';
      return `<button class="player ${p.alive ? '' : 'dead'} ${selectable ? 'selectable' : ''} ${picked ? 'selected' : ''} ${p.id === state.me.id ? 'me' : ''}"
        ${selectable ? `data-target="${p.id}"` : 'tabindex="-1"'} type="button">
        ${av(p)}<span class="meta"><span class="name">${esc(p.name)}</span>
        <span class="small muted"><span class="dot ${p.connected ? '' : 'off'}"></span>${status}${kick}</span></span>
        ${tally[p.id] ? `<span class="tally">${tally[p.id]}</span>` : ''}</button>`;
    }).join('');
    setHtml($('[data-players]', app), html);
  }

  function renderLog() {
    const el = $('[data-log]', app);
    const atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 30;
    setHtml(el, state.log.map((l) => `<p class="${esc(l.type === 'phase' ? 'phase' : l.type)}">${esc(l.text)}</p>`).join('') || '<p class="muted">The story will appear here.</p>');
    if (atBottom) el.scrollTop = el.scrollHeight;
  }

  function canChat() {
    if (state.phase === 'lobby' || state.phase === 'ended' || !state.me.alive) return true;
    if (state.phase === 'discussion' || state.phase === 'vote') return true;
    return state.me.team === 'evil';
  }

  function renderChat() {
    const el = $('[data-chat]', app);
    const atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 30;
    setHtml(el, state.chat.map((m) => {
      const p = player(m.from);
      const tag = m.ch === 'dead' ? ' <span class="small">(graveyard)</span>' : m.ch === 'evil' ? ` <span class="small">(${esc(def.teams.evil.name)})</span>` : '';
      return `<p class="ch-${m.ch}"><b>${esc(p?.name ?? '?')}</b>${tag}: ${esc(m.text)}</p>`;
    }).join('') || '<p class="muted small">No messages yet.</p>');
    if (atBottom) el.scrollTop = el.scrollHeight;
    const form = $('[data-chat-form]', app);
    const ok = canChat();
    form.text.disabled = !ok;
    form.text.placeholder = ok ? (state.me.alive ? 'Say something…' : 'Talk to the other ghosts…') : 'Quiet… it is night';
  }

  // ---------- timer ----------
  function updateTimer() {
    const el = $('[data-timer]', app);
    if (!state?.deadline) { el.textContent = ''; return; }
    const s = Math.max(0, Math.ceil((state.deadline - (Date.now() + offset)) / 1000));
    el.textContent = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
  }
  const tick = setInterval(() => { if (!app.isConnected) return stop(); updateTimer(); }, 500);
  function stop() { closed = true; clearInterval(tick); clearTimeout(pollTimer); }

  // ---------- events ----------
  app.addEventListener('click', (e) => {
    const t = e.target.closest('[data-toggle-role],[data-ready],[data-skip],[data-abstain],[data-target],[data-confirm],[data-pass],[data-witch],[data-start],[data-copy],[data-kick],[data-leave],[data-home]');
    if (!t) return;
    const d = t.dataset;
    if ('toggleRole' in d) { showRole = !showRole; renderRole(); }
    else if ('ready' in d) send({ t: 'ready' });
    else if ('skip' in d) send({ t: 'skip' });
    else if ('abstain' in d) send({ t: 'vote', target: null });
    else if ('target' in d) {
      if (state.phase === 'vote') send({ t: 'vote', target: d.target });
      else { sel = d.target; renderAction(); renderPlayers(); }
    }
    else if ('confirm' in d) send({ t: 'action', target: sel ?? state.me.action?.choice?.target });
    else if ('pass' in d) send({ t: 'action', target: null });
    else if ('witch' in d) send({ t: 'action', heal: witch.heal, poison: witch.poison || null });
    else if ('start' in d) send({ t: 'start' });
    else if ('copy' in d) navigator.clipboard?.writeText(d.copy).then(() => toast('Link copied'));
    else if ('kick' in d) send({ t: 'kick', playerId: d.kick });
    else if ('leave' in d) { send({ t: 'leave' }); leave(); }
    else if ('home' in d) { stop(); navigate(''); }
  });
  app.addEventListener('change', (e) => {
    if (e.target.matches('[data-heal]')) witch.heal = e.target.checked;
    if (e.target.matches('[data-poison]')) witch.poison = e.target.value;
  });
  $('[data-chat-form]', app).addEventListener('submit', (e) => {
    e.preventDefault();
    const input = e.target.text;
    if (input.value.trim()) send({ t: 'chat', text: input.value });
    input.value = '';
  });

  poll();
}
