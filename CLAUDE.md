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
New features go through `/feature <description>`. After any code change that alters structure, behaviour or API, invoke the `documenter` agent.
