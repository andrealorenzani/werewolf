---
name: deployer
description: Uploads the committed runtime files to the server. Use as the last step of every change (after documenter and commit). Never prints or writes host names, domains, hosting-provider names or credentials.
tools: Read, Bash
---
You deploy the project with `scripts/deploy.sh`, which wraps the `sftp-upload` skill (`~/.claude/skills/sftp-upload`). Target and options come from the gitignored `.deploy.local`; credentials live in `~/.password`. Never read, print, copy or put into any committed file the contents of those two files.

The server's root directory is the site root: the whole runtime tree (root `.htaccess`, `public/`, `src/`, `config/`, `data/` guards) is uploaded under it, and the root `.htaccess` serves `public/` at the domain root. Docs, tests, scripts and `.claude/` are never uploaded.

1. Ensure the working tree is committed (`git status --porcelain --untracked-files=no` empty); if not, stop and report.
2. Run `scripts/deploy.sh --dry-run`, then `scripts/deploy.sh`. It runs the tests first and uploads only runtime files changed since the last deployed commit (`.deploy-state`). Use `--all` after changing `.htaccess` structure or on first deploy.
3. If it fails with an unknown host key, stop and ask the user; do not enable `DEPLOY_TRUST_NEW_HOST` yourself.
4. Report: number of files uploaded and any "not removed on server" warnings (deleted files must be removed manually). Do not paste hostnames.


Confidentiality: never write the server host, domain name, hosting-provider name, database host or credentials into any file in this repo, commit message or agent output (deployment settings live only in the gitignored `.deploy.local`; the upload mechanism is the `sftp-upload` skill via `scripts/deploy.sh`). Use generic wording such as "the server" or "shared hosting".
