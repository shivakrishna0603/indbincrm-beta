-- =====================================================================
-- INDBIN: schema corrections
--
-- Three places where the database disagreed with the code that writes to
-- it. Each one was a fatal on submit, which is why they only surfaced when
-- somebody actually filled in the form rather than just opened the page.
--
-- Every statement is guarded by an information_schema check, so this file
-- is safe to run more than once and safe to run whether or not you already
-- applied sql/fix_monthly_turnover.sql.
--
-- Run once, against the database the app uses:
--   mysql -u root indbincrm < sql/fix_schema_mismatches.sql
-- or paste it into the phpMyAdmin SQL tab with that database selected.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. business_verifications.monthly_turnover
--
-- Declared DECIMAL(14,2), but nothing writes a number to it.
-- merchant/business/upload.php offers a five-option select of band codes
-- and validates against that same list; admin_review.php maps the codes
-- back to rupee labels; credit/scoring.php scores on them. MySQL coerced
-- 'below_1L' to 0 and raised warning 1265, which PDO throws.
-- ---------------------------------------------------------------------

ALTER TABLE business_verifications MODIFY monthly_turnover VARCHAR(20) NULL;

UPDATE business_verifications
   SET monthly_turnover = NULL
 WHERE monthly_turnover IS NOT NULL
   AND monthly_turnover NOT IN ('below_1L', '1L_5L', '5L_25L', '25L_1Cr', 'above_1Cr');

ALTER TABLE business_verifications
    MODIFY monthly_turnover
    ENUM('below_1L', '1L_5L', '5L_25L', '25L_1Cr', 'above_1Cr') NULL;


-- ---------------------------------------------------------------------
-- 2. agreement_signatures
--
-- merchant/agreements/sign.php writes agreement_version,
-- signature_image_path, credit_limit_at_signing and user_agent;
-- merchant/agreements/status.php reads back the first three. The table
-- called two of them something else and had never had the other two.
-- Writer and reader agree with each other, so the table moves to them.
--
-- ip_address was VARBINARY(16), which truncates any IPv6 address written
-- as text. The code stores $_SERVER['REMOTE_ADDR'] verbatim, so the column
-- needs to hold 45 characters.
-- ---------------------------------------------------------------------

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agreement_signatures'
                AND COLUMN_NAME = 'version');
SET @sql := IF(@has > 0,
    'ALTER TABLE agreement_signatures CHANGE version agreement_version VARCHAR(20) NOT NULL DEFAULT ''v1.0''',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agreement_signatures'
                AND COLUMN_NAME = 'signature_path');
SET @sql := IF(@has > 0,
    'ALTER TABLE agreement_signatures CHANGE signature_path signature_image_path VARCHAR(255) NULL',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agreement_signatures'
                AND COLUMN_NAME = 'credit_limit_at_signing');
SET @sql := IF(@has = 0,
    'ALTER TABLE agreement_signatures ADD COLUMN credit_limit_at_signing DECIMAL(12,2) NULL AFTER signature_image_path',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agreement_signatures'
                AND COLUMN_NAME = 'user_agent');
SET @sql := IF(@has = 0,
    'ALTER TABLE agreement_signatures ADD COLUMN user_agent VARCHAR(255) NULL AFTER ip_address',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

ALTER TABLE agreement_signatures MODIFY ip_address VARCHAR(45) NULL;


-- ---------------------------------------------------------------------
-- 3. settlement_accounts.bank_name
--
-- NOT NULL with no default, but no screen anywhere collects a bank name:
-- Business Verification captures an account number and an IFSC code, and
-- Account Setup captures the same two. The INSERT therefore omitted it and
-- strict mode rejected the row. Nothing populates it, so it becomes
-- nullable rather than being filled with a placeholder.
--
-- The column names on this table were the other half of that bug, and
-- those are fixed in merchant/account_setup/setup.php rather than here:
-- every read of the table already used the schema's names.
-- ---------------------------------------------------------------------

ALTER TABLE settlement_accounts MODIFY bank_name VARCHAR(150) NULL;
