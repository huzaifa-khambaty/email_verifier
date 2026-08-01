# Deployment — v2

Supersedes `legacy-v1/DEPLOYMENT.md` for actually shipping code (that
file's VPS-hardening and outbound-SMTP-reputation sections — non-root
user, ufw, PTR record, port 25 unblocking — still apply and aren't
repeated here). This covers the v2 stack specifically: Laravel 12 +
Vue 3 + MariaDB + Nginx + Supervisor, deployed via GitHub Actions.

## How it works

`main` is regular development — PRs into it run `.github/workflows/ci.yml`
for fast feedback, nothing deploys. When you're ready to ship, merge (or
push) `main` into the `production` branch; that push triggers
`.github/workflows/deploy.yml`, which re-runs the same tests as a gate
and then:

1. Builds the backend (`composer install --no-dev`) and frontend
   (`npm run build`) **in CI**, not on the VPS — the server never needs
   Composer or Node installed at all, only PHP, Nginx, MariaDB, and
   Supervisor.
2. Packages both into a tarball, `scp`s it to the VPS along with
   `deploy/remote-deploy.sh`.
3. That script extracts it and `rsync`s it into place at
   `/var/www/email-verifier/backend/` and `/var/www/email-verifier/frontend/`
   — **overwriting in place**, no release history or symlink swap (kept
   deliberately simple; there's no need for zero-downtime versioning at
   this project's scale). `.env` and `storage/` are excluded from the
   sync so they persist across deploys. Then it runs
   `php artisan migrate --force`, seeds/updates the admin account, caches
   config, and restarts PHP-FPM + the Supervisor-managed workers so they
   pick up the new code.

No manual production edits after this is set up (v2 §11) — config
changes go through `/var/www/email-verifier/backend/.env` on the server
(see below), code changes go through git.

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
sudo mkdir -p /var/www/email-verifier/{backend,frontend/dist,incoming}
sudo chown -R deploy:deploy /var/www/email-verifier
```

Recreate the Laravel storage subdirectories (they're gitignored, so the
release tarball won't contain them, and `remote-deploy.sh` excludes
`storage/` from every sync so this only needs doing once):

```bash
cd /var/www/email-verifier/backend
mkdir -p storage/app/private storage/app/public \
  storage/framework/cache/data storage/framework/sessions \
  storage/framework/testing storage/framework/views storage/logs
```

**Ownership matters here and is easy to get wrong**: PHP-FPM runs as
`www-data`, but everything above was just created as `deploy` — Laravel
will fail to write logs (a 500 with "Permission denied" opening
`storage/logs/laravel.log", found live in production) until `storage/`
is actually writable by `www-data`. Fix it with:

```bash
sudo chown -R www-data:deploy /var/www/email-verifier/backend/storage
sudo find /var/www/email-verifier/backend/storage -type d -exec chmod 2775 {} \;
sudo find /var/www/email-verifier/backend/storage -type f -exec chmod 664 {} \;
```

(`2775` sets the setgid bit so new files/directories PHP-FPM creates
keep inheriting the `deploy` group, so `deploy` can still read/manage
logs without needing `sudo`. Note this only controls the *group*
permission bit on freshly created files — PHP-FPM's own umask still
governs whether that group-write bit is actually set on a brand new
file, so don't be surprised if a fresh `laravel.log` needs the `chmod
2775`/`664` pass repeated occasionally; nothing to worry about, just
re-run the two `find` commands above.)

### 4. Production `.env`

This is the one thing that's genuinely manual — CI never sees it, by
design (see `DECISIONS.md` "Auth & users" for why secrets don't flow
through GitHub Actions here). Create
`/var/www/email-verifier/backend/.env`:

```bash
nano /var/www/email-verifier/backend/.env
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

`ADMIN_EMAIL`/`ADMIN_PASSWORD` are read via `config('verifier.admin.*')`
(see `config/verifier.php`), not `env()` directly — `remote-deploy.sh`
runs `config:cache` as part of every deploy, and calling `env()` outside
a config file becomes unreliable once config is cached. `db:seed` runs
automatically on every deploy and is idempotent (updates the one
existing admin in place), so rotating the password later is just:
change `ADMIN_PASSWORD` here and push to `production` again — no manual
SSH step needed.

### 5. Passwordless sudo for the deploy user (scoped, not full sudo)

`remote-deploy.sh` needs to reload PHP-FPM and restart Supervisor without
a password prompt, and nothing else:

```bash
sudo visudo -f /etc/sudoers.d/email-verifier-deploy
```

```
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm, /usr/bin/supervisorctl restart email-verifier\:*
```

Note the escaped `\:` before the `*` — sudoers treats a bare `:` in a command
argument as a syntax token, not a literal character (confirmed against sudo
1.9.15p5; a bare `email-verifier:*` fails with a parser error pointing at the
colon, not the asterisk, despite how that reads). The `*` itself is left
unescaped — it stays a real wildcard, needed since `remote-deploy.sh` invokes
this normally (`sudo supervisorctl restart email-verifier:*`, no backslash) and
that literal argument still has to match the pattern stored here. Validate
with `sudo visudo -cf /etc/sudoers.d/email-verifier-deploy` after editing.

### 6. Nginx + Supervisor config

```bash
sudo cp deploy/nginx/email-verifier.conf /etc/nginx/sites-available/email-verifier
sudo ln -s /etc/nginx/sites-available/email-verifier /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

sudo cp deploy/supervisor/email-verifier.conf /etc/supervisor/conf.d/email-verifier.conf
sudo supervisorctl reread
sudo supervisorctl update
```

(Supervisor programs won't start successfully until the first deploy has
put real code at `/var/www/email-verifier/backend/` — that's fine,
`autorestart=true` means they'll come up on their own once step 9
finishes.)

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

Push to `production` (or re-run the workflow). `remote-deploy.sh` will
refuse to run if `backend/.env` is missing (step 4), so do that first.
After the first successful deploy, generate the app key **on the
server** (the only step `db:seed` running automatically doesn't cover,
since a key has to exist before anything using encryption/sessions can
work at all):

```bash
cd /var/www/email-verifier/backend
php artisan key:generate   # writes APP_KEY into backend/.env
```

Then redeploy (push to `production` again, or re-run
`remote-deploy.sh` by hand) so the app actually picks up the new key.

## Manual deploy / rollback

Re-run `remote-deploy.sh` by hand if needed — it takes the tarball
filename (already sitting in `incoming/` from the last CI run, or
uploaded manually) and a label for the log line:

```bash
bash /var/www/email-verifier/incoming/remote-deploy.sh release-<sha>.tar.gz <sha>
```

**Rollback**: there's no release history to fall back to (deliberately —
see "How it works" above). To roll back, push an older commit to
`production` (`git revert` the bad commit, or force-push production back
to a known-good SHA) and let the pipeline redeploy it normally. If the
bad deploy included a migration, check whether
`php artisan migrate:rollback` is actually safe for it before running
that by hand on the server.
