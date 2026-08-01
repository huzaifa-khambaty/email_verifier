<?php

// Defaults locked in DECISIONS.md. Per-domain overrides (delay_seconds,
// max_workers, priority) live on the `domains` table itself — these are
// only the fallback used when creating a new domain row.

return [
    // Read via config('verifier.admin.*'), never env() directly, from the
    // seeder — env() becomes unreliable once `config:cache` has run (only
    // config files are guaranteed to still see real .env values at cache
    // time), and remote-deploy.sh caches config before seeding. Calling
    // env('ADMIN_EMAIL') straight from DatabaseSeeder silently fell back
    // to its hardcoded default in production. See DECISIONS.md.
    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL', 'admin@nextmatchmail.com'),
        'password' => env('ADMIN_PASSWORD', 'password'),
    ],

    'smtp' => [
        'helo_domain' => env('SMTP_HELO_DOMAIN', 'verify.nextmatchmail.com'),
        'mail_from' => env('SMTP_MAIL_FROM', 'verify@nextmatchmail.com'),
        'connect_timeout' => (int) env('SMTP_CONNECT_TIMEOUT', 10),
        'read_timeout' => (int) env('SMTP_READ_TIMEOUT', 10),
        'port' => (int) env('SMTP_PORT', 25),
        'catch_all_check' => (bool) env('SMTP_CATCH_ALL_CHECK', true),
    ],

    'scheduler' => [
        'default_delay_seconds' => (int) env('VERIFY_DEFAULT_DELAY_SECONDS', 3),
        'major_provider_delay_seconds' => (int) env('VERIFY_MAJOR_PROVIDER_DELAY_SECONDS', 8),
        'default_max_workers' => (int) env('VERIFY_DEFAULT_MAX_WORKERS', 1),
        'max_attempts' => (int) env('VERIFY_MAX_ATTEMPTS', 5),
        'circuit_breaker_threshold' => (int) env('VERIFY_CIRCUIT_BREAKER_THRESHOLD', 5),
        'circuit_breaker_cooldown_minutes' => (int) env('VERIFY_CIRCUIT_BREAKER_COOLDOWN_MINUTES', 30),
        'claim_batch_size' => (int) env('VERIFY_CLAIM_BATCH_SIZE', 10),

        // Consecutive catch-all confirmations before a domain is trusted
        // as catch-all and its remaining addresses are resolved without
        // an SMTP round trip. More than one because a server having a bad
        // day can transiently accept everything; three consecutive
        // random-probe accepts is strong evidence, and any single
        // definitive VALID/INVALID resets the count to zero.
        'catch_all_confirmations' => (int) env('VERIFY_CATCH_ALL_CONFIRMATIONS', 3),

        // Domains seeded with the stricter major_provider_delay_seconds /
        // max_workers=1 defaults instead of the general defaults — see
        // DECISIONS.md "Stricter defaults for major providers".
        'major_providers' => [
            'gmail.com',
            'googlemail.com',
            'yahoo.com',
            'ymail.com',
            'outlook.com',
            'hotmail.com',
            'live.com',
            'msn.com',
            'aol.com',
            'icloud.com',
            'me.com',
        ],

        // Exponential backoff schedule (minutes) for TEMP_FAILURE retries,
        // indexed by attempt number (1st retry, 2nd retry, ...). The last
        // value repeats if max_attempts exceeds the list length.
        'retry_backoff_minutes' => [5, 15, 45, 120],
    ],
];
