# backend/ — Laravel 12 API

## Local dev gotcha (Windows/UniServer only — irrelevant on the Ubuntu VPS)

`php artisan serve` crashes on this machine: it spawns its child process
using PHP's `$_ENV` superglobal rather than passing the OS environment
through, and this PHP 8.3 build's `variables_order` doesn't populate
`$_ENV`, so no php.ini gets loaded in the spawned server (no mbstring,
wrong extension_dir, etc. — `Str::studly()` crashes on a missing
`mb_split`). Workaround: bypass `artisan serve` and start PHP's built-in
server directly with an explicit `-c`:

```bash
export PHPRC="/d/UniServerXV/core/php83/php-cli.ini"   # or pass -c inline below
php -c "$PHPRC" -S 127.0.0.1:8000 -t public public/index.php
```

Every `artisan` command run standalone (migrate, tinker, verify:work,
etc.) also needs `PHPRC` set first, or it hits the same missing-extension
error. A standard apt-installed PHP 8.3 on Ubuntu loads php.ini normally
and won't need any of this.


Not yet bootstrapped. Per the [v2 plan](../NextMatchMail_Email_Verification_AI_Development_Plan_v2.md) §2:

- Laravel 12, PHP 8.3
- MariaDB
- Laravel Sanctum (auth)
- Queue workers + Supervisor
- REST API under `/api`, JSON only, thin controllers, business logic in a service layer

## Bootstrap steps (not yet run)

```bash
composer create-project laravel/laravel . "^12.0"
composer require laravel/sanctum
php artisan sanctum:install
```

Then create the migrations for the tables in v2 §7:
`users`, `settings`, `domains`, `import_batches`, `emails`,
`verification_jobs`, `smtp_logs`, `exports`, `audit_logs`, `worker_status`
— with indexes on `email`, `domain_id`, `status`, `batch_id`, `processed_at`.

`domains` additionally needs `priority`, `delay_seconds`, `max_workers`,
`active_workers`, `last_dispatched_at`, `consecutive_failures`,
`cooling_down_until` — see the scheduler design in `../DECISIONS.md`.

## Verification worker (no Redis)

No queue broker — `php artisan verify:work` is a long-lived command
implementing the claim → verify → update loop from `../DECISIONS.md`
("Queue driver — no Redis, no broker"), run as N processes under
Supervisor. Uses `SELECT ... FOR UPDATE SKIP LOCKED` against
`verification_jobs`/`domains` for coordination — no `jobs` table, no
Redis, no Horizon.

See [`../TODO.md`](../TODO.md) for items the v2 spec leaves unstated that
need a decision before these can be finalized.
