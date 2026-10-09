# Project guide for Claude Code

Werewolf & Mafia: browser social-deduction games. **Plain PHP ≥ 8.2** (namespace `Wm`, PSR-4 `src/`), JSON-file storage, vanilla JS client with polling. Target: Apache shared hosting (no daemons, no WebSockets, no Composer dependencies).

## Run / test
- `php -S localhost:8000 -t public` · `php tests/run.php [filter]` (must pass before finishing).

## Before deciding or searching the code
Read `docs/architecture.md` and `docs/code.md` (and `docs/rules.md` for behaviour).

## Conventions
- `declare(strict_types=1)`; rules live only in `src/Game.php`; roles/narration are data in `src/games/*.php` – never hard-code role ids in the engine.
- Hidden information leaves the server only through `Game::viewFor`; every new field needs a "who may see this?" decision. Never put tokens/avatars in views.
- All state changes happen inside `Store::mutate` (lock + atomic write). Validate everything server-side; player mistakes throw `GameError`.
- Client: no frameworks or build step; escape dynamic text with `esc()`; render through `setHtml`; keep URLs relative (app may live in a subdirectory).
- Every behaviour change needs a test in `tests/`; rule changes need `docs/rules.md` updates.

## Workflow
New features go through `/feature <description>` (architect → implementer → reviewer → documenter → commit → deployer). After any code change that alters structure, behaviour or API, invoke the `documenter` agent. Only the `documenter` edits `docs/` and `README.md`.
- **Changelog**: `docs/changelog.md` must get an entry (by the `documenter`) for every player- or developer-visible change.

## Deployment
- **Every change must end up on the server**: after committing, run `scripts/deploy.sh` (or the `deployer` agent). It uses the `sftp-upload` skill; target and options are in the gitignored `.deploy.local` (template: `.deploy.local.example`), credentials in `~/.password`.
- The site lives at the root of its own domain: the whole runtime tree is uploaded to the server's root directory and the root `.htaccess` serves `public/` at `/`. Docs, tests, scripts and `.claude/` are not uploaded. Keep URLs relative.
- **Confidentiality**: never write the server host, domain name, hosting-provider name, SFTP details, database host or credentials into any tracked file, doc, commit message or agent output. Say "the server"/"shared hosting". Such data may only live in gitignored files (`.deploy.local`) and `~/.password` (never read or print it).

## Database
None today (JSON files). If one is ever needed it must be MySQL via PDO with prepared statements, **all tables prefixed `werewolf_`**, schema changes as SQL files in `migrations/`, and an architecture decision in `docs/architecture.md` first. Host and credentials live only in the gitignored `.deploy.local` / `config/config.local.php` and `~/.password`, never in tracked files, commits or agent output. A DB failure must never break a page.
