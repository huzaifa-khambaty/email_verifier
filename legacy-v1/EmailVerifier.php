<?php

declare(strict_types=1);

/**
 * Verifies a mailbox's deliverability without ever sending a message:
 * syntax check -> MX lookup -> SMTP dialogue up to (but not including) DATA.
 *
 * The RCPT TO response is used as the source of truth for deliverability,
 * per RFC 5321. This is inherently best-effort: many mail servers accept
 * all RCPT TO commands ("catch-all") or defer the real check to DATA time,
 * so results should be treated as a strong signal, not a guarantee.
 */
class EmailVerifier
{
    /** @var array{helo_domain:string, mail_from:string, connect_timeout:int, read_timeout:int, port:int, catch_all_check:bool} */
    private array $config;
    private Logger $logger;

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @return array{status:string, smtp_code:?int, smtp_response:?string, mx_host:?string}
     */
    public function verify(string $email): array
    {
        $email = trim($email);

        if (!$this->isSyntaxValid($email)) {
            return $this->result('INVALID', null, 'Malformed email address', null);
        }

        $domain = substr($email, strrpos($email, '@') + 1);

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
                $this->logger->debug("Connect failed for {$host}: {$lastError}");
                continue;
            }

            try {
                return $this->runDialogue($connection, $email, $domain, $host);
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
                $this->logger->debug("SMTP dialogue failed on {$host}: {$lastError}");
                continue;
            } finally {
                fclose($connection);
            }
        }

        return $this->result('TEMP_FAILURE', null, $lastError, null);
    }

    private function isSyntaxValid(string $email): bool
    {
        if ($email === '' || strlen($email) > 254) {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Returns MX hosts ordered by priority. Falls back to the domain's own
     * A/AAAA record when no MX record exists, per RFC 5321 5.1.
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
     * @param resource $fp
     */
    private function runDialogue($fp, string $email, string $domain, string $host): array
    {
        [$code, $message] = $this->readResponse($fp);
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("Unexpected greeting from {$host}: {$code} {$message}");
        }

        [$code, $message] = $this->command($fp, "EHLO {$this->config['helo_domain']}");
        if ($code < 200 || $code >= 300) {
            // Some legacy servers only understand HELO.
            [$code, $message] = $this->command($fp, "HELO {$this->config['helo_domain']}");
            if ($code < 200 || $code >= 300) {
                throw new RuntimeException("EHLO/HELO rejected by {$host}: {$code} {$message}");
            }
        }

        [$code, $message] = $this->command($fp, "MAIL FROM:<{$this->config['mail_from']}>");
        if ($code < 200 || $code >= 300) {
            $this->command($fp, 'QUIT');
            return $this->result('UNKNOWN', $code, $message, $host);
        }

        [$code, $message] = $this->command($fp, "RCPT TO:<{$email}>");
        $status = $this->mapSmtpCode($code);

        if ($status === 'VALID' && $this->config['catch_all_check']) {
            if ($this->probeCatchAll($fp, $domain)) {
                $status = 'CATCH_ALL';
            }
        }

        $this->command($fp, 'QUIT');

        return $this->result($status, $code, $message, $host);
    }

    /**
     * Probes a random, essentially-guaranteed-nonexistent mailbox on the
     * same domain within the same connection to detect catch-all servers.
     *
     * @param resource $fp
     */
    private function probeCatchAll($fp, string $domain): bool
    {
        $probeLocal = 'verify-probe-' . bin2hex(random_bytes(8));

        [$code] = $this->command($fp, "RSET");
        if ($code < 200 || $code >= 300) {
            return false;
        }

        [$code] = $this->command($fp, "MAIL FROM:<{$this->config['mail_from']}>");
        if ($code < 200 || $code >= 300) {
            return false;
        }

        [$code] = $this->command($fp, "RCPT TO:<{$probeLocal}@{$domain}>");

        return $code >= 200 && $code < 300;
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
     * @param resource $fp
     * @return array{0:int, 1:string}
     */
    private function command($fp, string $line): array
    {
        fwrite($fp, $line . "\r\n");

        return $this->readResponse($fp);
    }

    /**
     * Reads a full (possibly multi-line) SMTP response, e.g.:
     *   250-mx.example.com
     *   250-SIZE 35882577
     *   250 STARTTLS
     *
     * @param resource $fp
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

    private function result(string $status, ?int $smtpCode, ?string $smtpResponse, ?string $mxHost): array
    {
        return [
            'status'        => $status,
            'smtp_code'     => $smtpCode,
            'smtp_response' => $smtpResponse,
            'mx_host'       => $mxHost,
        ];
    }
}
