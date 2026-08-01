# Deployment — v2

Supersedes `legacy-v1/DEPLOYMENT.md` for actually shipping code (that
file's VPS-hardening and outbound-SMTP-reputation sections — non-root
user, ufw, PTR record, port 25 unblocking — still apply and aren't
repeated here). This covers the v2 stack specifically: Laravel 12 +
Vue 3 + MariaDB + Nginx + Supervisor, deployed via GitHub Actions.

## How it works

Every push to `main` that passes `.github/workflows/ci.yml`'s tests
triggers `.github/workflows/deploy.yml`:

1. Builds the backend (`composer install --no-dev`) and frontend
   (`npm run build`) **in CI**, not on the VPS — the server never needs
   Composer or Node installed at all, only PHP, Nginx, MariaDB, and
   Supervisor.
2. Packages both into a tarball, `scp`s it to the VPS along with
   `deploy/remote-deploy.sh`.
3. That script extracts it to `releases/<git-sha>/`, symlinks in the
   persistent `.env` and `storage/` from `shared/`, runs
   `php artisan migrate --force` + config caching, atomically swaps the
   `current` symlink, reloads PHP-FPM, and restarts the Supervisor-managed
   workers so they pick up the new code.

No manual production edits after this is set up (v2 §11) — config
changes go through `shared/backend/.env` on the server (see below), code
changes go through git.

## One-time VPS setup

Assumes the general hardening from `legacy-v1/DEPLOYMENT.md` steps 1-3
(non-root `deploy` user, `ufw`, SSH key auth) is already done.

### 1. Install PHP, MariaDB, Nginx, Supervisor

```bash
sudo apt update
sudo apt install -y php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-bcmath \
  mariadb-server nginx supervisor
```

### 2. Database

```bash
sudo mysql_secure_installation
sudo mysql
```

```sql
CREATE DATABASE email_verifier CHARACTER SET utf8mb4;
CREATE USER 'verifier'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON email_verifier.* TO 'verifier'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Directory layout

```bash
sudo mkdir -p /var/www/email-verifier/{releases,incoming,shared/backend/storage}
sudo chown -R deploy:deploy /var/www/email-verifier
```

Recreate the Laravel storage subdirectories (they're gitignored, so the
release tarball won't contain them) and give them the right permissions:

```bash
cd /var/www/email-verifier/shared/backend/storage
mkdir -p app/private app/public framework/{cache/data,sessions,testing,views} logs
```

### 4. Production `.env`

This is the one thing that's genuinely manual — CI never sees it, by
design (see `DECISIONS.md` "Auth & users" for why secrets don't flow
through GitHub Actions here). Create
`/var/www/email-verifier/shared/backend/.env`:

```bash
nano /var/www/email-verifier/shared/backend/.env
```

Base it on `backend/.env.example`, then set at minimum:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://nextmatchmail.com
APP_KEY=                          # fill in after first deploy, see below

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=email_verifier
DB_USERNAME=verifier
DB_PASSWORD=CHANGE_ME_STRONG_PASSWORD

SANCTUM_STATEFUL_DOMAINS=nextmatchmail.com,www.nextmatchmail.com
SESSION_DOMAIN=.nextmatchmail.com

SMTP_HELO_DOMAIN=verify.nextmatchmail.com
SMTP_MAIL_FROM=verify@nextmatchmail.com

ADMIN_EMAIL=huzaifa.khambaty@gmail.com
ADMIN_PASSWORD=CHANGE_ME_STRONG_PASSWORD
```

### 5. Passwordless sudo for the deploy user (scoped, not full sudo)

`remote-deploy.sh` needs to reload PHP-FPM and restart Supervisor without
a password prompt, and nothing else:

```bash
sudo visudo -f /etc/sudoers.d/email-verifier-deploy
```

```
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm, /usr/bin/supervisorctl restart email-verifier:*
```

### 6. Nginx + Supervisor config

```bash
sudo cp deploy/nginx/email-verifier.conf /etc/nginx/sites-available/email-verifier
sudo ln -s /etc/nginx/sites-available/email-verifier /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

sudo cp deploy/supervisor/email-verifier.conf /etc/supervisor/conf.d/email-verifier.conf
sudo supervisorctl reread
sudo supervisorctl update
```

(Supervisor programs won't start successfully until the first release
exists at `current/` — that's fine, `autorestart=true` means they'll
come up on their own once step 8 finishes.)

### 7. DNS + TLS

Point `nextmatchmail.com` (A record) at the VPS IP, then:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d nextmatchmail.com -d www.nextmatchmail.com
```

### 8. GitHub repo secrets

Generate a dedicated SSH keypair for CI (don't reuse your personal one):

```bash
ssh-keygen -t ed25519 -f deploy_key -C "github-actions-deploy" -N ""
cat deploy_key.pub >> ~/.ssh/authorized_keys   # on the VPS, as the deploy user
```

In the GitHub repo → Settings → Secrets and variables → Actions, add:

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | VPS IP or hostname |
| `DEPLOY_USER` | `deploy` |
| `DEPLOY_PORT` | *(optional)* only needed if SSH isn't on port 22 |
| `DEPLOY_SSH_KEY` | contents of `deploy_key` (the **private** key) |

### 9. First deploy

Push to `main` (or re-run the workflow). `remote-deploy.sh` will refuse
to run if `shared/backend/.env` is missing (step 4), so do that first.
After the first successful deploy, generate the app key and seed the
admin account **on the server**, once:

```bash
cd /var/www/email-verifier/current/backend
php artisan key:generate   # writes APP_KEY into shared/backend/.env via the symlink
php artisan db:seed
```

## Manual deploy / rollback

Re-run `remote-deploy.sh` by hand if needed — it takes the tarball
filename (already sitting in `incoming/` from the last CI run, or
uploaded manually) and the SHA to release under:

```bash
bash /var/www/email-verifier/incoming/remote-deploy.sh release-<sha>.tar.gz <sha>
```

**Rollback** is just re-pointing the symlink to a previous release and
restarting workers — no rebuild needed:

```bash
ls /var/www/email-verifier/releases/            # find the SHA to roll back to
ln -sfn /var/www/email-verifier/releases/<old-sha> /var/www/email-verifier/current
sudo systemctl reload php8.3-fpm
sudo supervisorctl restart email-verifier:*
```

(If the rollback needs to undo a migration too, that's not automatic —
run `php artisan migrate:rollback` from the old release manually, and
think carefully about whether that's actually safe for that migration.)
