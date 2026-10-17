# Changelog

Newest first. Maintained by the `documenter` agent for every player- or developer-visible change.

## 2026-10-17
### Added
- Evil kill (Werewolf and Mafia) is suggest-then-confirm: `action{target}` suggests, `action{confirm:true}` confirms once all living killers suggest the same player; no pass; timeout/host skip picks randomly among distinct suggestions, none means nobody dies. Views `teamChoices`, `agreed`, `agreedTarget`, `confirmed`, `mine` only for the living evil team.
- Werewolf setup caps (`cap` in `src/games/werewolf.php`, `Config::capFor`, mirrored in `setup.js`): villagers 3-20; werewolves 1 to ceil(non-wolves/3); Seer max 1; Paladin and Witch max ceil(non-wolves/10); Hunter max = werewolves. Early start clamps to the caps, excess goes to the pool.
- Paladins know each other (`me.mates`, `knowsEachOther`), share the charge stock and need all living paladins to save.
- Retention: `Game::endedAt`; `Store::expired`, `peekLive`, `discard`, `lock()`. Ended games are deleted 2 days after the end, others after 7 days idle; expired games answer "Game not found". `info` sweeps about 1 in 20 calls, `create` sweeps.
- Night action, hunter shot and vote open as popups (`role=dialog`); non-actors see "It is night…".
### Changed
- Ghost is no longer a setup card: a hidden `ghostSlot` trait is given to ceil(non-wolves/5) random non-evil players (topped up on late join); any role, including the Seer, rises as ghost when killed by wolves. The old `ghost` role stays as a legacy flag for old saves.
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
