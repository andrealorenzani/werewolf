import { gameDefs, pollState, sendCommand, fetchAvatars, joinUrl, qrDataUrl } from '../api.js';
import { getSession, forgetSession } from '../store.js';
import { esc, setHtml, toast, $ } from '../util.js';
import { avatarHtml, profileFields, bindProfile, closePhotoDialog } from '../avatar.js';
import { buildSetupForm, settingsLines } from './setup.js';

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
  let sel = null;               // selected target (night action / vote), local until confirmed
  let witch = { heal: false, poison: '' };
  let voteClosedSeq = -1;       // the vote popup was closed during this phase (it can be reopened)
  let lastSeq = -1;
  let offset = 0;               // server clock - client clock

  app.innerHTML = `
    <div class="room-head">
      <div><h1 data-title style="margin:0"></h1><span class="muted small" data-desc></span></div>
      <div class="row"><span class="badge" data-phase></span><span class="timer" data-timer></span>
        <button data-edit-profile>My profile</button><button data-home>Home</button></div>
    </div>
    <div data-banner></div>
    <div data-profile></div>
    <div class="room-grid">
      <div class="stack"><div data-role></div><div data-settings-list></div><div data-action class="panel stack"></div><div data-notes></div></div>
      <div class="stack"><div data-lobby></div><div data-settings></div><div class="panel stack" data-players-box><h3>Players</h3><div class="players" data-players></div></div></div>
      <div class="stack">
        <div class="panel"><h3>Story</h3><div class="log" data-log></div></div>
        <div class="panel"><h3>Chat</h3><div class="chat-list" data-chat></div>
          <form class="chat-form" data-chat-form><select name="to" aria-label="Send to"><option value="">Everyone</option></select>
            <input name="text" maxlength="300" autocomplete="off" placeholder="Say something…"><button>Send</button></form>
          <p class="small muted" data-chat-hint></p></div>
      </div>
    </div>
    <div class="dialog-overlay" data-modal role="dialog" aria-modal="true" aria-labelledby="modal-title" hidden>
      <div class="dialog-box stack">
        <div class="row spread"><h2 id="modal-title" data-m-title style="margin:0"></h2>
          <span class="row"><span class="timer" data-m-timer></span><button type="button" class="link" data-m-close aria-label="Close" hidden>✕</button></span></div>
        <div data-m-body class="stack"></div>
        <div data-m-chat-wrap hidden>
          <div class="chat-list" data-m-chat></div>
          <form class="chat-form" data-m-chat-form><input name="text" maxlength="300" autocomplete="off" placeholder="Talk to your pack…"><button>Send</button></form>
        </div>
        <div data-m-foot class="row"></div>
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
      if (r.left) { leave(); return true; }
      if (r.state) applyState(r.state);
      return true;
    } catch (e) {
      if (isGone(e)) leave(e.message); else toast(e.message);
      return false;
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
    } catch {
      need.forEach((p) => requested.delete(`${p.id}:${p.avatarV}`));
      if (!closed) setTimeout(() => { if (!closed && state) loadMissingAvatars(); }, 3000); // retry later
    }
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
  const av = (p) => avatarHtml(p.name, p.hasAvatar ? avatars[p.id] : null);
  const roleDef = (id) => def.roles.find((r) => r.id === id);

  function render() {
    def = defs.find((d) => d.id === state.type);
    if (state.seq !== lastSeq) { lastSeq = state.seq; sel = null; witch = { heal: false, poison: '' }; }
    $('[data-title]', app).textContent = `${def.icon} ${state.name}`;
    $('[data-desc]', app).textContent = state.description;
    const badge = $('[data-phase]', app);
    badge.textContent = (state.round && state.phase !== 'lobby' && state.phase !== 'ended' ? `${PHASE_LABEL[state.phase]} · round ${state.round}` : PHASE_LABEL[state.phase]);
    badge.className = `badge ${state.phase === 'night' ? 'night' : state.phase === 'discussion' || state.phase === 'vote' ? 'day' : ''}`;
    renderBanner(); renderRole(); renderSettingsList(); renderAction(); renderNotes(); renderLobby(); closeStaleEditors(); renderPlayers(); renderLog(); renderChat();
    renderModal();
    updateTimer();
  }

  function renderBanner() {
    let html = '';
    if (state.phase === 'ended') {
      const team = def.teams[state.winner];
      const won = state.me.team === state.winner;
      html = `<div class="panel winner-banner team-${state.winner}">🏆 ${esc(team.name)} win! <span class="muted">${won ? 'You won!' : 'You lost.'}</span></div>`;
    }
    const g = state.me.ghost;
    if (g) {
      const team = def.teams[g.evil ? 'evil' : 'good'];
      html += `<div class="panel ghost-banner ghost-${g.evil ? 'evil' : 'good'}">👻 ${g.evil
        ? `Death has turned you: your ghost now sides with the ${esc(team.name)}.`
        : `Your ghost stays loyal to the ${esc(team.name)}.`} <span class="muted small">Only you know this.</span></div>`;
    }
    setHtml($('[data-banner]', app), html);
  }

  function renderSettingsList() {
    const el = $('[data-settings-list]', app);
    if (state.phase === 'lobby') return setHtml(el, '');
    setHtml(el, `<div class="panel"><h3>Game settings</h3><ul class="settings-list small">${settingsLines(state.settings).map((l) => `<li>${esc(l)}</li>`).join('')}</ul></div>`);
  }

  function renderRole() {
    const el = $('[data-role]', app);
    const me = state.me;
    if (state.phase === 'lobby' || !me.role) return setHtml(el, '');
    const r = roleDef(me.role);
    if (!r) return setHtml(el, '');
    const status = me.alive ? '' : '<p class="error">You are out of the game. You can still watch and chat with other eliminated players.</p>';
    setHtml(el, showRole
      ? `<div class="panel role-card ${r.team}" data-toggle-role title="Tap to hide"><div class="icon">${r.icon}</div><h2>${esc(r.name)}</h2>
         <div class="team-${r.team}">${esc(def.teams[r.team].name)}</div><p class="muted">${esc(r.desc)}</p>
         <p class="small muted">Goal: ${esc(def.teams[r.team].goal)}</p>${status}</div>`
      : `<div class="panel role-card hidden" data-toggle-role><div class="icon">🂠</div><h2>Your character</h2><p class="muted">Tap to reveal (make sure no one is looking)</p>${status}</div>`);
  }


  /** The inline action panel: discussion button, status lines. Night actions, the hunter shot and the vote live in the popup. */
  function renderAction() {
    const el = $('[data-action]', app);
    const me = state.me;
    el.hidden = state.phase === 'lobby' && !me.isHost;
    const a = me.action;
    const popup = !!popupSpec();
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
      html = `<h3>Vote</h3>
        <p>${mine ? `Your vote: <b>${esc(player(mine).name)}</b>` : mine === null ? 'You abstained.' : 'You have not voted yet.'}</p>
        ${popup ? '' : '<button class="primary" data-open-vote>Open the voting popup</button>'}
        ${me.isHost && !popup ? '<button data-skip class="danger">End vote (host)</button>' : ''}`;
    } else if (state.phase === 'hunter' && !a) {
      html = `<h3>Hunter</h3><p class="muted">The hunter is taking a last shot…</p>`;
    } else if (state.phase === 'night' && !popup) {
      html = `<h3>Night</h3><p class="muted">You sleep. Eyes closed… the village is quiet.</p>`;
    }
    if (me.isHost && !popup && (state.phase === 'night' || state.phase === 'hunter')) {
      html += `<button data-skip class="danger">${state.phase === 'night' ? 'Skip night step' : 'Skip hunter shot'} (host)</button>`;
    }
    setHtml(el, html);
    el.hidden = el.hidden || !html;
  }

  // ---------- popup (night action, hunter shot, vote) ----------
  /** Grid of players to pick from. mode: 'pick' | 'vote' | 'suggest'. marks: playerId -> small caption. */
  function targetGrid(ids, mode, chosen, marks = {}) {
    return `<div class="target-grid">${ids.map((id) => {
      const p = player(id);
      return `<button type="button" class="target ${chosen === id ? 'selected' : ''}" data-target="${esc(id)}" data-mode="${mode}">${av(p)}
        <span class="name">${esc(p.name)}</span>${marks[id] ? `<span class="small muted">${esc(marks[id])}</span>` : ''}</button>`;
    }).join('') || '<p class="muted">Nobody to choose.</p>'}</div>`;
  }

  const skipFoot = (label) => (state.me.isHost ? `<button type="button" data-skip class="danger">${label} (host)</button>` : '');

  /** What the popup shows right now, or null: { title, body, foot, closable, chat }. */
  function popupSpec() {
    const me = state.me, a = me.action;
    if (state.phase === 'hunter' && a?.kind === 'hunter') {
      const chosen = sel ?? a.choice?.target ?? null;
      return { title: '🏹 Your last shot', foot: skipFoot('Skip hunter shot'), body: `<p>${esc(a.prompt)}</p>${targetGrid(a.targets, 'pick', chosen)}
        <div class="row"><button class="primary" data-confirm ${chosen ? '' : 'disabled'}>Shoot</button><button data-pass>Don't shoot</button></div>` };
    }
    if (state.phase === 'night' && me.alive) {
      const chat = me.team === 'evil';
      if (!a || a.waiting) {
        return { title: '🌙 It is night…', chat, foot: skipFoot('Skip night step'),
          body: `<p class="muted">${a ? 'Waiting for the others… keep your eyes closed.' : 'The village sleeps. Keep your eyes closed.'}</p>` };
      }
      return { title: `${roleOrGhost(a)}`, chat, foot: skipFoot('Skip night step'), body: nightBody(a) };
    }
    if (state.phase === 'vote' && me.alive && voteClosedSeq !== state.seq) {
      const mine = state.votes?.[me.id];
      const chosen = sel ?? mine ?? null;
      const ids = state.players.filter((p) => p.alive && p.id !== me.id).map((p) => p.id);
      return { title: '🗳️ Vote', closable: true, foot: skipFoot('End vote'), body: `<p>Who should be eliminated? You can change your vote until everyone has voted.</p>
        <p class="small muted">${mine ? `Your vote: ${esc(player(mine).name)}` : mine === null ? 'You abstained.' : 'You have not voted yet.'}</p>
        ${targetGrid(ids, 'vote', chosen)}
        <div class="row"><button class="primary" data-vote-confirm ${chosen && chosen !== mine ? '' : 'disabled'}>Confirm vote</button><button data-abstain>Abstain</button></div>` };
    }
    return null;
  }

  function roleOrGhost(a) {
    if (a.kind === 'haunt') return '👻 Your ghost';
    const r = roleDef(state.me.role);
    return r ? `${r.icon} ${esc(r.name)}` : 'Night';
  }

  function nightBody(a) {
    const note = a.done ? '<p class="small muted">Submitted. Waiting for the night to end.</p>' : '';
    if (a.kind === 'witch') {
      const opts = a.targets.map((id) => `<option value="${esc(id)}" ${witch.poison === id ? 'selected' : ''}>${esc(player(id).name)}</option>`).join('');
      return `<p>${esc(a.prompt)}</p>
        <p>${a.victim ? `The victim tonight is <b>${esc(a.victim)}</b>.` : 'Nobody was attacked tonight.'}</p>
        ${a.canHeal ? `<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-heal style="width:auto" ${witch.heal ? 'checked' : ''}> Use healing potion on ${esc(a.victim)}</label>` : '<p class="muted small">Healing potion unavailable.</p>'}
        ${a.canPoison ? `<label>Poison</label><select data-poison><option value="">— nobody —</option>${opts}</select>` : '<p class="muted small">Poison already used.</p>'}
        <button class="primary" data-witch>${a.done ? 'Update' : 'Confirm'}</button>${note}`;
    }
    if (a.kind === 'guard') {
      const mates = Object.entries(a.teamChoices ?? {}).map(([id, v]) => `<div class="small muted">${esc(player(id)?.name)}: ${v ? 'saves them' : 'lets fate decide'}</div>`).join('');
      return `<p>${esc(a.prompt)}</p><p>Victim tonight is <b>${esc(a.victim)}</b>.</p>
        ${(state.me.mates ?? []).length ? `<p class="small muted">Fellow paladins must all agree to shield. ${mates || 'They have not chosen yet.'}</p>` : ''}
        <div class="row"><button class="primary" data-guard="true">Save them (${a.usesLeft} left)</button>
        <button data-guard="false">Let fate decide</button></div>
        ${a.done ? `<p class="small muted">Submitted (${a.choice?.save ? 'saving them' : 'letting fate decide'}). Waiting for the night to end.</p>` : ''}`;
    }
    if (a.kind === 'kill') {
      const marks = {};
      for (const [wid, tid] of Object.entries(a.teamChoices ?? {})) marks[tid] = [marks[tid], player(wid)?.name].filter(Boolean).join(', ');
      const target = a.agreedTarget ? player(a.agreedTarget)?.name : null;
      const confirmed = (a.confirmed ?? []).map((id) => player(id)?.name).filter(Boolean);
      return `<p>${esc(a.prompt)}</p>
        <p class="small muted">${target ? `You agree on <b>${esc(target)}</b>. Everyone must confirm.` : 'Tap a player to suggest them. You all need to pick the same one.'}</p>
        ${targetGrid(a.targets, 'suggest', a.mine ?? null, marks)}
        <div class="row"><button class="primary" data-wolf-confirm ${a.agreed && !a.done ? '' : 'disabled'}>${a.done ? '✓ Confirmed' : target ? `Confirm ${esc(target)}` : 'Confirm'}</button></div>
        <p class="small muted">${confirmed.length ? `Confirmed: ${esc(confirmed.join(', '))}` : 'Nobody has confirmed yet.'}</p>`;
    }
    const chosen = sel ?? a.choice?.target ?? null;
    return `<p>${esc(a.prompt)}</p>${targetGrid(a.targets, 'pick', chosen)}
      <div class="row"><button class="primary" data-confirm ${chosen ? '' : 'disabled'}>${a.done ? 'Update' : 'Confirm'}</button>
      <button data-pass>${a.kind === 'haunt' ? "Don't point" : 'Skip'}</button></div>
      ${a.done ? `<p class="small muted">Submitted${a.choice?.target ? '' : ' (skip)'}. Waiting for the night to end.</p>` : ''}`;
  }

  function renderModal() {
    const box = $('[data-modal]', app);
    const spec = popupSpec();
    box.hidden = !spec;
    if (!spec) { setHtml($('[data-m-body]', box), ''); return; }
    setHtml($('[data-m-title]', box), spec.title);
    setHtml($('[data-m-body]', box), spec.body);
    setHtml($('[data-m-foot]', box), spec.foot ?? '');
    $('[data-m-close]', box).hidden = !spec.closable;
    $('[data-m-chat-wrap]', box).hidden = !spec.chat;
    if (spec.chat) {
      const list = $('[data-m-chat]', box);
      const atBottom = list.scrollTop + list.clientHeight >= list.scrollHeight - 30;
      setHtml(list, chatHtml());
      if (atBottom) list.scrollTop = list.scrollHeight;
      const form = $('[data-m-chat-form]', box);
      form.text.disabled = !canChat();
    }
  }

  function renderNotes() {
    const notes = state.me.notes;
    setHtml($('[data-notes]', app), notes.length
      ? `<div class="panel"><h3>Your private notes</h3>${notes.map((n) => `<div class="note"><span class="muted small">Night ${n.round}</span><br>${esc(n.text)}</div>`).join('')}</div>` : '');
  }

  function renderLobby() {
    const el = $('[data-lobby]', app);
    if (state.phase !== 'lobby' && !state.joinable) return setHtml(el, '');
    const link = joinUrl(state.id);
    const qr = renderLobby.qr?.link === link ? renderLobby.qr.src : (renderLobby.qr = { link, src: qrDataUrl(link) }).src;
    const n = state.players.length;
    const canStart = n >= 4;
    const inLobby = state.phase === 'lobby';
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
      <p><b>${n}/${state.total}</b> players joined.</p>
      ${inLobby ? `<p class="small muted">${comp}</p>` : '<p class="small muted">Late joiners welcome: you can still take a seat on day 0.</p>'}
      ${inLobby ? (state.me.isHost
        ? `<button class="primary" data-start ${canStart ? '' : 'disabled'}>${canStart ? `Start with ${n} players` : 'Waiting for players (at least 4)…'}</button>
           <button data-edit-settings>Edit settings</button>`
        : `<p class="muted">${canStart ? 'Waiting for the host to start…' : 'Waiting for more players…'}</p>`) : ''}
      ${inLobby && !state.me.isHost ? '<button data-leave>Leave game</button>' : ''}
    </div>`);
  }

  // ---------- editors (rendered once, so polling never wipes what the user is typing) ----------
  const settingsBox = $('[data-settings]', app);
  const profileBox = $('[data-profile]', app);
  const closeSettings = () => { settingsBox.innerHTML = ''; };
  const closeProfile = () => { profileBox.innerHTML = ''; };
  function closeStaleEditors() {
    if (settingsBox.firstChild && (state.phase !== 'lobby' || !state.me.isHost)) closeSettings();
  }

  function openSettings() {
    if (!state || settingsBox.firstChild) return;
    const form = buildSetupForm(def, { name: state.name, description: state.description, roles: state.roleCounts, settings: state.settings });
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
    const me = player(state.me.id);
    const wrap = document.createElement('form');
    wrap.className = 'panel stack';
    wrap.innerHTML = `<h3>Edit my profile</h3>${profileFields({ name: me.name, avatar: me.hasAvatar ? avatars[me.id] ?? null : null })}
      <div class="row"><button class="primary" data-save-profile>Save</button><button type="button" data-cancel-profile>Cancel</button></div>`;
    const values = bindProfile(wrap, { name: me.name, avatar: me.hasAvatar ? avatars[me.id] ?? null : null });
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

  function renderPlayers() {
    const tally = {};
    for (const t of Object.values(state.votes ?? {})) if (t) tally[t] = (tally[t] ?? 0) + 1;
    const html = state.players.map((p) => {
      const r = p.role ? roleDef(p.role) : null;
      const status = state.phase === 'lobby' ? (p.id === state.hostId ? '👑 host' : '')
        : !p.alive ? `☠ ${r ? esc(r.name) : 'out'}` : r ? `${r.icon} ${esc(r.name)}`
        : state.phase === 'vote' ? (p.hasVoted ? '✓ voted' : '…') : state.phase === 'discussion' && p.ready ? '✓ ready' : '';
      const flag = p.markedText ? ` <span class="flag">⚑ ${esc(p.markedText)}</span>` : '';
      const kick = state.phase === 'lobby' && state.me.isHost && p.id !== state.me.id
        ? ` <span class="link" role="button" tabindex="0" data-kick="${p.id}" title="Remove">✕</span>` : '';
      return `<div class="player ${p.alive ? '' : 'dead'} ${p.markedBy ? 'marked' : ''} ${p.id === state.me.id ? 'me' : ''}">
        ${av(p)}<span class="meta"><span class="name">${esc(p.name)}</span>
        <span class="small muted"><span class="dot ${p.connected ? '' : 'off'}"></span>${status}${kick}${flag}</span></span>
        ${tally[p.id] ? `<span class="tally">${tally[p.id]}</span>` : ''}</div>`;
    }).join('');
    setHtml($('[data-players]', app), html);
  }

  function renderLog() {
    const el = $('[data-log]', app);
    const atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 30;
    setHtml(el, state.log.map((l) => {
      if (l.type !== 'chat') return `<p class="${esc(l.type === 'phase' ? 'phase' : l.type)}">${esc(l.text)}</p>`;
      const from = esc(player(l.from)?.name ?? '?');
      const who = l.ch === 'dm' ? `${from} → ${esc(player(l.to)?.name ?? '?')} <span class="small">(private)</span>`
        : `${from}${l.ch === 'dead' ? ' <span class="small">(graveyard)</span>' : l.ch === 'evil' ? ` <span class="small">(${esc(def.teams.evil.name)})</span>` : ''}`;
      return `<p class="chat">${who}: ${esc(l.text)}</p>`;
    }).join('') || '<p class="muted">The story will appear here.</p>');
    if (atBottom) el.scrollTop = el.scrollHeight;
  }

  function canChat() {
    if (state.phase === 'lobby' || state.phase === 'ended' || !state.me.alive) return true;
    if (state.phase === 'discussion' || state.phase === 'vote') return true;
    return state.me.team === 'evil';
  }

  function chatHtml() {
    return state.chat.map((m) => {
      const p = player(m.from);
      if (m.ch === 'dm') return `<p class="ch-dm"><b>${esc(p?.name ?? '?')}</b> → <b>${esc(player(m.to)?.name ?? '?')}</b> <span class="small">(private)</span>: ${esc(m.text)}</p>`;
      const tag = m.ch === 'dead' ? ' <span class="small">(graveyard)</span>' : m.ch === 'evil' ? ` <span class="small">(${esc(def.teams.evil.name)})</span>` : '';
      return `<p class="ch-${m.ch}"><b>${esc(p?.name ?? '?')}</b>${tag}: ${esc(m.text)}</p>`;
    }).join('') || '<p class="muted small">No messages yet.</p>';
  }

  function renderChat() {
    const el = $('[data-chat]', app);
    const atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 30;
    setHtml(el, chatHtml());
    if (atBottom) el.scrollTop = el.scrollHeight;
    const form = $('[data-chat-form]', app);
    const ok = canChat();
    form.text.disabled = !ok;
    form.text.placeholder = ok ? (state.me.alive ? 'Say something…' : 'Talk to the other ghosts…') : 'Quiet… it is night';
    renderChatTarget(form);
    $('[data-chat-hint]', app).textContent = state.settings.storyPrivateChat
      ? 'Private messages, wolf night chat and graveyard chat will be shown in the final story.' : '';
  }

  /** Can I send a private message to this player right now? Mirrors the server rule. */
  function canDm(p) {
    const ph = state.phase;
    if (p.id === state.me.id || ph === 'night') return false;
    if (ph === 'lobby' || ph === 'ended') return true;
    if (state.me.alive && ph !== 'discussion' && ph !== 'vote') return false;
    return p.alive === state.me.alive;
  }

  /** The "send to" list is rebuilt only when the players change, so polling never resets the choice. */
  function renderChatTarget(form) {
    const sel = form.to;
    const others = state.players.filter((p) => p.id !== state.me.id);
    const key = others.map((p) => `${p.id}:${p.name}`).join('|');
    if (sel.dataset.key !== key) {
      const keep = sel.value;
      sel.innerHTML = `<option value="">Everyone</option>${others.map((p) => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join('')}`;
      sel.dataset.key = key;
      sel.value = others.some((p) => p.id === keep) ? keep : '';
    }
    let any = false;
    for (const o of sel.options) {
      if (!o.value) continue;
      o.disabled = !canDm(player(o.value));
      any ||= !o.disabled;
    }
    if (sel.selectedOptions[0]?.disabled) sel.value = '';
    sel.disabled = !any;
  }

  // ---------- timer ----------
  function updateTimer() {
    let text = '';
    if (state?.deadline) {
      const s = Math.max(0, Math.ceil((state.deadline - (Date.now() + offset)) / 1000));
      text = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
    }
    $('[data-timer]', app).textContent = text;
    $('[data-m-timer]', app).textContent = text;
  }
  const tick = setInterval(() => { if (!app.isConnected) return stop(); updateTimer(); }, 500);
  function stop() { closed = true; document.removeEventListener('keydown', onKey); closePhotoDialog(); clearInterval(tick); clearTimeout(pollTimer); }

  // ---------- events ----------
  app.addEventListener('click', (e) => {
    const t = e.target.closest('[data-toggle-role],[data-ready],[data-skip],[data-abstain],[data-target],[data-confirm],[data-vote-confirm],[data-wolf-confirm],[data-open-vote],[data-m-close],[data-pass],[data-witch],[data-guard],[data-start],[data-copy],[data-kick],[data-leave],[data-home],[data-edit-settings],[data-edit-profile]');
    if (!t) return;
    const d = t.dataset;
    if ('toggleRole' in d) { showRole = !showRole; renderRole(); }
    else if ('ready' in d) send({ t: 'ready' });
    else if ('skip' in d) send({ t: 'skip' });
    else if ('abstain' in d) send({ t: 'vote', target: null });
    else if ('target' in d) {
      if (d.mode === 'suggest') send({ t: 'action', target: d.target });
      else { sel = d.target; renderModal(); }
    }
    else if ('voteConfirm' in d) send({ t: 'vote', target: sel });
    else if ('wolfConfirm' in d) send({ t: 'action', confirm: true });
    else if ('openVote' in d) { voteClosedSeq = -1; render(); }
    else if ('mClose' in d) { voteClosedSeq = state.seq; render(); }
    else if ('confirm' in d) send({ t: 'action', target: sel ?? state.me.action?.choice?.target });
    else if ('pass' in d) send({ t: 'action', target: null });
    else if ('guard' in d) send({ t: 'action', save: d.guard === 'true' });
    else if ('witch' in d) send({ t: 'action', heal: witch.heal, poison: witch.poison || null });
    else if ('start' in d) {
      const n = state.players.length;
      if (n < state.total && !confirm(`Start with ${n} players? ${state.total - n} seat(s) will stay empty and the cards will be adjusted.`)) return;
      send({ t: 'start' });
    }
    else if ('editSettings' in d) openSettings();
    else if ('editProfile' in d) openProfile();
    else if ('copy' in d) navigator.clipboard?.writeText(d.copy).then(() => toast('Link copied'));
    else if ('kick' in d) send({ t: 'kick', playerId: d.kick });
    else if ('leave' in d) { send({ t: 'leave' }); leave(); }
    else if ('home' in d) { stop(); navigate(''); }
  });
  app.addEventListener('change', (e) => {
    if (e.target.matches('[data-heal]')) witch.heal = e.target.checked;
    if (e.target.matches('[data-poison]')) witch.poison = e.target.value;
  });
  const onKey = (e) => { if (e.key === 'Escape' && state && popupSpec()?.closable) { voteClosedSeq = state.seq; render(); } };
  document.addEventListener('keydown', onKey);
  $('[data-m-chat-form]', app).addEventListener('submit', (e) => {
    e.preventDefault();
    const input = e.target.text;
    if (input.value.trim()) send({ t: 'chat', text: input.value });
    input.value = '';
  });
  $('[data-chat-form]', app).addEventListener('submit', (e) => {
    e.preventDefault();
    const input = e.target.text;
    if (input.value.trim()) send({ t: 'chat', text: input.value, to: e.target.to.value || null });
    input.value = '';
  });

  poll();
}
