<?php

namespace App\Support;

/**
 * The same syntax rule v1 used (see legacy-v1/EmailVerifier.php). In v2
 * this check happens once, at CSV import time — by the time an email
 * reaches SmtpEmailVerifier it's already known syntactically valid, so
 * the verifier doesn't repeat this check.
 */
class EmailSyntax
{
    public static function isValid(string $email): bool
    {
        if ($email === '' || strlen($email) > 254) {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function domain(string $email): string
    {
        return strtolower(substr($email, strrpos($email, '@') + 1));
    }
}
