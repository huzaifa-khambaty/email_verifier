<?php

declare(strict_types=1);

/**
 * Thin PDO wrapper providing the exact queries the worker needs.
 *
 * Batch claiming uses SELECT ... FOR UPDATE inside a transaction so that
 * multiple worker processes can run against the same table without
 * verifying the same row twice. Requires the table to use the InnoDB
 * storage engine (see setup.sql).
 */
class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $this->pdo = new PDO(
            $config['dsn'],
            $config['user'],
            $config['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }

    /**
     * Atomically claims up to $limit PENDING rows by flipping them to
     * PROCESSING, and returns their id/email/attempts.
     *
     * @return array<int, array{id:int, email:string, attempts:int}>
     */
    public function claimPendingBatch(int $limit): array
    {
        $this->pdo->beginTransaction();

        try {
            $select = $this->pdo->prepare(
                "SELECT id, email, attempts
                 FROM email_addresses
                 WHERE verification_status = 'PENDING'
                 ORDER BY id
                 LIMIT :limit
                 FOR UPDATE"
            );
            $select->bindValue(':limit', $limit, PDO::PARAM_INT);
            $select->execute();
            $rows = $select->fetchAll();

            if (empty($rows)) {
                $this->pdo->commit();
                return [];
            }

            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $update = $this->pdo->prepare(
                "UPDATE email_addresses
                 SET verification_status = 'PROCESSING'
                 WHERE id IN ($placeholders)"
            );
            $update->execute($ids);

            $this->pdo->commit();

            return $rows;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function updateResult(
        int $id,
        string $status,
        ?int $smtpCode,
        ?string $smtpResponse,
        ?string $mxHost,
        int $attempts
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE email_addresses
             SET verification_status = :status,
                 smtp_code = :smtp_code,
                 smtp_response = :smtp_response,
                 mx_host = :mx_host,
                 attempts = :attempts,
                 verified_at = NOW()
             WHERE id = :id"
        );

        $stmt->execute([
            ':status'        => $status,
            ':smtp_code'     => $smtpCode,
            ':smtp_response' => $smtpResponse !== null ? substr($smtpResponse, 0, 255) : null,
            ':mx_host'       => $mxHost,
            ':attempts'      => $attempts,
            ':id'            => $id,
        ]);
    }
}
