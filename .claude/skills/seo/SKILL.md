---
name: seo
description: Project SEO rules for Werewolf & Mafia. Use whenever a public page, a game/role, copy, the router or the head markup changes, and before every deploy. Keeps Wm\Seo, sitemap, robots and the static page content up to date.
---
# SEO upkeep (this project)

Generic checklist: the global `seo-page` skill. This file is the project-specific part.

## How SEO is built
- `src/Seo.php` is the single source: `PAGES` (path → title 30–60 chars, description 70–160 chars, `updated` date), `head()` (title, description, canonical, robots, Open Graph, Twitter, JSON-LD), `robots()`, `sitemap()`. The origin comes from the request: **never write the domain in the repo** (see CLAUDE.md confidentiality).
- Public pages are PHP files in `public/` that call `Seo::head('/path')`: `index.php` (home, static content + FAQ JSON-LD) and `how-to-play.php` (rules; role lists come from `src/games/*.php`, so new roles/games appear automatically). `robots.txt` / `sitemap.xml` are served by `robots.php` / `sitemap.php` via `public/.htaccess`.
- Private views (`?game=`, `?join=`, `?create=`, API) are `noindex` (`Seo::head(null)` + `X-Robots-Tag`) and never in the sitemap. Never make them indexable.
- Social image: `public/img/og.png` (1200×630).

## Rules whenever you change something
1. **New public page** → add it to `Seo::PAGES`, give it a unique title/description, one `<h1>`, 250+ words of static HTML, a `.htaccess` clean-URL rule, a link from the home page; it then appears in the sitemap by itself.
2. **Changed copy, rules, roles or games** → update the static text on the affected page(s) and bump `updated` in `Seo::PAGES`. A new game also needs mention in the home/how-to-play copy and the FAQ if relevant.
3. **Client/router change** → the home page must still ship its content as static HTML inside `#app` (the JS replaces it). Keep URLs relative.
4. **Never** hide text for crawlers, add fake ratings/dates, or add structured data for content not visible on the page.
5. Run the linter on the rendered output and fix every FAIL (WARNs about http/localhost are expected locally):
   `php -S localhost:8000 -t public` then `python3 ~/.claude/skills/seo-page/scripts/seo_check.py http://localhost:8000/` (and `/how-to-play.php --url http://localhost:8000/how-to-play`; private view with `--private`).
6. `php tests/run.php` includes `tests/SeoTest.php` (lengths, canonical, JSON-LD, sitemap); extend it when `Seo` changes.
7. After deploy, tell the user to submit `/sitemap.xml` in Google Search Console and Bing Webmaster Tools (we cannot).
