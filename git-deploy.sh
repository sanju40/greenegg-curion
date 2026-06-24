#!/bin/bash

# ─────────────────────────────────────────────────────────────────────────────
# Curion — Auto Deploy Script
# Triggered by git-webhook.php on every push to the main branch.
# ─────────────────────────────────────────────────────────────────────────────

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DIR" || {
  echo "Failed to cd into $DIR"
  exit 1
}

# ── Logging ───────────────────────────────────────────────────────────────────
mkdir -p "$DIR/git-logs"
LOG_FILE="$DIR/git-logs/deploy.log"

# Only write verbose logs if --debug flag is passed by the webhook
LOG_ENABLED=false
for arg in "$@"; do
  [[ "$arg" == "--debug" ]] && LOG_ENABLED=true
done

log() {
  echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

log "──────────────────────────────────────────"
log "Deploy triggered"
log "Running as: $(whoami)"
log "Directory : $DIR"

# ── SSH key (configure if the server uses a dedicated deploy key) ─────────────
# Uncomment and set the correct path to the private key that has read access
# to the GitHub repository (added as a Deploy Key in GitHub repo settings).
#
# export GIT_SSH_COMMAND='ssh -i /home/admin/.ssh/greenegg-curion-deploy -o IdentitiesOnly=yes'

# Prevents git from being confused by environment variables set by the web server
unset GIT_DIR

# ── Branch guard ──────────────────────────────────────────────────────────────
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>> "$LOG_FILE")
log "Branch: $CURRENT_BRANCH"

if [[ "$CURRENT_BRANCH" != "main" ]]; then
  log "Aborted — branch '$CURRENT_BRANCH' is not 'main'"
  exit 0
fi

# ── Pull ──────────────────────────────────────────────────────────────────────
log "git reset --hard"
git reset --hard >> "$LOG_FILE" 2>&1

log "git pull origin main"
git pull origin main >> "$LOG_FILE" 2>&1
PULL_EXIT=$?

if [ $PULL_EXIT -ne 0 ]; then
  log "ERROR — git pull failed (exit code $PULL_EXIT)"
  exit 1
fi

log "git pull succeeded"

# ── PHP dependencies ──────────────────────────────────────────────────────────
# Runs composer install to pick up any new packages added to composer.json.
# Uses the existing composer.lock on the server if present (safe, no version drift).
# Remove or comment out this block if you manage vendor/ manually.
if command -v composer &> /dev/null; then
  log "composer install --no-dev --optimize-autoloader"
  composer install --no-dev --optimize-autoloader --no-interaction >> "$LOG_FILE" 2>&1
  log "composer done (exit: $?)"
else
  log "composer not in PATH — skipping"
fi

# ── Permissions ───────────────────────────────────────────────────────────────
# Ensure the logs/ directory stays writable by the web server
chmod -R 775 "$DIR/logs" 2>> "$LOG_FILE" || true

log "Deploy complete."
log "──────────────────────────────────────────"
