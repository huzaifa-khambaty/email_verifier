<?php

declare(strict_types=1);

/**
 * CLI entry point. Intended to be invoked by cron:
 *   php /path/to/email-verifier/cron.php
 */

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$config = require __DIR__ . '/config.php';

$logger = new Logger($config['log']['path'], $config['log']['level']);

try {
    $db = new Database($config['db']);
    $verifier = new EmailVerifier($config['smtp'], $logger);
    $worker = new Worker($db, $verifier, $logger, $config['worker']);

    $worker->run();
} catch (Throwable $e) {
    $logger->error('Fatal error: ' . $e->getMessage());
    fwrite(STDERR, 'Fatal error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
