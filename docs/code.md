# Code map

> Maintained by the `documenter` agent. For the *why*, see [`architecture.md`](architecture.md); for game behaviour, [`rules.md`](rules.md).

## Entry points and request flow
| Entry | Flow |
|---|---|
| `public/index.html` | Static shell → `js/main.js` (router) → a view |
| `public/api.php?a=<action>` | Reads JSON body → `Wm\Api::handle` → `Store` → `Game` → JSON |
| `php tests/run.php` | Loads `tests/bootstrap.php` and every `tests/*Test.php` |

Typical play request: `room.js poll()` → `api.js pollState` → `api.php` → `Api::state` → (fast path `Store::peek`) or `Store::mutate` → `Game::tick`, `Game::touch`, `Game::viewFor` → JSON `{state}` → `room.js applyState` → region renderers.

## API actions (`src/Api.php`, via `public/api.php?a=…`)
| Action | HTTP | Input | Output | Handler |
|---|---|---|---|---|
| `defs` | GET | – | `{games:[…]}` | `Games::publicList` |
| `create` | POST | `type,name,description,roles{id:n},settings,player{name,avatar}` | `{id,playerId,token}` | `Api::create` |
| `info` | GET `&id=` | – | name, phase, `joinable`, joined/total | `Api::info` |
| `join` | POST | `id, player` | `{id,playerId,token}` | `Api::join` |
| `state` | POST | `id, token, v` | `{unchanged:true,now}` or `{state}` | `Api::state` |
| `avatars` | POST | `id, token, ids[]` | `{avatars:{playerId:dataURL}}` | `Api::avatars` |
| `delete` | POST | `id, token` | `{deleted:true}` (host only) | `Api::deleteGame` → `Store::remove` |
| `cmd` | POST | `id, token, cmd{t,…}` | `{state}` (or `{left:true}`) | `Api::command` → `Game::command` |

Game commands (`cmd.t`): `start` (host, ≥4 players), `settings{…create payload…}` (host, lobby), `profile{name,avatar?}` (any player, any phase; avatar absent = keep, null = remove, data URL = replace), `kick{playerId}`, `leave`, `chat{text,to?}` (`to` = private message), `ready`, `skip` (host: discussion, vote, night, hunter), `vote{target|null}`, `action{target}` / `action{heal,poison}`.
Errors: `{error}` with HTTP 400 (`GameError`) or 500.

## PHP (`src/`, namespace `Wm`)
| File | Responsibility |
|---|---|
| `bootstrap.php` | Autoloader `Wm\Foo → src/Foo.php`, timezone/encoding |
| `Api.php` | Action dispatch, auth by token, lock-free poll fast path |
| `Game.php` | The engine: lobby, phases, timers (`tick`), voting, night resolution, win check, `viewFor`, (de)serialisation |
| `Player.php` | Plain data for one seat (incl. `profileAt` rate limit) |
| `Config.php` | `normalize` (setup validation, limits 4–30 players, timer ranges, 0/empty = off), `profile` (name/avatar), `clean` |
| `Store.php` | One JSON file per game: `create`, `peek`, `mutate` (flock + atomic write), `remove` (guarded delete under the lock), `sweep`, `validId` |
| `Games.php` | Registry of game ids, `get`, `publicList` |
| `GameError.php` | Exception whose message is shown to players |
| `games/werewolf.php`, `games/mafia.php` | Data-only definitions: teams, narration strings, roles |

Key `Game` methods: `command` (router) · `composition` (dealt/pool split) · `joinable`, `addPlayer`, `drawLateRole` (late join) · `configure` (host `settings`) · `updateProfile` · `enter` (phase entry) · `tick`/`advance` (timers) · `action`, `actorsForStep`, `canAct`, `usesLeft`, `advanceNight`, `applyNightEffects`, `finishNight` (night, 3 steps) · `chatChannel`, `chatMessage` (channels, DMs, retention), `storyLog` (end-of-game log with chat), `canDelete` · `vote`, `resolveVote` · `kill`, `afterDeaths`, `finishHunter`, `checkWin` · `viewFor`, `actionFor`, `canSeeRole` (what a player may see).

## JavaScript (`public/js`)
| File | Responsibility |
|---|---|
| `main.js` | Router: `?create=TYPE`, `?join=ID`, `?game=ID`, otherwise home; `navigate('?x=…')` |
| `api.js` | `api.php` wrappers (incl. `deleteGame`), `joinUrl`, `qrDataUrl` |
| `store.js` | `localStorage`: profile (name+avatar), sessions (token per game) |
| `avatar.js` | Image → 96 px JPEG (`fileToAvatar`, `canvasToAvatar`), camera dialog (`takePhotoDialog`/`closePhotoDialog`, file-input fallback), avatar HTML, profile form |
| `util.js` | `esc`, `toast`, `setHtml` (skip unchanged HTML), `$` |
| `views/home.js` | Choose game, join by code, saved games (Rejoin / Remove: host deletes via `deleteGame`, others forget locally) |
| `views/setup.js` | `buildSetupForm`: shared setup form (name, description, role counts, optional timers), used by create and the room's host settings |
| `views/create.js` | Create page: profile + setup form |
| `views/join.js` | Join form for QR links |
| `views/room.js` | Lobby (host settings, avatars) + game UI, "My profile" editor, polling loop, command sending |
| `vendor/qrcode.js` | Vendored `qrcode-generator` 2.0.4 (MIT) |
| `css/app.css` | All styles (light/dark via `prefers-color-scheme`) |

## Data and storage
No database. `data/<ID>.json` + `data/<ID>.lock` written by `Store`; layout of the JSON is `Game::toArray()`. Configuration: `config/config.php` (`data_dir`), override with `config/config.local.php` or env `WM_DATA_DIR`.

## Tests (`tests/`)
| File | Content |
|---|---|
| `run.php` | Runner (`php tests/run.php [name-filter]`) |
| `bootstrap.php` | `T` assertions, `Clock`, `startedGame()`, `toNight()`, `act()` |
| `EngineTest.php` | Rules: config, phases, night, vote, win, hunter, witch, escort, vigilante, visibility, chat, lazy timers, optional timers, composition/pool, late join, host settings, delete, DMs, story chat, paladin, ghost, profile, serialisation |
| `ApiTest.php` | API + storage: create/join/poll/commands, delete, avatars, unchanged polls, lazy timers, id validation, sweep |
Naming: `T::test('what it proves', fn)`; one behaviour per test.

## Agentic workflow
`CLAUDE.md` (conventions) · `.claude/commands/feature.md` (`/feature <text>`) · `.claude/agents/{architect,implementer,reviewer,documenter,deployer}.md`. Deployment: `scripts/deploy.sh [--all] [--dry-run]` (runs tests, uploads only changed runtime files from committed code via the `sftp-upload` skill; target in gitignored `.deploy.local`, template `.deploy.local.example`; last deployed commit in gitignored `.deploy-state`). Root `.htaccess` serves `public/` at the site root, redirects `/public/…` to `/…` and 404s `src|config|data|tests|docs|scripts|.claude|.git`. `docs/changelog.md`: one entry per visible change (documenter).

## Where do I…?
| Task | Where |
|---|---|
| Add/edit a role using existing powers | `src/games/<game>.php`, then `docs/rules.md`, tests |
| Add a new night power | `Game::validTargets`, `actorsForStep`/`canAct`, `action`, `applyNightEffects`, `actionFor`; UI in `room.js` `actionForm`; tests |
| Add a whole new game | `src/games/<id>.php` + id in `Games::IDS`; UI picks it up automatically |
| Change phase/vote/win rules | `Game.php`, `docs/rules.md`, `EngineTest.php` |
| Change setup limits/defaults | `Config.php`, role `def` in `src/games/*.php` (+ hints in `views/setup.js`) |
| Change early-start / late-join rules | `Game::composition`, `joinable`, `drawLateRole`, `docs/rules.md`, `EngineTest.php` |
| Change chat channels / DMs / story replay | `Game::chatChannel`, `chatMessage`, `storyLog`, `viewFor`, `Config::normalize` |
| Change what a player may see | `Game::viewFor`, `canSeeRole`, `actionFor` |
| Add an API action | `Api::dispatch` + handler, `api.js` wrapper, `ApiTest.php`, table above |
| Add a game command | `Game::command` switch + method, handler in `room.js` click listener, test |
| Add a page | new `views/*.js` + branch in `main.js` `route()` |
| Change storage location | `config/config.local.php` |
| Deploy / change what is uploaded | `scripts/deploy.sh` (`RUNTIME` regex), `.deploy.local` |
| Log a change | `docs/changelog.md` |
| Add a test | `tests/EngineTest.php` (rules) or `ApiTest.php` (API) with `T::test` |
