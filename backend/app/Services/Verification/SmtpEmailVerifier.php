<?php

namespace App\Services\Verification;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Verifies a mailbox's deliverability without ever sending a message:
 * syntax check -> MX lookup -> SMTP dialogue up to (but not including)
 * DATA. Ported from legacy-v1/EmailVerifier.php — see DECISIONS.md
 * "Porting notes from v1 EmailVerifier.php" for exactly which behaviors
 * had to survive the rewrite (multi-MX fallback, EHLO/HELO fallback,
 * multiline response parsing, catch-all probe mechanics) and why.
 *
 * The RCPT TO response is used as the source of truth for deliverability,
 * per RFC 5321. This is inherently best-effort: many mail servers accept
 * all RCPT TO commands ("catch-all") or defer the real check to DATA
 * time, so results are a strong signal, not a guarantee.
 */
class SmtpEmailVerifier
{
    /** @var array{helo_domain:string, mail_from:string, connect_timeout:int, read_timeout:int, port:int, catch_all_check:bool} */
    private array $config;

    /** @var array<int, array{stage:string, mx_host:?string, smtp_code:?int, message:?string}> */
    private array $log = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function verify(string $email, string $domain): VerificationResult
    {
        $this->log = [];

        $mxHosts = $this->resolveMxHosts($domain);
        if (empty($mxHosts)) {
            return $this->result('NO_MX', null, 'No MX or fallback A record found', null);
        }

        $lastError = 'Could not connect to any mail server';

        foreach ($mxHosts as $host) {
            try {
                $connection = $this->connect($host);
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
                Log::debug("verify: connect failed for {$host}: {$lastError}");

                continue;
            }

            try {
                return $this->runDialogue($connection, $email, $domain, $host);
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
                Log::debug("verify: SMTP dialogue failed on {$host}: {$lastError}");

                continue;
            } finally {
                fclose($connection);
            }
        }

        return $this->result('TEMP_FAILURE', null, $lastError, null);
    }

    /**
     * Returns MX hosts ordered by priority — tries every one, not just the
     * lowest-preference host (see DECISIONS.md porting notes). Falls back
     * to the domain's own A/AAAA record when no MX record exists, per RFC
     * 5321 §5.1.
     *
     * @return string[]
     */
    private function resolveMxHosts(string $domain): array
    {
        $hosts = [];
        $weights = [];

        if (getmxrr($domain, $hosts, $weights)) {
            array_multisort($weights, $hosts);

            return $hosts;
        }

        if (checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA')) {
            return [$domain];
        }

        return [];
    }

    /**
     * @return resource
     */
    private function connect(string $host)
    {
        $port = $this->config['port'];
        $fp = @fsockopen($host, $port, $errno, $errstr, $this->config['connect_timeout']);

        if ($fp === false) {
            throw new RuntimeException("Connection to {$host}:{$port} failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($fp, $this->config['read_timeout']);

        return $fp;
    }

    /**
     * @param  resource  $fp
     */
    private function runDialogue($fp, string $email, string $domain, string $host): VerificationResult
    {
        [$code, $message] = $this->readResponse($fp);
        $this->record('GREETING', $host, $code, $message);
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("Unexpected greeting from {$host}: {$code} {$message}");
        }

        [$code, $message] = $this->command($fp, "EHLO {$this->config['helo_domain']}");
        $this->record('EHLO', $host, $code, $message);
        if ($code < 200 || $code >= 300) {
            // Some legacy servers only understand HELO.
            [$code, $message] = $this->command($fp, "HELO {$this->config['helo_domain']}");
            $this->record('HELO', $host, $code, $message);
            if ($code < 200 || $code >= 300) {
                throw new RuntimeException("EHLO/HELO rejected by {$host}: {$code} {$message}");
            }
        }

        [$code, $message] = $this->command($fp, "MAIL FROM:<{$this->config['mail_from']}>");
        $this->record('MAIL_FROM', $host, $code, $message);
        if ($code < 200 || $code >= 300) {
            $this->quit($fp, $host);

            return $this->result('UNKNOWN', $code, $message, $host);
        }

        [$code, $message] = $this->command($fp, "RCPT TO:<{$email}>");
        $this->record('RCPT_TO', $host, $code, $message);
        $status = $this->mapSmtpCode($code);

        if ($status === 'VALID' && $this->config['catch_all_check']) {
            if ($this->probeCatchAll($fp, $domain, $host)) {
                $status = 'CATCH_ALL';
            }
        }

        $this->quit($fp, $host);

        return $this->result($status, $code, $message, $host);
    }

    /**
     * Probes a random, essentially-guaranteed-nonexistent mailbox on the
     * same domain within the same connection to detect catch-all servers.
     * Only called after the real RCPT TO already came back 250/251 — no
     * point spending a probe on an address that's already INVALID.
     *
     * @param  resource  $fp
     */
    private function probeCatchAll($fp, string $domain, string $host): bool
    {
        $probeLocal = 'verify-probe-'.bin2hex(random_bytes(8));

        [$code, $message] = $this->command($fp, 'RSET');
        $this->record('RSET', $host, $code, $message);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        [$code, $message] = $this->command($fp, "MAIL FROM:<{$this->config['mail_from']}>");
        $this->record('MAIL_FROM', $host, $code, $message);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        [$code, $message] = $this->command($fp, "RCPT TO:<{$probeLocal}@{$domain}>");
        $this->record('CATCHALL_PROBE', $host, $code, $message);

        return $code >= 200 && $code < 300;
    }

    /**
     * @param  resource  $fp
     */
    private function quit($fp, string $host): void
    {
        [$code, $message] = $this->command($fp, 'QUIT');
        $this->record('QUIT', $host, $code, $message);
    }

    private function mapSmtpCode(int $code): string
    {
        if ($code === 250 || $code === 251) {
            return 'VALID';
        }
        if ($code === 450 || $code === 451 || $code === 452) {
            return 'TEMP_FAILURE';
        }
        if ($code === 550 || $code === 551 || $code === 553) {
            return 'INVALID';
        }

        return 'UNKNOWN';
    }

    /**
     * @param  resource  $fp
     * @return array{0:int, 1:string}
     */
    private function command($fp, string $line): array
    {
        fwrite($fp, $line."\r\n");

        return $this->readResponse($fp);
    }

    /**
     * Reads a full (possibly multi-line) SMTP response, e.g.:
     *   250-mx.example.com
     *   250-SIZE 35882577
     *   250 STARTTLS
     * The status code comes from the LAST line. Distinguishes a genuine
     * read timeout from a plain connection close — different failure
     * modes worth telling apart in the log.
     *
     * @param  resource  $fp
     * @return array{0:int, 1:string}
     */
    private function readResponse($fp): array
    {
        $code = 0;
        $lastLine = '';

        do {
            $line = fgets($fp, 512);

            $meta = stream_get_meta_data($fp);
            if ($meta['timed_out']) {
                throw new RuntimeException('SMTP read timed out');
            }
            if ($line === false) {
                throw new RuntimeException('SMTP connection closed unexpectedly');
            }

            $lastLine = rtrim($line, "\r\n");
            $code = (int) substr($lastLine, 0, 3);
            $continues = isset($lastLine[3]) && $lastLine[3] === '-';
        } while ($continues);

        return [$code, $lastLine];
    }

    private function record(string $stage, ?string $host, ?int $code, ?string $message): void
    {
        $this->log[] = [
            'stage' => $stage,
            'mx_host' => $host,
            'smtp_code' => $code,
            'message' => $message,
        ];
    }

    private function result(string $status, ?int $smtpCode, ?string $smtpResponse, ?string $mxHost): VerificationResult
    {
        return new VerificationResult($status, $smtpCode, $smtpResponse, $mxHost, $this->log);
    }
}
