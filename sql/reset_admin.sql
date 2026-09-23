-- =====================================================================
-- INDBIN : account diagnostic and reset
--
-- Safe to run any number of times. It does not drop anything.
-- Run it whenever a login is refused and you are sure the password is right.
--
-- Why a login can fail even though the schema installed cleanly: the seed
-- INSERT sits near the END of indbin_full.sql. phpMyAdmin stops at the
-- first statement that errors, so a single failure anywhere earlier leaves
-- you with all the tables and none of the accounts. Everything looks
-- installed, and every login is refused.
-- =====================================================================

-- ---------------------------------------------------------------------
-- STEP 1 : what is actually in there?
--
-- Run this on its own first. If it returns 0 rows, the seed never ran and
-- step 2 is your fix. If it returns the admin row, the problem is the
-- password, and step 2 resets it.
-- ---------------------------------------------------------------------
SELECT id, role, full_name, email, mobile, account_status, kyc_status,
       LEFT(password_hash, 7) AS hash_prefix,
       LENGTH(password_hash)  AS hash_length
  FROM users
 ORDER BY id;

-- A correct bcrypt hash reads $2y$10$ and is exactly 60 characters long.
-- Anything shorter means the column truncated it, which happens when
-- password_hash is defined narrower than VARCHAR(60).

-- ---------------------------------------------------------------------
-- STEP 2 : create or reset the four starter accounts
--
-- ON DUPLICATE KEY UPDATE means this repairs an existing row rather than
-- failing on the unique email index, so it works whether the accounts are
-- missing or just have the wrong password.
--
--   admin@indbin.local     Admin@123
--   customer@indbin.local  Test@1234
--   agent@indbin.local     Test@1234
--   merchant@indbin.local  Test@1234
--
-- These hashes are in a file anyone can read. Change all four passwords
-- from inside the app once you are in.
-- ---------------------------------------------------------------------
INSERT INTO users
    (role, full_name, email, mobile, password_hash, party_code,
     business_name, referral_code, kyc_status, onboarding_step,
     account_status, mobile_verified)
VALUES
    ('admin', 'M Satyanarayana', 'admin@indbin.local', '9000000001',
     '$2y$10$2HCLLAE4pfI43nvma4RWGeiri1hzorrYoMzIyVBCxmmzrpSMtjeuy',
     'INDBINX0000001', NULL, NULL, 'approved', 7, 'active', 1),

    ('customer', 'Test Customer', 'customer@indbin.local', '9000000002',
     '$2y$10$mH/if.3L61Ooq26bPyEnRup0nwPoQ.Rci4FF6aQ5yA.JkvH.5uAZS',
     'INDBINC0000002', NULL, NULL, 'not_started', 1, 'registered', 0),

    ('agent', 'Test Agent', 'agent@indbin.local', '9000000003',
     '$2y$10$mH/if.3L61Ooq26bPyEnRup0nwPoQ.Rci4FF6aQ5yA.JkvH.5uAZS',
     'INDBINA0000003', NULL, 'AGT0003', 'not_started', 1, 'registered', 0),

    ('merchant', 'Test Merchant', 'merchant@indbin.local', '9000000004',
     '$2y$10$mH/if.3L61Ooq26bPyEnRup0nwPoQ.Rci4FF6aQ5yA.JkvH.5uAZS',
     'INDBINM0000004', 'Shree Kirana Store', NULL, 'not_started', 1, 'registered', 0)

ON DUPLICATE KEY UPDATE
    password_hash  = VALUES(password_hash),
    role           = VALUES(role),
    account_status = VALUES(account_status),
    full_name      = VALUES(full_name);

-- ---------------------------------------------------------------------
-- STEP 3 : the admin needs a row in `admins` as well
--
-- require_admin() merges the permission flags from this table. Without the
-- row the account signs in but cannot approve KYC, override credit or
-- release payouts, because admin_can() reads 0 for everything.
-- ---------------------------------------------------------------------
INSERT INTO admins (user_id, designation, can_approve_kyc, can_override_credit, can_manage_payouts)
SELECT id, 'Chief Executive', 1, 1, 1 FROM users WHERE email = 'admin@indbin.local'
ON DUPLICATE KEY UPDATE
    can_approve_kyc     = 1,
    can_override_credit = 1,
    can_manage_payouts  = 1;

-- Agents need a wallet row before any commission can be credited to them.
INSERT IGNORE INTO agent_wallet (user_id) SELECT id FROM users WHERE role = 'agent';

-- ---------------------------------------------------------------------
-- STEP 4 : confirm
--
-- Expect four rows, the admin one showing admin_row = 1.
-- ---------------------------------------------------------------------
SELECT u.id, u.role, u.email, u.account_status,
       LENGTH(u.password_hash) AS hash_length,
       (a.user_id IS NOT NULL) AS admin_row
  FROM users u
  LEFT JOIN admins a ON a.user_id = u.id
 WHERE u.email IN ('admin@indbin.local', 'customer@indbin.local',
                   'agent@indbin.local', 'merchant@indbin.local')
 ORDER BY FIELD(u.role, 'admin', 'agent', 'merchant', 'customer');

-- ---------------------------------------------------------------------
-- If a login is STILL refused after this, the accounts are fine and
-- something else is wrong. Check, in this order:
--
--   1. The role dropdown on the login form must match the accounts role.
--      auth_login() filters on it: an admin selecting Customer is told the
--      details do not match, which is deliberate but reads as a wrong
--      password.
--
--   2. Five failed attempts in a row triggers a five minute pause. The
--      message is different, but if you have been retrying, wait it out.
--
--   3. Check storage/logs/php-error.log for anything from auth_login.
-- ---------------------------------------------------------------------
