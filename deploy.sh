#!/usr/bin/env bash
set -euo pipefail

DEPLOY_DIR=~/containers/amruta_wp/wp-content/plugins/video-manager-php
DRY_RUN=""

# Parse flags
if [[ "${1:-}" == "--dry-run" || "${1:-}" == "-n" ]]; then
  DRY_RUN="--dry-run"
  echo "[DRY RUN] No files will be copied or deleted."
fi

# Ensure deploy target exists
if [ ! -d "$DEPLOY_DIR" ]; then
  echo "Error: Deploy directory not found: $DEPLOY_DIR" >&2
  exit 1
fi

# 1. Test and lint
echo "Running PHP unit tests..."
docker compose -f containers/video-manager-test/docker-compose.yml --profile test run --rm --no-deps phpunit
echo "Running unit tests..."
npm test
echo "Running ESLint..."
npm run lint
echo "Running PHPCS..."
npm run php:lint

# 2. Build
echo "Building..."
npm run build

# 3. Sync production files
echo "Deploying to $DEPLOY_DIR..."
rsync -av --delete ${DRY_RUN} \
  --exclude-from='.distignore' \
  ./ "$DEPLOY_DIR/"

echo "Deploy complete."
