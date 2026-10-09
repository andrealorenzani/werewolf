#!/usr/bin/env bash
# Upload committed runtime files to the server through the sftp-upload skill's uploader.
# Config: .deploy.local (gitignored; see .deploy.local.example). State: .deploy-state (gitignored).
# Usage: scripts/deploy.sh [--all] [--dry-run]
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f .deploy.local ] || { echo "error: .deploy.local missing (copy .deploy.local.example)" >&2; exit 2; }
# shellcheck disable=SC1091
source .deploy.local
: "${DEPLOY_HOST:?}" "${DEPLOY_DIR:?}"
UPLOADER="${DEPLOY_UPLOADER:-$HOME/.claude/skills/sftp-upload/scripts/upload.py}"
ALL=0; DRY=0
for a in "$@"; do case "$a" in --all) ALL=1;; --dry-run) DRY=1;; *) echo "unknown option $a" >&2; exit 2;; esac; done

# Only committed code is deployed, so the server always matches a known commit.
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "error: uncommitted changes; commit first" >&2; exit 2
fi
php tests/run.php >/dev/null || { echo "error: tests failing; not deploying" >&2; exit 1; }

# Runtime files only: no docs, tests, scripts or agent configuration on the server.
RUNTIME='^(\.htaccess|public/|src/|config/(config\.php|\.htaccess)$|data/(\.htaccess|\.gitkeep)$)'

HEAD_SHA=$(git rev-parse HEAD)
LAST=""; [ -f .deploy-state ] && LAST=$(cat .deploy-state)
if [ "$ALL" = 1 ] || [ -z "$LAST" ] || ! git cat-file -e "$LAST^{commit}" 2>/dev/null; then
  FILES=$(git ls-files | grep -E "$RUNTIME" || true)
else
  FILES=$(git diff --name-only --diff-filter=ACMR "$LAST" HEAD | grep -E "$RUNTIME" || true)
  git diff --name-only --diff-filter=D "$LAST" HEAD | grep -E "$RUNTIME" | sed 's/^/not removed on server (delete manually): /' >&2 || true
fi
[ -n "$FILES" ] || echo "no runtime files changed"

TRUST=(); [ "${DEPLOY_TRUST_NEW_HOST:-0}" = 1 ] && TRUST=(--trust-new-host)
while IFS= read -r f; do
  [ -n "$f" ] || continue
  d=$(dirname "$f"); dir="${DEPLOY_DIR%/}/"; [ "$d" != "." ] && dir="${DEPLOY_DIR%/}/$d"
  if [ "$DRY" = 1 ]; then echo "would upload $f"; continue; fi
  # --force: files of this project are overwritten on purpose (updates).
  python3 "$UPLOADER" "$f" --host "$DEPLOY_HOST" --dir "$dir" --force "${TRUST[@]}" >/dev/null
  echo "uploaded $f"
done <<< "$FILES"

[ "$DRY" = 1 ] || echo "$HEAD_SHA" > .deploy-state
