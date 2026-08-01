# PHP Email Verification Worker - Developer Plan

## Goal

Build a CLI PHP application that reads email addresses from a database,
verifies them without sending email, and updates verification status.

## Architecture

``` text
cron
  |
Worker.php
  |
Database.php ---- config.php
  |
EmailVerifier.php
  |
SMTP Server
```

## Folder Structure

``` text
email-verifier/
  config.php
  Database.php
  EmailVerifier.php
  Worker.php
  Logger.php
  cron.php
  setup.sql
  README.md
```

## Database

``` sql
CREATE TABLE email_addresses (
 id INT AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(255) NOT NULL,
 verification_status ENUM(
   'PENDING','PROCESSING','VALID','INVALID','NO_MX',
   'CATCH_ALL','UNKNOWN','TEMP_FAILURE'
 ) DEFAULT 'PENDING',
 smtp_code SMALLINT NULL,
 smtp_response VARCHAR(255) NULL,
 mx_host VARCHAR(255) NULL,
 attempts INT DEFAULT 0,
 verified_at DATETIME NULL
);
```

## Worker Flow

1.  Fetch next batch (`LIMIT 100`) with `PENDING`.
2.  Mark rows `PROCESSING`.
3.  Validate syntax.
4.  Check MX.
5.  Open SMTP connection.
6.  Send `EHLO`, `MAIL FROM`, `RCPT TO`.
7.  Never send `DATA`.
8.  Update status.
9.  Sleep briefly between requests.

## Example: Fetch Batch

``` php
$stmt = $pdo->prepare(
"SELECT id,email
 FROM email_addresses
 WHERE verification_status='PENDING'
 LIMIT 100"
);
$stmt->execute();
$rows = $stmt->fetchAll();
```

## Example: SMTP Verification

``` php
$fp = fsockopen($mxHost,25,$errno,$errstr,10);

fgets($fp);

fwrite($fp,"EHLO verifier.local\r\n");
fwrite($fp,"MAIL FROM:<verify@localhost>\r\n");
fwrite($fp,"RCPT TO:<$email>\r\n");

$response = fgets($fp);

fwrite($fp,"QUIT\r\n");
fclose($fp);
```

## Status Mapping

         SMTP Status
  ----------- --------------
          250 VALID
          251 VALID
          550 INVALID
          451 TEMP_FAILURE
        No MX NO_MX
    Catch-all CATCH_ALL
        Other UNKNOWN

## Cron

``` cron
*/5 * * * * php /path/email-verifier/cron.php
```

## Future Enhancements

-   STARTTLS support
-   IPv6
-   Catch-all detection
-   Disposable email detection
-   Domain throttling
-   Retry queue
-   SMTP transcript logging
-   PHPUnit tests
