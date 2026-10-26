# Code map

> Maintained by the `documenter` agent. For the *why*, see [`architecture.md`](architecture.md); for game behaviour, [`rules.md`](rules.md).

## Entry points and request flow
| Entry | Flow |
|---|---|
| `public/index.php` | Server-rendered shell (`Seo::head`, static home content, FAQ JSON-LD; noindex + `X-Robots-Tag` for `?game/?join/?create`) → `js/main.js` (router) → a view |
| `public/how-to-play.php` | Public rules/roles page (body from `Rules::html`), served at `/how-to-play` |
| `public/robots.php`, `public/sitemap.php` | Served as `/robots.txt`, `/sitemap.xml` (`Seo::robots()`, `Seo::sitemap()`); routing in `public/.htaccess` |
| `public/api.php?a=<action>` | Reads JSON body → `Wm\Api::handle` → `Store` → `Game` → JSON |
| `php tests/run.php` | Loads `tests/bootstrap.php` and every `tests/*Test.php` |

Typical play request: `room.js poll()` → `api.js pollState` → `api.php` → `Api::state` → (fast path `Store::peek`) or `Store::mutate` → `Game::tick`, `Game::touch`, `Game::viewFor` → JSON `{state}` → `room.js applyState` → region renderers.

## API actions (`src/Api.php`, via `public/api.php?a=…`)
| Action | HTTP | Input | Output | Handler |
|---|---|---|---|---|
| `defs` | GET | – | `{games:[…]}` (each with `engine`; Impostor adds `limits`) | `Games::publicList` |
| `create` | POST | `type,name,description,roles{id:n},settings,player{name,avatar}` (Impostor: `seats` instead of `roles`, settings `hostPlays,source,turns,voteSeconds,points,playerPoints`; validated by `Rooms::config`) | `{id,playerId,token}` | `Api::create` |
| `info` | GET `&id=` | – | name, phase, `joinable`, joined/total | `Api::info` |
| `join` | POST | `id, player` | `{id,playerId,token}` | `Api::join` |
| `state` | POST | `id, token, v` | `{unchanged:true,now}` or `{state}` | `Api::state` |
| `avatars` | POST | `id, token, ids[]` | `{avatars:{playerId:dataURL}}` | `Api::avatars` |
| `delete` | POST | `id, token` | `{deleted:true}` (host only) | `Api::deleteGame` → `Store::remove` |
| `cmd` | POST | `id, token, cmd{t,…}` | `{state}` (or `{left:true}`) | `Api::command` → `Game::command` |

Game commands (`cmd.t`): `start` (host, ≥4 players), `settings{…create payload…}` (host, lobby), `profile{name,avatar?}` (any player, any phase; avatar absent = keep, null = remove, data URL = replace), `kick{playerId}`, `leave`, `chat{text,to?}` (`to` = private message), `ready`, `skip` (host: discussion, vote, night, hunter), `side{side}` (alive player whose role has `allegiance: choose`, once; phases discussion/vote/night/hunter), `vote{target|null}`, `action{target}` / `action{confirm:true}` (evil kill: suggest, then confirm) / `action{heal,poison}`.
Impostor commands (`cmd.t`, engine `Impostor`): `start` (host, ≥4 playing), `settings` (host, lobby), `profile`, `kick{playerId}`, `leave`, `write{normal,impostor}` / `confirm` (players source) / `hostadd{normal,impostor}` / `hostdel{index}` (host source) / `forcestart` (host) in the writing phase, `answer{text}`, `done` (current speaker), `startvote` (host, during reveal), `vote{target|null}`, `unveil` (the Impostor), `next` (after a result), `skip` (host: answer/reveal/vote/unveil). No chat, ready, side or action commands.
Errors: `{error}` with HTTP 400 (`GameError`) or 500.

## PHP (`src/`, namespace `Wm`)
| File | Responsibility |
|---|---|
| `bootstrap.php` | Autoloader `Wm\Foo → src/Foo.php`, timezone/encoding |
| `Api.php` | Action dispatch, auth by token, lock-free poll fast path; works against `Room` via `Rooms` |
| `Game.php` | The engine: lobby, phases, timers (`tick`), voting, night resolution, win check, `viewFor`, (de)serialisation |
| `Room.php` | Interface both engines implement (`fromArray`, `toArray`, `tick`, `touch`, `addPlayer`, `joinable`, `joinedCount`, `command`, `viewFor`, `avatars`, `canDelete`…); what `Store`/`Api` use |
| `RoomBase.php` | Trait with the shared plumbing for `Impostor`: seats, tokens, presence, `updateProfile`, `removePlayer` (leave/kick, hooks `seat()`/`dropped()`), clock/rng, `shuffle`, `avatars` |
| `Rooms.php` | Factory: `config` (validate create request), `create`, `load` for the right engine (`Games::engine`) |
| `Impostor.php` | The Impostor engine: lobby, writing, turn phases (answer, reveal, vote, unveil, result), lazy timers, host-written pairs (`hostAdd`/`hostDel`), scoring (`finishTurn`, incl. `playerPoints` awards), leave handling (`dropped`), `winners`, `viewFor` (incl. God block) |
| `ImpostorQuestions.php` | Built-in question pool reader: `all()` (list of `[normal, impostor]`, cached), `count()`, `file()`, `useFile(?path)` (tests); strict parser of `resources/impostor-questions.md`, throws `\RuntimeException` |
| `ImpostorConfig.php` | `normalize` (setup validation: seats 4–30, turns 1–20, vote 30–90 s, points 1–10, points-for-finders `playerPoints` 0–9 and < points, source `pool`/`players`/`host` (host only if `hostPlays` is false), `hostPlays`), `limits()` for the client |
| `Player.php` | Plain data for one seat (incl. `profileAt` rate limit) |
| `Config.php` | `normalize` (setup validation, limits 4–30 players, role caps via `capFor`, discussion/vote timer ranges 0/empty = off, `nightSeconds` mandatory 30–90, default 60), `profile` (name/avatar), `clean` |
| `Store.php` | One JSON file per game: `create`, `peek`, `mutate` (flock + atomic write), `remove` (guarded delete under the lock), `expired` (retention rule: ended +2 days, idle 7 days), `peekLive` (peek hiding expired games), `discard` (delete under lock), private `lock()`, `sweep`, `validId` |
| `Seo.php` | `PAGES` (indexable pages: title, description, `updated`), `head()` (meta, canonical, Open Graph, JSON-LD; noindex for private views), `robots()`, `sitemap()`, `origin()`/`url()` from the request |
| `Rules.php` | `Rules::html(bool $links)` (private `impostor()` renders the Impostor section from `ImpostorConfig`/`Impostor` constants): rules/character guide as trusted escaped HTML; single source for `/how-to-play` and the popup (values from `Config`, roles from `Games`) |
| `Games.php` | Registry of game ids (`werewolf`, `mafia`, `impostor`), `get`, `engine` (`classic`/`impostor`), `publicList` (every entry has `engine`; Impostor entries carry `title,icon,tagline,pitch,limits` only; classic onesrole data incl. `cap`, `legacy`, `knowsEachOther`, `allegiance`, `goal`, `convert` (bool), `ghost` = {name,icon,desc,min,max,def,cap}) |
| `GameError.php` | Exception whose message is shown to players |
| `games/werewolf.php`, `games/mafia.php` | Data-only definitions: teams, narration strings, roles |
| `games/impostor.php` | Data-only: `engine => impostor`, title/icon/tagline/pitch (the questions are in `resources/impostor-questions.md`) |

Evil kill: `action` stores suggestions, `agreedTarget` and `teamChoices` (view) drive the confirm step; ghost trait: `ghostSlot` in player state, count from setting `ghosts` (null in old saves), `hauntSpec`; `Config::RANGES` is public; `heldLog` + `flushHeld` hold the Hunter-shoots-Hunter log line; `endedAt` feeds `Store::expired`.

Key `Game` methods: `command` (router) · `composition` (dealt/pool split) · `joinable`, `addPlayer`, `drawLateRole` (late join) · `configure` (host `settings`) · `updateProfile` · `enter` (phase entry) · `tick`/`advance` (timers) · `action`, `actorsForStep`, `canAct`, `usesLeft`, `advanceNight`, `applyNightEffects`, `finishNight` (night, 3 steps) · `chatChannel`, `chatMessage` (channels, DMs, retention), `storyLog` (end-of-game log with chat), `canDelete` · `vote`, `resolveVote` · `kill`, `afterDeaths`, `finishHunter`, `checkWin` · `sideOf` (side whose win counts for the player), `dealtRole`, `chooseSide` (command `side`), `convertPlayer` (Cultist turn, from `applyNightEffects`) · `viewFor`, `actionFor`, `canSeeRole` (what a player may see; honours `mutualWith`). `viewFor` adds `me.side` (own, `choose` roles only) and, after the end, `me.won` and `players[].won`.

## JavaScript (`public/js`)
| File | Responsibility |
|---|---|
| `main.js` | Router: `?create=TYPE`, `?join=ID`, `?game=ID`, otherwise home; `navigate('?x=…')`. `openGame` picks `openRoom` or `openImpostorRoom` by the engine of the game's type (`gameDefs` + `getGame`) |
| `api.js` | `api.php` wrappers (incl. `deleteGame`, cached `fetchRules`), `joinUrl`, `qrDataUrl` |
| `rules.js` | Rules popup: `openRules`, `closeRules`, `bindRulesLinks` (body-level overlay above the modal, a11y, closed on route change/room stop) |
| `store.js` | `localStorage`: profile (name+avatar), sessions (token per game) |
| `avatar.js` | Image → 96 px JPEG (`fileToAvatar`, `canvasToAvatar`), camera dialog (`takePhotoDialog`/`closePhotoDialog`, file-input fallback), avatar HTML, profile form |
| `util.js` | `esc`, `toast`, `setHtml` (skip unchanged HTML), `$` |
| `views/home.js` | Choose game, join by code, saved games (Rejoin / Remove: host deletes via `deleteGame`, others forget locally) |
| `views/setup.js` | `buildSetupForm` (cultist shown with the red evil name, neutral count in the summary): shared setup form (name, description, role counts with caps mirrored from `Config::capFor`, ghost count row `.ghost-name`, timers), used by create and the room's host settings |
| `views/create.js` | Create page: profile + setup form (`buildImpostorSetup` for engine `impostor`, else `buildSetupForm`) |
| `views/impostor-setup.js` | `buildImpostorSetup` (seats, source incl. host, turns, vote seconds, points, points for finding the Impostor, host plays; limits from `def.limits`), `impostorSettingsLines` |
| `views/impostor-room.js` | `openImpostorRoom`: lobby (host settings, avatars, QR), writing form, answer / reveal / vote / unveil / result screens, scoreboard, host question editor, God view, polling and commands |
| `views/join.js` | Join form for QR links |
| `views/room.js` | Lobby (host settings, avatars) + game UI (night action, hunter shot and vote as `role=dialog` popups; "It is night…" for non-actors; evil kill suggest/confirm UI; `sidePanel` with `data-side` buttons for the Corrupt; end banner from `me.won`), "My profile" editor, polling loop, command sending |
| `vendor/qrcode.js` | Vendored `qrcode-generator` 2.0.4 (MIT) |
| `css/app.css` | All styles (light/dark via `prefers-color-scheme`) |

## Data and storage
No database. `data/<ID>.json` + `data/<ID>.lock` written by `Store`; layout of the JSON is `Game::toArray()`. Configuration: `config/config.php` (`data_dir`), override with `config/config.local.php` or env `WM_DATA_DIR`.

## Tests (`tests/`)
| File | Content |
|---|---|
| `run.php` | Runner (`php tests/run.php [name-filter]`) |
| `bootstrap.php` | `T` assertions, `Clock`, `startedGame()`, `toNight()`, `act()` |
| `EngineTest.php` | Rules: config, phases, night, vote, win, hunter, witch, escort, vigilante, visibility, chat, lazy timers, optional timers, composition/pool, late join, host settings, delete, DMs, story chat, paladin, cultist conversion, corrupt/neutral side, ghost trait (host-set count), rules popup/API, kill agreement, hunter vs hunter, caps, retention, profile, serialisation |
| `ApiTest.php` | API + storage: create/join/poll/commands, delete, avatars, unchanged polls, lazy timers, id validation, sweep, Impostor create/info/poll over the API |
| `ImpostorQuestionsTest.php` | Pool file: real file (304 pairs, first 21 pinned, format, uniqueness), strict parser errors via `useFile`, deck positions |
| `ImpostorTest.php` | Impostor engine: setup validation, lobby/God host, writing, turn phases, scoring and ties, winners, leave/kick, timeouts, view secrecy, serialisation (`startedImpostor()` helper in `bootstrap.php`) |
| `JsTest.php` | `node --check` on every `public/js` file (skipped without Node) |
| `SeoTest.php` | `Seo`: title/description lengths, indexable vs noindex head, JSON-LD, sitemap and robots, Impostor in titles/how-to-play/home |
Naming: `T::test('what it proves', fn)`; one behaviour per test.

## Agentic workflow
`CLAUDE.md` (conventions) · `.claude/commands/feature.md` (`/feature <text>`) · `.claude/agents/{architect,implementer,reviewer,documenter,deployer}.md` · `.claude/skills/seo/SKILL.md` (SEO upkeep checklist) · `.claude/skills/impostor-questions/SKILL.md` (rules for extending the question pool: adult tone with content limits; `samples.md` = the 21 reference pairs + analysis, `research.md` = research notes). Deployment: `scripts/deploy.sh [--all] [--dry-run]` (runs tests, uploads only changed runtime files from committed code via the `sftp-upload` skill; target in gitignored `.deploy.local`, template `.deploy.local.example`; last deployed commit in gitignored `.deploy-state`). Root `.htaccess` serves `public/` at the site root, redirects `/public/…` to `/…` and 404s `src|config|data|resources|tests|docs|scripts|.claude|.git`. `docs/changelog.md`: one entry per visible change (documenter).

## Where do I…?
| Task | Where |
|---|---|
| Add/edit a role using existing powers | `src/games/<game>.php`, then `docs/rules.md`, tests |
| Add a new night power | `Game::validTargets`, `actorsForStep`/`canAct`, `action`, `applyNightEffects`, `actionFor`; UI in `room.js` `actionForm`; tests |
| Add a whole new game of the Werewolf/Mafia family | `src/games/<id>.php` + id in `Games::IDS`; UI picks it up automatically |
| Add a game with different rules (new engine) | `src/games/<id>.php` with a new `engine`, a class implementing `Room` (use `RoomBase`), a config class, branches in `Rooms`/`Games::engine`/`publicList`, a room view + branch in `main.js` `openGame`, `Rules::html`, tests |
| Change Impostor rules (phases, scoring, timers, leaving) | `Impostor.php`, limits in `ImpostorConfig.php`, `Rules::impostor`, `docs/rules.md`, `ImpostorTest.php`; questions in `resources/impostor-questions.md` (append-only, `ImpostorQuestions.php`, skill `impostor-questions`) |
| Change phase/vote/win rules (Werewolf/Mafia) | `Game.php`, `docs/rules.md`, `EngineTest.php` (Impostor: see above) |
| Change setup limits/defaults/caps | `Config.php` (`capFor`, `normalize`), role `def`/`max`/`cap` in `src/games/*.php`, mirror in `views/setup.js`, `Game::composition` clamp |
| Change the rules text (page and popup) | `Rules::html`; `public/how-to-play.php` only wraps it; popup in `public/js/rules.js` |
| Change retention (delete after N days) | `Store::IDLE_MS`/`ENDED_MS`, `expired`, `peekLive`, `sweep`; `Game::endedAt` |
| Change evil-kill agreement / Paladin / Witch / ghost trait | `Game::action`, `agreedTarget`, `applyNightEffects`, `hauntSpec`, `viewFor`; `room.js` popups |
| Change early-start / late-join rules | `Game::composition`, `joinable`, `drawLateRole`, `docs/rules.md`, `EngineTest.php` |
| Change chat channels / DMs / story replay | `Game::chatChannel`, `chatMessage`, `storyLog`, `viewFor`, `Config::normalize` |
| Add a role that converts, has its own allegiance or is neutral | flags `allegiance`, `convert`, `mutualWith`, `goal` in `src/games/*.php`; `Game::sideOf`, `convertPlayer`, `chooseSide`, `checkWin`, `composition`; tests |
| Change what a player may see | `Game::viewFor`, `canSeeRole`, `actionFor` |
| Add an API action | `Api::dispatch` + handler, `api.js` wrapper, `ApiTest.php`, table above |
| Add a game command | `Game::command` switch + method, handler in `room.js` click listener, test |
| Add a page | new `views/*.js` + branch in `main.js` `route()` |
| Change storage location | `config/config.local.php` |
| Deploy / change what is uploaded | `scripts/deploy.sh` (`RUNTIME` regex), `.deploy.local` |
| Add/change a public page, title, description, role/game copy | `Seo::PAGES` (bump `updated`), the page in `public/`, `public/.htaccess` route, `SeoTest.php`, `.claude/skills/seo/SKILL.md` |
| Change robots/sitemap/meta tags | `Seo::robots`, `sitemap`, `head` |
| Log a change | `docs/changelog.md` |
| Add a test | `tests/EngineTest.php` (rules) or `ApiTest.php` (API) with `T::test` |
