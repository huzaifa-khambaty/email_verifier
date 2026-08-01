# VPS Deployment Guide — nextmatchmail.com

Target: Ubuntu 24.04 VPS, fresh install, SSH access as root (or a sudo user).

This app makes **outbound** SMTP connections only (to verify mailboxes) —
it does not receive mail. So there's no inbound mail server to configure,
but two things specific to being an SMTP *client* matter a lot for your
results: your outbound port 25 must not be blocked, and your server's
reverse DNS should look legitimate, or many receiving servers will
greylist/reject you and every check will come back `UNKNOWN`/`TEMP_FAILURE`.

---

## 0. Before you SSH in

**Check with your VPS provider whether outbound port 25 is blocked.** Most
cloud providers (DigitalOcean, AWS, Vultr, Linode, GCP, Azure, Hetzner
sometimes) block outbound port 25 by default to fight spam, and require a
support ticket to unblock it for a specific IP. Do this now since it can
take hours-to-days to get approved. Nothing in this guide will work over
port 25 until that's unblocked.

---

## 1. Point DNS at the VPS

In your domain registrar / DNS provider for `nextmatchmail.com`, add an A
record for the host you'll run the verifier on, e.g.:

```
verify.nextmatchmail.com.   A   <VPS_PUBLIC_IP>
```

Then **ask your VPS provider to set the reverse DNS (PTR) record** for
`<VPS_PUBLIC_IP>` to `verify.nextmatchmail.com`. This is usually done in
their control panel (not your DNS provider — reverse DNS is controlled by
whoever owns the IP block). Forward (A) and reverse (PTR) should match.

Why this matters: your `EmailVerifier` sends `EHLO verifier.local` and
connects from your VPS's IP. Many receiving mail servers do a PTR lookup
on the connecting IP and reject/defer connections where it's missing or
doesn't resolve — that shows up as false `TEMP_FAILURE`/`UNKNOWN` results,
not a bug in the code.

---

## 2. First login and basic hardening

```bash
ssh root@<VPS_PUBLIC_IP>

# Update everything
apt update && apt full-upgrade -y

# Set the hostname to match the PTR record from step 1
hostnamectl set-hostname verify.nextmatchmail.com

# Create a non-root deploy user
adduser deploy
usermod -aG sudo deploy

# Copy your SSH key so you can log in as `deploy` without a password
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy
```

Now open a **second terminal**, confirm you can log in as `deploy` with
your key, and only then lock down root login:

```bash
ssh deploy@<VPS_PUBLIC_IP>   # confirm this works first!
```

Back on the root session, edit SSH config:

```bash
sudo nano /etc/ssh/sshd_config
```

Set:

```
PermitRootLogin no
PasswordAuthentication no
```

```bash
sudo systemctl restart ssh
```

From here on, do everything as `deploy` via `sudo`.

---

## 3. Firewall

Only SSH needs to be open inbound — the app makes outbound connections,
it doesn't accept any.

```bash
sudo apt install -y ufw
sudo ufw allow OpenSSH
sudo ufw enable
sudo ufw status
```

Optional but recommended:

```bash
sudo apt install -y fail2ban
sudo systemctl enable --now fail2ban
```

---

## 4. Install PHP 8.3

Ubuntu 24.04's default repos already ship PHP 8.3, so no PPA is needed:

```bash
sudo apt install -y php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml

php -v
php -m | grep -Ei "pdo|mysqli|openssl"
```

You should see `PDO`, `pdo_mysql`, and `openssl` listed (the code doesn't
need a web server — it's a CLI-only worker).

---

## 5. Install MySQL

```bash
sudo apt install -y mysql-server
sudo mysql_secure_installation
```

Answer yes to removing anonymous users, disallowing remote root login,
removing the test database, and reloading privileges.

Create the database and a dedicated (non-root) app user:

```bash
sudo mysql
```

```sql
CREATE DATABASE email_verifier CHARACTER SET utf8mb4;
CREATE USER 'verifier'@'localhost' IDENTIFIED BY 'CHANGE_ME_TO_A_STRONG_PASSWORD';
GRANT SELECT, INSERT, UPDATE ON email_verifier.* TO 'verifier'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## 6. Deploy the code

You don't have a git remote yet, so the simplest path is `rsync` straight
from your Windows machine. Run this **from your local machine**, not the
VPS (PowerShell with OpenSSH, or Git Bash):

```bash
rsync -avz --exclude 'worker.lock' --exclude 'logs' \
  "/d/UniServerXV/www/email_verifier/" deploy@<VPS_PUBLIC_IP>:/home/deploy/email-verifier/
```

(If `rsync` isn't available on Windows, `scp -r` works too, just slower
for repeat syncs. Alternatively, push this folder to a private GitHub repo
and `git clone` it on the VPS — better long-term if you'll keep iterating.)

On the VPS:

```bash
sudo mkdir -p /opt/email-verifier
sudo rsync -a /home/deploy/email-verifier/ /opt/email-verifier/
sudo chown -R deploy:deploy /opt/email-verifier
mkdir -p /opt/email-verifier/logs
```

---

## 7. Configure

Load the DB setup:

```bash
mysql -u verifier -p email_verifier < /opt/email-verifier/setup.sql
```

Set environment variables the app reads (`config.php` uses `getenv()`).
Store them in a file only the app reads, not in shell history:

```bash
sudo nano /opt/email-verifier/.env
```

```
DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=email_verifier;charset=utf8mb4
DB_USER=verifier
DB_PASS=CHANGE_ME_TO_A_STRONG_PASSWORD
SMTP_HELO_DOMAIN=verify.nextmatchmail.com
SMTP_MAIL_FROM=verify@nextmatchmail.com
LOG_LEVEL=INFO
```

```bash
chmod 600 /opt/email-verifier/.env
```

Update `SMTP_HELO_DOMAIN` / `SMTP_MAIL_FROM` to your real domain — using
`verifier.local` (the code's default) as your `EHLO` name will get you
rejected by strict receiving servers, since it doesn't resolve.

We need a tiny wrapper so cron loads `.env` before running `cron.php`
(cron doesn't source `.env` files itself):

```bash
nano /opt/email-verifier/run.sh
```

```bash
#!/bin/bash
set -a
source /opt/email-verifier/.env
set +a
/usr/bin/php /opt/email-verifier/cron.php
```

```bash
chmod +x /opt/email-verifier/run.sh
```

---

## 8. Test manually before trusting cron with it

```bash
# insert one test address you control
mysql -u verifier -p email_verifier -e \
  "INSERT INTO email_addresses (email) VALUES ('your.own.address@gmail.com');"

/opt/email-verifier/run.sh
tail -n 50 /opt/email-verifier/logs/worker.log
```

Check the row updated as expected:

```bash
mysql -u verifier -p email_verifier -e \
  "SELECT email, verification_status, smtp_code, mx_host FROM email_addresses;"
```

If it hangs or times out on every address, port 25 outbound is almost
certainly still blocked — go back to step 0.

---

## 9. Schedule it

```bash
crontab -e   # as the deploy user
```

```cron
*/5 * * * * /opt/email-verifier/run.sh >> /opt/email-verifier/logs/cron.out 2>&1
```

---

## 10. Log rotation

Without rotation, `logs/worker.log` grows forever.

```bash
sudo nano /etc/logrotate.d/email-verifier
```

```
/opt/email-verifier/logs/*.log /opt/email-verifier/logs/*.out {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
```

---

## Checklist summary

- [ ] Confirmed with VPS provider that outbound port 25 is unblocked
- [ ] A record `verify.nextmatchmail.com` → VPS IP
- [ ] PTR record for VPS IP → `verify.nextmatchmail.com`
- [ ] Non-root `deploy` user, root SSH login disabled, `ufw` enabled
- [ ] PHP 8.3 + `pdo_mysql` installed
- [ ] MySQL installed, dedicated `verifier` DB user (not root) created
- [ ] Code deployed to `/opt/email-verifier`, `setup.sql` applied
- [ ] `.env` configured with real `SMTP_HELO_DOMAIN` / `SMTP_MAIL_FROM`, `chmod 600`
- [ ] Manual test run confirmed a real status update
- [ ] Cron entry added via `run.sh`
- [ ] `logrotate` configured
