<?php

declare(strict_types=1);

/**
 * Orchestrates one batch run: claim PENDING rows, verify each, persist the
 * result, and throttle between checks. Designed to be invoked repeatedly by
 * cron; a lock file prevents overlapping runs if a previous one is slow.
 */
class Worker
{
    private Database $db;
    private EmailVerifier $verifier;
    private Logger $logger;
    private array $config;

    /** @var resource|null */
    private $lockHandle;

    public function __construct(Database $db, EmailVerifier $verifier, Logger $logger, array $workerConfig)
    {
        $this->db = $db;
        $this->verifier = $verifier;
        $this->logger = $logger;
        $this->config = $workerConfig;
    }

    public function run(): void
    {
        if (!$this->acquireLock()) {
            $this->logger->warning('Another worker run is already in progress; exiting.');
            return;
        }

        try {
            $this->processBatch();
        } finally {
            $this->releaseLock();
        }
    }

    private function processBatch(): void
    {
        $batch = $this->db->claimPendingBatch($this->config['batch_size']);

        if (empty($batch)) {
            $this->logger->info('No pending email addresses to verify.');
            return;
        }

        $this->logger->info('Claimed ' . count($batch) . ' email address(es) for verification.');

        foreach ($batch as $row) {
            $this->processRow($row);
            usleep($this->config['sleep_between_checks_us']);
        }
    }

    private function processRow(array $row): void
    {
        $id = (int) $row['id'];
        $email = $row['email'];
        $attempts = (int) $row['attempts'] + 1;

        try {
            $result = $this->verifier->verify($email);
            $status = $result['status'];

            // No dedicated retry queue in v1: transient failures are
            // requeued as PENDING until max_attempts is reached, at which
            // point they're left as a terminal TEMP_FAILURE.
            if ($status === 'TEMP_FAILURE' && $attempts < $this->config['max_attempts']) {
                $status = 'PENDING';
            }

            $this->db->updateResult(
                $id,
                $status,
                $result['smtp_code'],
                $result['smtp_response'],
                $result['mx_host'],
                $attempts
            );

            $this->logger->info(sprintf(
                'id=%d email=%s status=%s smtp_code=%s attempts=%d',
                $id,
                $email,
                $status,
                $result['smtp_code'] ?? 'n/a',
                $attempts
            ));
        } catch (Throwable $e) {
            $this->logger->error("id={$id} email={$email} unexpected error: {$e->getMessage()}");

            $status = $attempts < $this->config['max_attempts'] ? 'PENDING' : 'TEMP_FAILURE';

            try {
                $this->db->updateResult($id, $status, null, substr($e->getMessage(), 0, 255), null, $attempts);
            } catch (Throwable $inner) {
                $this->logger->error("id={$id} failed to persist error state: {$inner->getMessage()}");
            }
        }
    }

    private function acquireLock(): bool
    {
        $handle = fopen($this->config['lock_file'], 'c');
        if ($handle === false) {
            throw new RuntimeException('Could not open lock file: ' . $this->config['lock_file']);
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        $this->lockHandle = $handle;
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }
}
