#!/usr/bin/env bash
# Runs ON THE VPS, invoked over SSH by .github/workflows/deploy.yml.
# Not meant to be run by hand except for manual recovery — see
# ../DEPLOYMENT.md "Manual deploy / rollback".
#
# Flat layout — each deploy overwrites in place, no release history:
#   /var/www/email-verifier/
#     backend/    Laravel app; .env and storage/ are preserved across
#                 deploys (excluded from the sync), everything else is
#                 overwritten
#     frontend/   built Vite output, fully stateless, fully overwritten
set -euo pipefail

RELEASE_FILE="$1"
SHA="${2:-unknown}"
APP_DIR="/var/www/email-verifier"
EXTRACT_DIR="$APP_DIR/incoming/extracted"

if [ ! -f "$APP_DIR/backend/.env" ]; then
  echo "Missing $APP_DIR/backend/.env — one-time server setup isn't done yet." >&2
  echo "See DEPLOYMENT.md 'One-time VPS setup'." >&2
  exit 1
fi

echo "==> Extracting $RELEASE_FILE"
rm -rf "$EXTRACT_DIR"
mkdir -p "$EXTRACT_DIR"
tar -xzf "$APP_DIR/incoming/$RELEASE_FILE" -C "$EXTRACT_DIR"

echo "==> Syncing backend/ (preserving .env and storage/)"
mkdir -p "$APP_DIR/backend"
rsync -a --delete \
  --exclude='.env' \
  --exclude='storage/' \
  "$EXTRACT_DIR/backend/" "$APP_DIR/backend/"

echo "==> Syncing frontend/"
mkdir -p "$APP_DIR/frontend/dist"
rsync -a --delete "$EXTRACT_DIR/frontend/dist/" "$APP_DIR/frontend/dist/"

echo "==> Running migrations, seeding, and caching config"
cd "$APP_DIR/backend"
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan event:cache

echo "==> Reloading PHP-FPM and restarting workers"
sudo /usr/bin/systemctl reload php8.3-fpm
sudo /usr/bin/supervisorctl restart email-verifier:*

rm -rf "$EXTRACT_DIR"
rm -f "$APP_DIR/incoming/$RELEASE_FILE" "$APP_DIR/incoming/remote-deploy.sh"

echo "==> Deployed $SHA"
