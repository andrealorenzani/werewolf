# Changelog

Newest first. Maintained by the `documenter` agent for every player- or developer-visible change.

## 2026-10-09
### Added
- Agentic workflow extended: `deployer` agent, `scripts/deploy.sh` (uploads runtime files only), changelog duty for the documenter, confidentiality and database-prefix rules.
- Root `.htaccess` hardened: serves `public/` at the domain root, hides `src`, `config`, `data`, `tests`, `docs`, `scripts`.
### Fixed
- Root `.htaccess`: the old-URL redirect from `/public` (without trailing slash) pointed to a wrong filesystem path; it now redirects to the site root.
### Changed
- Documentation no longer names a hosting provider.
