-- LifeLink – Email Verification Migration
-- Run this ONE TIME in phpMyAdmin or MySQL CLI after starting MySQL
-- Go to: http://localhost/phpmyadmin → Select "lifelink" DB → SQL tab → paste & run

USE lifelink;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_token VARCHAR(64) NULL DEFAULT NULL COMMENT 'Email verification token',
  ADD COLUMN IF NOT EXISTS email_token_expires DATETIME NULL DEFAULT NULL COMMENT 'Token expiry (24h after registration)';

-- Add index for fast token lookups
ALTER TABLE users
  ADD INDEX IF NOT EXISTS idx_email_token (email_token);

SELECT 'Migration complete! email_token columns added.' AS result;
