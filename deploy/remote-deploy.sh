#!/usr/bin/env bash
# Runs ON THE VPS, invoked over SSH by .github/workflows/deploy.yml.
# Not meant to be run by hand except for manual recovery — see
# ../DEPLOYMENT.md "Manual deploy / rollback".
#
# Zero-downtime release layout (Capistrano-style):
#   /var/www/email-verifier/
#     releases/<git-sha>/backend/      composer install already run in CI
#     releases/<git-sha>/frontend/dist/  npm run build already run in CI
#     shared/backend/.env               persists across releases, never in git
#     shared/backend/storage/           persists across releases (uploads, logs)
#     current -> releases/<git-sha>     atomic symlink, what Nginx/PHP-FPM serve
set -euo pipefail

RELEASE_FILE="$1"
SHA="$2"
APP_DIR="/var/www/email-verifier"
RELEASE_DIR="$APP_DIR/releases/$SHA"
KEEP_RELEASES=3

if [ ! -f "$APP_DIR/shared/backend/.env" ]; then
  echo "Missing $APP_DIR/shared/backend/.env — one-time server setup isn't done yet." >&2
  echo "See DEPLOYMENT.md 'One-time VPS setup'." >&2
  exit 1
fi

echo "==> Extracting $RELEASE_FILE to $RELEASE_DIR"
mkdir -p "$RELEASE_DIR"
tar -xzf "$APP_DIR/incoming/$RELEASE_FILE" -C "$RELEASE_DIR"

echo "==> Linking shared resources"
ln -sfn "$APP_DIR/shared/backend/.env" "$RELEASE_DIR/backend/.env"
rm -rf "$RELEASE_DIR/backend/storage"
ln -sfn "$APP_DIR/shared/backend/storage" "$RELEASE_DIR/backend/storage"

echo "==> Running migrations and caching config"
cd "$RELEASE_DIR/backend"
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan event:cache

echo "==> Swapping current -> $SHA"
ln -sfn "$RELEASE_DIR" "$APP_DIR/current"

echo "==> Reloading PHP-FPM and restarting workers"
sudo /usr/bin/systemctl reload php8.3-fpm
sudo /usr/bin/supervisorctl restart email-verifier:*

echo "==> Pruning old releases (keeping last $KEEP_RELEASES)"
cd "$APP_DIR/releases"
ls -1t | tail -n "+$((KEEP_RELEASES + 1))" | xargs -r rm -rf

rm -f "$APP_DIR/incoming/$RELEASE_FILE" "$APP_DIR/incoming/remote-deploy.sh"

echo "==> Deployed $SHA"
