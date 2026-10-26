# Changelog

Newest first. Maintained by the `documenter` agent for every player- or developer-visible change.

## 2026-10-26 (pool regeneration)
### Changed
- Built-in Impostor pool fully regenerated: 94 pairs in `resources/impostor-questions.md`; pairs 1-21 are the maintainer's reference samples (adult, crude-but-light, confessional humour), the other 73 follow their style. The old 90 pairs were replaced (deliberate exception to append-only: games in progress may show different questions). `tests/ImpostorQuestionsTest.php` pins the first 21 pairs.
- Skill `.claude/skills/impostor-questions`: tone changed from family-friendly to adult with content limits (no slurs/hatred, no minors, no real private people, no politics/religion, no graphic sexual detail); new `samples.md` (21 samples + analysis) and `research.md` (research notes).
- `docs/rules.md`: note that the built-in questions have an adult tone.

## 2026-10-26
### Added
- Impostor: new question source `host` (only if the host does not play): the host writes up to 50 pairs in the writing phase (commands `hostadd`, `hostdel`, `forcestart`; at least `turns` pairs needed), visible only in God view.
- Impostor: new setting `playerPoints` (0..9, default 0, strictly below `points`): when the Impostor is discovered each present voter of it earns these points. History entries gain `playerPoints` and `awards`; old saves default to 0.
- `src/ImpostorQuestions.php` (`all`, `count`, `file`, `useFile`): strict parser of the question pool; `tests/ImpostorQuestionsTest.php`; project skill `.claude/skills/impostor-questions`.
### Changed
- The built-in Impostor pool moved from `src/games/impostor.php` to the append-only `resources/impostor-questions.md` (90 pairs, up from 69); decks store positions in it. `startTurn`/`beginTurns` turn a pool failure into a `GameError`.
- `resources/` is denied by root `.htaccess` (`RedirectMatch`) and `resources/.htaccess`, and uploaded by `scripts/deploy.sh`.
- Rules popup and `/how-to-play` describe the host source and the new scoring; `updated` bumped to 2026-10-26.

## 2026-10-23
### Added
- Third game, **Impostor**: everyone privately answers the same question except one player (the Impostor), who gets a similar one. Phases lobby, optional writing (players write question pairs), answer, reveal, vote, unveil, result, ended; the Impostor scores (host-set 1-10 points) when not strictly the most voted. Built-in pool of 69 pairs (`src/games/impostor.php`) or pairs written by the players; the host may play or not (God mode: sees the live turn and every pair). 4-30 players, 1-20 turns, vote 30-90 s; answer 180 s, speaker 120 s and unveil 60 s are fixed. No chat. Leaving/kicking: the Impostor leaving cancels the turn, below 4 players ends the game, a leaver's answers and votes are purged. Known limitation: a gone host stalls the lobby and writing phases.
- New files: `src/Impostor.php`, `src/ImpostorConfig.php`, `public/js/views/impostor-room.js`, `public/js/views/impostor-setup.js`, `tests/ImpostorTest.php`, `tests/JsTest.php` (`node --check` of all client scripts). New command set for Impostor (see `docs/code.md`). Rules section added to `docs/rules.md`, `Rules::html` and the popup.
### Changed
- Multi-engine refactor: new `Room` interface, `RoomBase` trait and `Rooms` factory; `Game` implements `Room`; `Store` and `Api` work against `Room`; `Api::info` reports `joinedCount()`. Game definitions may carry `engine`; `Games::engine` and `Games::publicList` (now returns `engine`, plus `limits` for Impostor) expose it; the client router (`main.js` `openGame`) opens the matching room view. Architecture decision 16.
- SEO: site name, titles, descriptions, home FAQ, og alt text and how-to-play heading now say "Werewolf, Mafia & Impostor"; `updated` bumped to 2026-10-23 for both pages; home heading and Impostor game card updated.

## 2026-10-18
### Added
- Werewolf **Cultist** (looks good, wins with the werewolves): turned into a werewolf instead of dying when the werewolves kill him; healed/shielded/protected means no turn; another lethal source the same night just kills him; a same-night poison on him is void and not spent (the Witch is told). Wolves are told he joined; he reads wolf chat only from the turn onward; ghost slot cleared. Counted as good by the Seer, Ghost, parity and Paladin charges; wins with the werewolves even when dead; loses if the last wolf dies unturned. Cap one per werewolf; red name in setup. Known trade-off: setup counts him as good for "villagers must outnumber werewolves".
- Mafia **Corrupt** (neutral): picks Town or Mafia once with the new `side` command (discussion, vote, night, hunter, while alive); never choosing means losing. Knows and is known by mafioso/godfather/detective/vigilante (`mutualWith`); the side stays private until the end.
- Neutral team support: role flags `allegiance`, `convert`, `mutualWith`, `goal`; `Game::sideOf`, `dealtRole`, `chooseSide`, `convertPlayer`; `state['was'|'side'|'turnedAt']`. Neutral players are excluded from the parity check; neutral cards are dealt only while real good still outnumber evil (composition, late join). `Games::publicList` exposes `allegiance`, `goal`, `convert`; `/how-to-play` and the rules popup list the new characters.
- Per-player end result: views carry `won` (`me.won`, `players[].won`) after the game ends; the end banner uses it.

## 2026-10-17
### Added
- In-game rules popup: `Rules::html()` (`src/Rules.php`) is the single source for `/how-to-play` and the popup; public GET API action `rules` -> `{html}`; `public/js/rules.js` (`openRules`, `bindRulesLinks`, `closeRules`; overlay above the modal, accessible, closed on route change); `api.js` `fetchRules` (cached); "How to play" links in the home, create and room headers (real relative links to how-to-play).
- Ghost count is a host setting `ghosts` (0 to number of werewolves, default 0) shown as a black Ghost row in setup (`.ghost-name`, not counted in the players). `Games::publicList` ghost shape {name,icon,desc,min,max,def,cap}; `Config::RANGES` is public. `ghosts` count is public in views, slots stay private.
- Evil kill (Werewolf and Mafia) is suggest-then-confirm: `action{target}` suggests, `action{confirm:true}` confirms once all living killers suggest the same player; no pass; timeout/host skip picks randomly among distinct suggestions, none means nobody dies. Views `teamChoices`, `agreed`, `agreedTarget`, `confirmed`, `mine` only for the living evil team.
- Werewolf setup caps (`cap` in `src/games/werewolf.php`, `Config::capFor`, mirrored in `setup.js`): villagers 3-20; werewolves 1 to ceil(non-wolves/3); Seer max 1; Paladin and Witch max ceil(non-wolves/10); Hunter max = werewolves. Early start clamps to the caps, excess goes to the pool.
- Paladins know each other (`me.mates`, `knowsEachOther`), share the charge stock and need all living paladins to save.
- Retention: `Game::endedAt`; `Store::expired`, `peekLive`, `discard`, `lock()`. Ended games are deleted 2 days after the end, others after 7 days idle; expired games answer "Game not found". `info` sweeps about 1 in 20 calls, `create` sweeps.
- Night action, hunter shot and vote open as popups (`role=dialog`); non-actors see "It is night…".
### Changed
- Ghost is no longer a setup card nor automatic (was ceil(non-wolves/5)): a hidden `ghostSlot` trait goes to as many living non-wolf players (Seer included) as the host setting `ghosts`, clamped to wolves dealt/candidates, topped up on early start and late join (alive or dead holders count). A ghost now rises on any night death (wolf kill or poison, unless saved), never from votes or Hunter shots.
- Old saves: `ghosts` null keeps existing slots and assigns none; old lobby saves start without ghosts; editing settings sets 0.
- The old `ghost` role stays as a legacy flag for old saves.
- Night timer is mandatory: 30-90 s per step, default 60 (empty/0 gives 60). Discussion and vote timers stay optional. Old saves are normalised.
- Witches act independently; heal targets only the wolf victim. A Hunter shooting a Hunter is held in `heldLog` (never in views) until the second shoots or passes; the second shoots blind.
- Player board is no longer clickable; polling an ended game takes no lock. `Games::publicList` passes `cap`, `legacy`, `knowsEachOther`, `ghost`; `/how-to-play` updated.
### Known limitation
- A heal does not protect a poisoned bystander (differs from the requested rule); heal plus poison on the victim kills.
### Removed
- The 24 h inactivity deletion (replaced by the retention above). No list-games API exists; the home shows only locally stored sessions.

## 2026-10-11
### Added
- SEO: `Wm\Seo` (`PAGES`, `head`, `robots`, `sitemap`); public `/how-to-play` page (rules and role lists generated from `src/games`); `/robots.txt` and `/sitemap.xml` generated per request (no domain stored in the repo); Open Graph image `public/img/og.png`; JSON-LD (WebSite, WebPage, FAQ on the home, breadcrumbs); `tests/SeoTest.php`; project skill `.claude/skills/seo/SKILL.md` and SEO duties in `CLAUDE.md`, the agents and `/feature`.
- `public/.htaccess`: `DirectoryIndex index.php`, clean URL `/how-to-play` (301 from `.php`), robots/sitemap routing, caching and compression.
### Changed
- `public/index.html` replaced by `public/index.php` (same app, plus static home content). Game rooms and `?join`/`?create` screens are `noindex,nofollow` with an `X-Robots-Tag` header.
### Fixed
- `Seo::origin()` no longer includes the internal `/public` directory when the repo root is the web root (canonical, og and sitemap URLs were wrong); test added in `tests/SeoTest.php`.
### Removed
- `public/index.html`. Deploy does not delete files, so remove it from the server manually.

## 2026-10-10 (2)
### Added
- Host can delete a game for everyone: API action `delete` (`Api::deleteGame`, `Store::remove`, `Game::canDelete`). The home "Remove" button deletes it for the host; for other players it only forgets the game locally.
- Settings `storyPublicChat` / `storyPrivateChat`: the end-of-game log replays the public chat and/or private chats (DMs, wolf/mafia night chat, graveyard chat) in order with the events. Chat history kept is 300 messages, 1000 when either option is on.
- Private messages (DMs): `chat` command accepts `to`. Not at night; alive to alive, dead to dead; anyone in the lobby and after the end.
- Werewolf: **Paladin** (night step 2: may shield the wolves' victim; charges = number of werewolves in the game, dead ones included) and **Ghost** (if killed by the wolves it haunts: 50% chance of turning evil, points a finger at someone in a new night step 3; public mark "selected by ghost X" for the day).
- Views: `me.ghost`, per-player `markedBy`/`markedText`, action kinds `guard` and `haunt`; `Games::publicList` exposes `nightStep` and `haunts`.
### Changed
- Settings list is now under "Your character" in the room.
- Night has three steps in the engine; `resolveNight` split into `applyNightEffects` and `finishNight`. New state `Game::marks`, `night.dying`, `night.ghosts`; log items carry an ordering key `cs`, chat items `ph` and `to`.
### Removed
- Werewolf **Doctor** (replaced by the Paladin; Mafia keeps its Doctor). In-flight Werewolf games saved with a doctor lose that power after deploy (games expire after 24 h).
### Known limitation
- Re-arming the step 3 deadline can hint that a ghost died.

## 2026-10-10
### Added
- Host can start with 4+ players before the lobby is full; cards are scaled (`Game::composition`) and the rest kept in a hidden `pool`.
- Late join until day 0 discussion ends (`Game::joinable`, `drawLateRole`); `info` and views expose `joinable`. Late joiners can delay day 0 until ready or host skip.
- Host-only `settings` command to edit the setup in the lobby; shared setup form `public/js/views/setup.js`.
- `profile` command and "My profile" editor: change name/avatar in any phase (unique name, 3 s cooldown); camera capture in `avatar.js`; avatars shown for all players.
- Host can skip night steps and the hunter shot.
### Changed
- Timers are optional: 0/empty = off, default off (hunter shot uses the vote timer).
- Default casts: 5 villagers + 1 werewolf / 5 citizens + 1 mafioso; other roles 0.
- Old saves without `pool` still load (late joiners get a filler).

## 2026-10-09
### Added
- Agentic workflow extended: `deployer` agent, `scripts/deploy.sh` (uploads runtime files only), changelog duty for the documenter, confidentiality and database-prefix rules.
- Root `.htaccess` hardened: serves `public/` at the domain root, hides `src`, `config`, `data`, `tests`, `docs`, `scripts`.
### Fixed
- Root `.htaccess`: the old-URL redirect from `/public` (without trailing slash) pointed to a wrong filesystem path; it now redirects to the site root.
### Changed
- Documentation no longer names a hosting provider.
