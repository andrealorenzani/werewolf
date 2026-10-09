# Changelog

Newest first. Maintained by the `documenter` agent for every player- or developer-visible change.

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
