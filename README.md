# Werewolf & Mafia

Browser-based, turn-based social-deduction games for 4–30 players, written in **plain PHP** (no database, no dependencies) so it runs on ordinary shared hosting.

- 🐺 **Werewolf** – villagers hunt the werewolves hiding among them.
- 🕴️ **Mafia** – the town hunts the criminals who run it.

The server is the game master: it deals the character cards, runs the night actions, counts the votes and announces the winner. Everybody plays from their own browser (phone-friendly).

## How it works
1. Pick a game; set the name, description, your name, an optional avatar (kept in your browser's storage) and **how many of each character** (defaults: 5 villagers/citizens + 1 evil; timers are optional and off by default).
2. You get a unique game code, a link and a **QR code**. Other players scan it or type the code, choose a name/avatar and join.
3. The host starts the game with at least 4 players (the cards are scaled to who is there); latecomers can still join until day 0 ends. Everyone taps their card to see their character. Anyone can edit their name and avatar (file or camera) at any time.
4. Day 0 discussion → night (special characters act secretly) → dawn → discussion → vote → night … until one side wins.

The host can delete a game for everyone from the home page; players can send private messages and the host may replay chats in the end-of-game story. Werewolf includes the Paladin and the Ghost.

Full rules: [`docs/rules.md`](docs/rules.md).

## Requirements
PHP ≥ 8.2 with `json` and `mbstring` (both standard on shared hosting). No Composer install is needed.

## Run locally
```bash
php -S localhost:8000 -t public     # or: composer serve
php tests/run.php                   # or: composer test
```
Open http://localhost:8000. To play with other devices on your network, start with `php -S 0.0.0.0:8000 -t public` and open the app through your LAN address, so the QR code carries a reachable link.

## Deploy on shared hosting
1. Upload the repository (SFTP/Git) to your site's directory.
2. In the panel, set the domain's **web directory to `public/`**. (If you cannot, leave the repo as the web root: the root `.htaccess` forwards everything to `public/` and the `.htaccess` files in `src/`, `data/`, `config/`, `tests/`, `docs/` deny direct access.)
3. Choose PHP 8.2+ for the domain (hosting panel).
4. Make `data/` writable (`chmod 775 data`). Safer: store games outside the web root — copy `config/config.local.php.example` to `config/config.local.php` and set `data_dir` to e.g. `/home/USER/werewolf-data` (create it first).
5. Serve over HTTPS.

Deployment is done by `scripts/deploy.sh [--all] [--dry-run]`: it requires a clean committed tree and passing tests, then uploads only the changed runtime files (no docs, tests, scripts). The target is configured in the gitignored `.deploy.local` (copy `.deploy.local.example`); credentials stay outside the repo. The site is served from the domain root. Never commit host names or credentials.

SEO: the home page and `/how-to-play` are indexable; `robots.txt` and `sitemap.xml` are generated from `Wm\Seo::PAGES` using the request's host (nothing to configure). Game rooms are `noindex`. The deploy only uploads, it never deletes: the old `public/index.html` (replaced by `index.php`) must be removed from the server manually.

Games are deleted after 24 h of inactivity. Timers are evaluated when a player's browser polls, so a game with nobody connected simply waits.

## Project structure
```
public/   web root: index.php, how-to-play.php, robots.php, sitemap.php, api.php, css/, js/ (vanilla JS client)
src/      PHP engine (Game, Api, Store, Config…), Seo.php and src/games/ role definitions
config/   configuration
data/     runtime game files (not served)
tests/    dependency-free test runner
docs/     rules.md, architecture.md, code.md
.claude/  agentic feature workflow
```
Navigation guide: [`docs/code.md`](docs/code.md). Design: [`docs/architecture.md`](docs/architecture.md).

## Adding features with the agentic workflow
```
/feature <description of the feature>
```
Runs `architect` (plan, reads `docs/`) → `implementer` (code + tests) → `reviewer` → `documenter` (updates `README.md` and `docs/`). Agents live in `.claude/agents/`, the orchestration in `.claude/commands/feature.md`, conventions in [`CLAUDE.md`](CLAUDE.md).

## Limitations
No accounts, spectators or translations; state is stored in JSON files; updates arrive by polling (about 1 s).
