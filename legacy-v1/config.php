<?php

declare(strict_types=1);

/**
 * Central configuration for the email verification worker.
 * Values can be overridden via environment variables, which is the
 * recommended approach for production/cron deployments.
 */
return [
    'db' => [
        'dsn'  => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=email_verifier;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ],

    'worker' => [
        // Rows fetched per cron run.
        'batch_size' => (int) (getenv('BATCH_SIZE') ?: 100),
        // Pause between individual SMTP checks, to avoid hammering remote
        // mail servers and tripping rate limits / greylisting.
        'sleep_between_checks_us' => (int) (getenv('SLEEP_US') ?: 300000), // 0.3s
        // After this many attempts a row that keeps failing transiently is
        // left in TEMP_FAILURE rather than retried forever.
        'max_attempts' => (int) (getenv('MAX_ATTEMPTS') ?: 5),
        // Prevents overlapping cron runs.
        'lock_file' => __DIR__ . '/worker.lock',
    ],

    'smtp' => [
        'helo_domain'     => getenv('SMTP_HELO_DOMAIN') ?: 'verifier.local',
        'mail_from'       => getenv('SMTP_MAIL_FROM') ?: 'verify@verifier.local',
        'connect_timeout' => (int) (getenv('SMTP_CONNECT_TIMEOUT') ?: 10),
        'read_timeout'    => (int) (getenv('SMTP_READ_TIMEOUT') ?: 10),
        'port'            => (int) (getenv('SMTP_PORT') ?: 25),
        // After a mailbox verifies as deliverable, probe a random,
        // near-certainly-nonexistent mailbox on the same domain to detect
        // catch-all domains.
        'catch_all_check' => true,
    ],

    'log' => [
        'path'  => __DIR__ . '/logs/worker.log',
        // DEBUG, INFO, WARNING, ERROR
        'level' => getenv('LOG_LEVEL') ?: 'INFO',
    ],
];
