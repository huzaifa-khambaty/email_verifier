<?php

namespace App\Services\Verification;

/**
 * Outcome of one SmtpEmailVerifier::verify() call, plus the full SMTP
 * transcript (one entry per stage) for persisting to smtp_logs — see v2
 * §6 "store SMTP response code, message and timestamp".
 */
class VerificationResult
{
    /**
     * @param  array<int, array{stage:string, mx_host:?string, smtp_code:?int, message:?string}>  $log
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $smtpCode,
        public readonly ?string $smtpResponse,
        public readonly ?string $mxHost,
        public readonly array $log = [],
    ) {
    }
}
