import { getSession } from './store.js';
import { gameDefs, getGame } from './api.js';
import { renderHome } from './views/home.js';
import { renderJoin } from './views/join.js';
import { renderCreate } from './views/create.js';
import { openRoom } from './views/room.js';
import { openImpostorRoom } from './views/impostor-room.js';
import { closeRules } from './rules.js';

const app = document.getElementById('app');

/** Tiny router: the URL hash/query decides the screen; sessions live in localStorage. */
export function navigate(to) {
  // `to` is a query string such as '?game=ABC123' ('' = home); paths stay relative so the app can live in a subdirectory
  history.pushState(null, '', location.pathname + to);
  route();
}

function route() {
  const params = new URLSearchParams(location.search);
  const joinId = params.get('join')?.toUpperCase();
  const gameId = params.get('game')?.toUpperCase();
  const create = params.get('create');
  closeRules();
  app.replaceChildren();
  if (gameId && getSession(gameId)) return openGame(gameId);
  if (joinId) {
    if (getSession(joinId)) return navigate(`?game=${joinId}`);
    return renderJoin(app, joinId, navigate);
  }
  if (create) return renderCreate(app, create, navigate);
  renderHome(app, navigate);
}

/** Pick the room screen by the game's engine (the public game info tells the type); unknown or gone games fall back to the classic room, which handles errors. */
async function openGame(id) {
  let def = null;
  try {
    const [defs, info] = await Promise.all([gameDefs(), getGame(id)]);
    def = defs.find((d) => d.id === info.type);
  } catch { /* the room reports it */ }
  if (new URLSearchParams(location.search).get('game')?.toUpperCase() !== id) return; // navigated away meanwhile
  if (def?.engine === 'impostor') return openImpostorRoom(app, id, navigate, def);
  return openRoom(app, id, navigate);
}

window.addEventListener('popstate', route);
route();
