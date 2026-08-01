CREATE TABLE IF NOT EXISTS email_addresses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  verification_status ENUM(
    'PENDING','PROCESSING','VALID','INVALID','NO_MX',
    'CATCH_ALL','UNKNOWN','TEMP_FAILURE'
  ) NOT NULL DEFAULT 'PENDING',
  smtp_code SMALLINT NULL,
  smtp_response VARCHAR(255) NULL,
  mx_host VARCHAR(255) NULL,
  attempts INT NOT NULL DEFAULT 0,
  verified_at DATETIME NULL,
  UNIQUE KEY uq_email (email),
  KEY idx_status (verification_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
