SET NAMES utf8mb4;
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION';
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- 1. IDENTITY
-- =====================================================================

-- One row per human, whatever their role. Merchant, agent and customer
-- modules all query this table already, so it is the natural join point.
DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role              ENUM('customer','agent','merchant','admin') NOT NULL DEFAULT 'customer',
    full_name         VARCHAR(150)  NOT NULL,
    email             VARCHAR(190)  NOT NULL,
    mobile            VARCHAR(15)   NULL,
    password_hash     VARCHAR(255)  NOT NULL,

    -- public-facing code: INDBINC0000001 / INDBINA0000001 / INDBINM0000001
    party_code        VARCHAR(32)   NULL,
    customer_code     VARCHAR(32)   NULL,   -- kept: the customer module writes it

    -- merchant
    business_name     VARCHAR(180)  NULL,
    business_type     VARCHAR(100)  NULL,
    merchant_id       INT UNSIGNED  NULL,   -- staff user belonging to a merchant

    -- agent
    referral_code     VARCHAR(24)   NULL,
    upline_id         INT UNSIGNED  NULL,

    -- shared lifecycle flags, read by all three modules
    mobile_verified   TINYINT(1)    NOT NULL DEFAULT 0,
    email_verified    TINYINT(1)    NOT NULL DEFAULT 0,
    kyc_status        ENUM('not_started','pending','approved','rejected','resubmit')
                      NOT NULL DEFAULT 'not_started',
    business_verification_status ENUM('not_started','pending','approved','rejected')
                      NOT NULL DEFAULT 'not_started',
    background_status ENUM('not_started','pending','approved','rejected')
                      NOT NULL DEFAULT 'not_started',
    onboarding_step   TINYINT UNSIGNED NOT NULL DEFAULT 1,
    account_status    ENUM('registered','onboarding','active','suspended','closed')
                      NOT NULL DEFAULT 'registered',

    risk_score        SMALLINT UNSIGNED NULL,
    risk_category     VARCHAR(30)   NULL,
    credit_limit      DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    preferred_support_channel VARCHAR(30) NULL,
    onboarding_completed_at   DATETIME NULL,
    agreement_signed_at       DATETIME NULL,
    product_enablement_completed_at DATETIME NULL,
    account_setup_completed_at DATETIME NULL,
    training_completed_at     DATETIME NULL,
    app_walkthrough_completed_at DATETIME NULL,

    last_login_at     DATETIME      NULL,
    created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_users_email    (email),
    UNIQUE KEY uq_users_mobile   (mobile),
    UNIQUE KEY uq_users_party    (party_code),
    UNIQUE KEY uq_users_code     (customer_code),
    UNIQUE KEY uq_users_referral (referral_code),
    KEY idx_users_role_status    (role, account_status),
    KEY idx_users_upline         (upline_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per role letter. issue_party_code() and issue_customer_code()
-- (core/workflow.php, customer/includes/guard.php) both read the row for
-- their letter under FOR UPDATE and increment it, so each role's public
-- code runs 1, 2, 3... on its own rather than sharing users.id's single
-- auto-increment sequence across all four roles at once - which is why a
-- merchant could previously show as INDBINM0000005 with only one merchant
-- ever onboarded before it: id 5 simply happened to be a merchant.
--
-- Seeded one past each role's own demo login below (which hold
-- INDBINX0000001, INDBINC0000002, INDBINA0000003 and INDBINM0000004 as
-- fixed convenience credentials, not earned through activation), so this
-- counter can never land on a number one of them already holds. Starting
-- every role at the same low number looked safe for the *first* code
-- issued, but a monotonically increasing counter eventually reaches every
-- number - starting agents at 1 would collide the moment the third real
-- agent activates, since the demo agent already sits on A0000003.
DROP TABLE IF EXISTS party_code_sequences;
CREATE TABLE party_code_sequences (
    role_letter  CHAR(1)      NOT NULL PRIMARY KEY,
    next_number  INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO party_code_sequences (role_letter, next_number) VALUES
    ('X', 2), ('C', 3), ('A', 4), ('M', 5);

-- The merchant and agent modules both query an `admins` table. Rather than
-- a second password store, it is a thin extension of users.
DROP TABLE IF EXISTS admins;
CREATE TABLE admins (
    user_id     INT UNSIGNED PRIMARY KEY,
    designation VARCHAR(80)  NOT NULL DEFAULT 'Reviewer',
    can_approve_kyc      TINYINT(1) NOT NULL DEFAULT 1,
    can_override_credit  TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_payouts   TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS otp_verifications;
CREATE TABLE otp_verifications (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NULL,
    purpose      ENUM('registration','ekyc','bank_link','login','high_value_txn','payout') NOT NULL,
    destination  VARCHAR(190) NOT NULL,
    otp_hash     CHAR(64)     NOT NULL,
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    consumed_at  DATETIME     NULL,
    expires_at   DATETIME     NOT NULL,
    request_ip   VARBINARY(16) NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_otp_lookup (destination, purpose, consumed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 2. KYC AND DOCUMENTS  (shared by all three roles)
-- =====================================================================

DROP TABLE IF EXISTS kyc_details;
CREATE TABLE kyc_details (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    full_name        VARCHAR(150) NOT NULL,
    mobile           VARCHAR(15)  NOT NULL,
    dob              DATE         NULL,
    gender           ENUM('male','female','other','undisclosed') NULL,
    kyc_mode         ENUM('aadhaar_otp','digilocker','video_kyc','offline_xml','manual')
                     NOT NULL DEFAULT 'manual',
    doc_type         VARCHAR(50)  NOT NULL,
    doc_number_last4 CHAR(4)      NULL,
    doc_number_enc   VARBINARY(512) NULL,
    doc_number_hash  CHAR(64)     NULL,
    face_match_score DECIMAL(5,2) NULL,
    liveness_passed  TINYINT(1)   NULL,
    status           ENUM('pending','approved','rejected','resubmit') NOT NULL DEFAULT 'pending',
    reviewer_id      INT UNSIGNED NULL,
    reviewed_at      DATETIME     NULL,
    rejection_reason VARCHAR(255) NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_kyc_user (user_id),
    KEY idx_kyc_status (status),
    CONSTRAINT fk_kyc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The merchant and agent modules write here. Columns match their queries.
DROP TABLE IF EXISTS kyc_documents;
CREATE TABLE kyc_documents (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                INT UNSIGNED NOT NULL,
    full_name_on_document  VARCHAR(150) NOT NULL,
    mobile_number          VARCHAR(15)  NOT NULL,
    document_type          VARCHAR(50)  NOT NULL,
    document_number        VARCHAR(64)  NULL,
    id_photo_path          VARCHAR(255) NULL,
    status                 ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewer_id            INT UNSIGNED NULL,
    reviewed_at            DATETIME     NULL,
    rejection_reason       VARCHAR(255) NULL,
    created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_kycdoc_user (user_id, status),
    CONSTRAINT fk_kycdoc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Versioned vault used by the customer module.
DROP TABLE IF EXISTS customer_documents;
CREATE TABLE customer_documents (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    stage            ENUM('ekyc','profile','credit','products','support','other') NOT NULL DEFAULT 'other',
    doc_code         VARCHAR(50)  NOT NULL,
    doc_label        VARCHAR(120) NOT NULL,
    doc_number_last4 CHAR(4)      NULL,
    stored_name      VARCHAR(180) NOT NULL,
    original_name    VARCHAR(180) NOT NULL,
    mime_type        VARCHAR(80)  NOT NULL,
    size_bytes       INT UNSIGNED NOT NULL,
    sha256           CHAR(64)     NOT NULL,
    version          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_current       TINYINT(1)   NOT NULL DEFAULT 1,
    expires_on       DATE         NULL,
    status           ENUM('pending','verified','rejected','expired') NOT NULL DEFAULT 'pending',
    reviewer_id      INT UNSIGNED NULL,
    reviewed_at      DATETIME     NULL,
    rejection_reason VARCHAR(255) NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_doc_user_code (user_id, doc_code, is_current),
    KEY idx_doc_status    (status),
    CONSTRAINT fk_doc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS consents;
CREATE TABLE consents (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    consent_type   ENUM('dpdp_notice','bureau_pull','comm_sms','comm_whatsapp','comm_email',
                        'aadhaar_ekyc','account_aggregator','product_kfs','terms','agent_agreement',
                        'merchant_agreement') NOT NULL,
    artifact_ref   VARCHAR(120) NULL,
    policy_version VARCHAR(20)  NOT NULL,
    granted        TINYINT(1)   NOT NULL,
    channel        ENUM('web','app','agent','ivr') NOT NULL DEFAULT 'web',
    request_ip     VARBINARY(16) NULL,
    user_agent     VARCHAR(255) NULL,
    valid_until    DATE         NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_consent_user (user_id, consent_type, created_at),
    CONSTRAINT fk_consent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 3. CUSTOMER MODULE
-- =====================================================================

DROP TABLE IF EXISTS customer_profiles;
CREATE TABLE customer_profiles (
    user_id            INT UNSIGNED PRIMARY KEY,
    dob                DATE         NULL,
    address_line1      VARCHAR(150) NULL,
    address_line2      VARCHAR(150) NULL,
    city               VARCHAR(80)  NULL,
    state              VARCHAR(80)  NULL,
    pincode            CHAR(6)      NULL,
    address_same_as_id TINYINT(1)   NOT NULL DEFAULT 1,
    occupation         VARCHAR(80)  NULL,
    employment_type    ENUM('salaried','self_employed','student','retired','other') NULL,
    annual_income_band ENUM('lt_3l','3l_6l','6l_12l','12l_25l','gt_25l') NULL,
    preferred_language VARCHAR(40)  NOT NULL DEFAULT 'English',
    primary_category   VARCHAR(100) NULL,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS payment_instruments;
CREATE TABLE payment_instruments (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    instrument     ENUM('bank','upi','card') NOT NULL,
    account_holder VARCHAR(150) NULL,
    bank_name      VARCHAR(150) NULL,
    account_last4  CHAR(4)      NULL,
    account_enc    VARBINARY(512) NULL,
    account_hash   CHAR(64)     NULL,
    ifsc_code      VARCHAR(11)  NULL,
    upi_handle     VARCHAR(100) NULL,
    card_last4     CHAR(4)      NULL,
    card_network   VARCHAR(20)  NULL,
    card_token     VARCHAR(120) NULL,
    is_primary     TINYINT(1)   NOT NULL DEFAULT 0,
    verification   ENUM('unverified','penny_drop_sent','verified','failed') NOT NULL DEFAULT 'unverified',
    verified_at    DATETIME     NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_instr (user_id, instrument, account_hash),
    KEY idx_instr_user (user_id, is_primary),
    CONSTRAINT fk_instr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS bank_mandates;
CREATE TABLE bank_mandates (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    instrument_id  BIGINT UNSIGNED NULL,
    umrn           VARCHAR(32)  NULL,
    account_holder VARCHAR(150) NOT NULL,
    bank_name      VARCHAR(150) NOT NULL,
    account_last4  CHAR(4)      NOT NULL,
    ifsc_code      VARCHAR(11)  NOT NULL,
    mandate_limit  DECIMAL(12,2) NOT NULL DEFAULT 50000.00,
    frequency      ENUM('adhoc','monthly','quarterly') NOT NULL DEFAULT 'monthly',
    valid_from     DATE NULL,
    valid_to       DATE NULL,
    status         ENUM('draft','pending','active','paused','revoked','failed') NOT NULL DEFAULT 'draft',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mandate_umrn (umrn),
    KEY idx_mandate_user (user_id, status),
    CONSTRAINT fk_mandate_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS loyalty_enrollments;
CREATE TABLE loyalty_enrollments (
    user_id      INT UNSIGNED PRIMARY KEY,
    program_tier VARCHAR(60) NOT NULL DEFAULT 'Tier-1 Rewards',
    enrolled_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status       ENUM('active','paused','exited') NOT NULL DEFAULT 'active',
    CONSTRAINT fk_loy_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS loyalty_ledger;
CREATE TABLE loyalty_ledger (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    entry_type    ENUM('welcome','earn','redeem','expiry','adjustment','referral') NOT NULL,
    points        INT NOT NULL,
    balance_after INT NOT NULL,
    reference     VARCHAR(80) NULL,
    expires_on    DATE NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ledger_user (user_id, created_at),
    CONSTRAINT fk_ledger_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS referrals;
CREATE TABLE referrals (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    referee_name    VARCHAR(150) NOT NULL,
    referee_phone   VARCHAR(15)  NOT NULL,
    referee_user_id INT UNSIGNED NULL,
    status          ENUM('invited','registered','onboarded','expired') NOT NULL DEFAULT 'invited',
    reward_points   INT NOT NULL DEFAULT 0,
    rewarded_at     DATETIME NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_referral (user_id, referee_phone),
    CONSTRAINT fk_ref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 4. CREDIT  (one engine, both naming conventions)
-- =====================================================================

-- Versioned, used by the customer modules scoring engine.
DROP TABLE IF EXISTS credit_evaluations;
CREATE TABLE credit_evaluations (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    evaluation_no    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_current       TINYINT(1) NOT NULL DEFAULT 1,
    engine           ENUM('rule','ml_model','bureau','manual') NOT NULL DEFAULT 'rule',
    model_version    VARCHAR(30) NULL,
    credit_score     SMALLINT UNSIGNED NOT NULL,
    bureau_score     SMALLINT UNSIGNED NULL,
    risk_category    ENUM('low','moderate','high','declined') NOT NULL,
    max_credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    bnpl_limit       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reason_codes     JSON NULL,
    input_snapshot   JSON NULL,
    decision         ENUM('auto_approved','manual_review','declined') NOT NULL DEFAULT 'manual_review',
    override_by      INT UNSIGNED NULL,
    override_reason  VARCHAR(255) NULL,
    valid_until      DATE NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_eval (user_id, evaluation_no),
    KEY idx_eval_current (user_id, is_current),
    CONSTRAINT fk_eval_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The merchant modules name for the same idea. Kept so merchant/credit/*
-- keeps running unchanged; core/workflow.php writes both.
DROP TABLE IF EXISTS credit_assessments;
CREATE TABLE credit_assessments (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    risk_score      SMALLINT UNSIGNED NOT NULL,
    risk_category   VARCHAR(30)  NOT NULL,
    credit_limit    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    factors         TEXT         NULL,
    is_override     TINYINT(1)   NOT NULL DEFAULT 0,
    overridden_by   INT UNSIGNED NULL,
    override_reason VARCHAR(255) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_assess_user (user_id, created_at),
    CONSTRAINT fk_assess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 5. MERCHANT MODULE
-- =====================================================================

DROP TABLE IF EXISTS business_verifications;
CREATE TABLE business_verifications (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id               INT UNSIGNED NOT NULL,
    business_address      TEXT         NULL,
    gstin                 VARCHAR(20)  NULL,
    years_in_business     DECIMAL(4,1) NULL,
    -- A band, not an amount: the form offers these five and nothing
    -- else, and merchant/credit/scoring.php scores on the code.
    monthly_turnover      ENUM('below_1L','1L_5L','5L_25L','25L_1Cr','above_1Cr') NULL,
    bank_account_number   VARCHAR(64)  NULL,
    ifsc_code             VARCHAR(11)  NULL,
    gst_certificate_path  VARCHAR(255) NULL,
    shop_photo_path       VARCHAR(255) NULL,
    address_proof_path    VARCHAR(255) NULL,
    cancelled_cheque_path VARCHAR(255) NULL,
    status                ENUM('pending','approved','rejected','resubmit') NOT NULL DEFAULT 'pending',
    reviewer_id           INT UNSIGNED NULL,
    reviewed_at           DATETIME     NULL,
    rejection_reason      VARCHAR(255) NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bizver_user (user_id),
    CONSTRAINT fk_bizver_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS settlement_accounts;
CREATE TABLE settlement_accounts (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    account_holder VARCHAR(150) NOT NULL,
    bank_name      VARCHAR(150) NULL,     -- no screen collects this
    account_number VARCHAR(64)  NOT NULL,
    ifsc_code      VARCHAR(11)  NOT NULL,
    is_primary     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_settle_user (user_id),
    CONSTRAINT fk_settle_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS payout_preferences;
CREATE TABLE payout_preferences (
    user_id           INT UNSIGNED PRIMARY KEY,
    payout_frequency  VARCHAR(30)  NOT NULL DEFAULT 'weekly',
    payout_day        VARCHAR(20)  NULL,
    minimum_threshold DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notify_email      TINYINT(1) NOT NULL DEFAULT 1,
    notify_sms        TINYINT(1) NOT NULL DEFAULT 0,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_payoutpref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS merchant_services;
CREATE TABLE merchant_services (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    service_key VARCHAR(60)  NOT NULL,
    status      ENUM('requested','approved','rejected','suspended') NOT NULL DEFAULT 'requested',
    reviewer_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_service (user_id, service_key),
    CONSTRAINT fk_service_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS agreement_signatures;
CREATE TABLE agreement_signatures (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    agreement_type VARCHAR(60)  NOT NULL DEFAULT 'merchant_master',

    -- Names match merchant/agreements/sign.php, which writes these, and
    -- merchant/agreements/status.php, which reads them back.
    signature_image_path    VARCHAR(255) NULL,
    signed_name             VARCHAR(150) NULL,
    credit_limit_at_signing DECIMAL(12,2) NULL,

    -- Text, not packed bytes: the code stores REMOTE_ADDR verbatim, and an
    -- IPv6 address written out needs 45 characters.
    ip_address     VARCHAR(45)  NULL,
    user_agent     VARCHAR(255) NULL,
    agreement_version VARCHAR(20) NOT NULL DEFAULT 'v1.0',
    signed_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sign_user (user_id),
    CONSTRAINT fk_sign_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS training_progress;
CREATE TABLE training_progress (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    topic_key  VARCHAR(60)  NOT NULL,
    status     ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'completed',
    completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_training (user_id, topic_key),
    CONSTRAINT fk_training_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 6. AGENT MODULE
-- =====================================================================

DROP TABLE IF EXISTS agent_verification;
CREATE TABLE agent_verification (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_id              INT UNSIGNED NOT NULL,
    role                  VARCHAR(60)  NULL,
    area                  VARCHAR(120) NULL,
    document_type         VARCHAR(60)  NULL,
    verification_address  TEXT         NULL,
    reference_name        VARCHAR(150) NULL,
    reference_mobile      VARCHAR(15)  NULL,
    reference_relationship VARCHAR(60) NULL,
    reference_status      ENUM('pending','verified','failed') NOT NULL DEFAULT 'pending',
    field_status          ENUM('pending','verified','failed') NOT NULL DEFAULT 'pending',
    background_status     ENUM('pending','verified','failed') NOT NULL DEFAULT 'pending',
    admin_remarks         VARCHAR(255) NULL,
    reviewed_at           DATETIME NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_agentver (agent_id),
    CONSTRAINT fk_agentver_user FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS background_verifications;
CREATE TABLE background_verifications (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    full_name  VARCHAR(150) NULL,
    check_type VARCHAR(60)  NOT NULL DEFAULT 'identity',

    -- agent/admin/admin_review.php reads the field-visit record from here.
    agent_address          TEXT         NULL,
    area                   VARCHAR(120) NULL,
    document_type          VARCHAR(60)  NULL,
    role_assignment        VARCHAR(60)  NULL,
    reference_name         VARCHAR(150) NULL,
    reference_mobile       VARCHAR(15)  NULL,
    reference_relationship VARCHAR(60)  NULL,
    reference_check        ENUM('pending','verified','failed') NOT NULL DEFAULT 'pending',
    field_verification     ENUM('pending','verified','failed') NOT NULL DEFAULT 'pending',

    status     ENUM('pending','verified','failed') NOT NULL DEFAULT 'pending',
    remarks    VARCHAR(255) NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bgv_user (user_id, status),
    CONSTRAINT fk_bgv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS agent_training;
CREATE TABLE agent_training (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    product_training    VARCHAR(30) NOT NULL DEFAULT 'pending',
    compliance_training VARCHAR(30) NOT NULL DEFAULT 'pending',
    certification_test  VARCHAR(30) NOT NULL DEFAULT 'pending',
    certification_score TINYINT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_agenttrain (user_id),
    CONSTRAINT fk_agenttrain_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS agent_commission_payout;
CREATE TABLE agent_commission_payout (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    commission_type VARCHAR(50)  NOT NULL DEFAULT 'Percentage',
    commission_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    account_holder  VARCHAR(150) NOT NULL DEFAULT '',
    account_number  VARCHAR(100) NOT NULL DEFAULT '',
    bank_name       VARCHAR(150) NOT NULL DEFAULT '',
    ifsc_code       VARCHAR(30)  NOT NULL DEFAULT '',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_agentpayout (user_id),
    CONSTRAINT fk_agentpayout_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS agent_hierarchy;
CREATE TABLE agent_hierarchy (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    agent_id        VARCHAR(32)  NULL,
    registered_name VARCHAR(150) NULL,
    email           VARCHAR(190) NULL,
    referral_code   VARCHAR(24)  NULL,
    upline_id       INT UNSIGNED NULL,
    downline_ids    TEXT         NULL,

    depth           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hierarchy (user_id),
    KEY idx_hierarchy_upline (upline_id),
    CONSTRAINT fk_hierarchy_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS agent_wallet;
CREATE TABLE agent_wallet (
    user_id         INT UNSIGNED PRIMARY KEY,
    balance         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    lifetime_earned DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    lifetime_paid   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    wallet_status   VARCHAR(20)   NOT NULL DEFAULT 'active',
    commission_rate DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 7. PRODUCTS
-- =====================================================================

-- Catalogue shared by all three modules. The agent module reads `products`
-- and the customer module reads `product_catalog`; one table, one view over
-- it, so neither has to change and the two can never disagree.
DROP TABLE IF EXISTS product_catalog;
CREATE TABLE product_catalog (
    product_code    VARCHAR(40) PRIMARY KEY,
    product_name    VARCHAR(150) NOT NULL,
    category        VARCHAR(30) NOT NULL,
    description     TEXT NULL,
    requires_credit TINYINT(1) NOT NULL DEFAULT 0,
    requires_kfs    TINYINT(1) NOT NULL DEFAULT 0,
    min_score       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    agent_commission_rate    DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    merchant_commission_rate DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,

    -- Every product in this table has an agent_commission_rate and a
    -- merchant_commission_rate, because an agent or a merchant can be the
    -- one who sells a product to a customer. That is different from a
    -- product being FOR a merchant's own business, which is what
    -- customer_facing distinguishes: Merchant Business Loan is working
    -- capital a merchant borrows for their shop, never something a
    -- customer applies for themselves, so it is excluded from the three
    -- customer-facing catalog views (onboarding's product step, Offers,
    -- and the Applications "raise a requirement" list) on this flag.
    customer_facing TINYINT(1) NOT NULL DEFAULT 1,

    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE OR REPLACE VIEW products AS
    SELECT product_code AS id, product_name AS name, category, description,
           agent_commission_rate AS commission_rate, status, created_at
      FROM product_catalog;

DROP TABLE IF EXISTS product_activations;
CREATE TABLE product_activations (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    product_code   VARCHAR(40)  NOT NULL,
    status         ENUM('requested','active','suspended','closed','declined') NOT NULL DEFAULT 'requested',
    assigned_limit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    evaluation_id  BIGINT UNSIGNED NULL,
    kfs_consent_id BIGINT UNSIGNED NULL,
    activated_at   DATETIME NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_activation (user_id, product_code),
    KEY idx_act_product (product_code),
    CONSTRAINT fk_act_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_act_product FOREIGN KEY (product_code) REFERENCES product_catalog(product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 8. THE INTEGRATION LAYER
--    leads -> applications -> decision -> commissions
-- =====================================================================

-- A customer as the agent and merchant modules see them: a CRM record,
-- distinct from the login row in users. user_id is NULL until they register.
DROP TABLE IF EXISTS customers;
CREATE TABLE customers (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    agent_id    INT UNSIGNED NULL,
    merchant_id INT UNSIGNED NULL,
    name        VARCHAR(150) NOT NULL,
    mobile      VARCHAR(15)  NOT NULL,
    email       VARCHAR(190) NULL,
    requirement TEXT         NULL,
    source      ENUM('self','agent','merchant','referral','import') NOT NULL DEFAULT 'self',
    status      ENUM('prospect','onboarding','active','dormant') NOT NULL DEFAULT 'prospect',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_mobile (mobile),
    KEY idx_cust_user (user_id),
    KEY idx_cust_agent (agent_id),
    KEY idx_cust_merchant (merchant_id),
    CONSTRAINT fk_cust_user     FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_cust_agent    FOREIGN KEY (agent_id)    REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_cust_merchant FOREIGN KEY (merchant_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS leads;
CREATE TABLE leads (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id  BIGINT UNSIGNED NULL,
    agent_id     INT UNSIGNED NULL,
    merchant_id  INT UNSIGNED NULL,
    name         VARCHAR(150) NOT NULL,
    mobile       VARCHAR(15)  NOT NULL,
    email        VARCHAR(190) NULL,
    requirement  TEXT         NULL,
    product_code VARCHAR(40)  NULL,
    amount       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    source       ENUM('customer_request','agent_sourced','merchant_referral','campaign','walk_in')
                 NOT NULL DEFAULT 'agent_sourced',
    status       ENUM('new','assigned','contacted','interested','not_interested',
                      'nurturing','converted','lost') NOT NULL DEFAULT 'new',
    next_follow_up DATE NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lead_agent    (agent_id, status),
    KEY idx_lead_merchant (merchant_id, status),
    KEY idx_lead_customer (customer_id),
    CONSTRAINT fk_lead_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_lead_agent    FOREIGN KEY (agent_id)    REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_lead_merchant FOREIGN KEY (merchant_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS quotes;
CREATE TABLE quotes (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id      BIGINT UNSIGNED NOT NULL,
    merchant_id  INT UNSIGNED NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    product_code VARCHAR(40)  NULL,
    amount       DECIMAL(12,2) NOT NULL,
    notes        TEXT NULL,
    valid_until  DATE NULL,
    status       ENUM('draft','sent','accepted','declined','expired') NOT NULL DEFAULT 'sent',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_quote_lead (lead_id),
    KEY idx_quote_merchant (merchant_id, status),
    CONSTRAINT fk_quote_lead     FOREIGN KEY (lead_id)     REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_quote_merchant FOREIGN KEY (merchant_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- THE single applications table.
--
-- All three parties are nullable and all three can be present at once,
-- which is the whole point: a customer raises it, an agent assists, a
-- merchant fulfils, and the commission split follows from the row.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS applications;
CREATE TABLE applications (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_number VARCHAR(32)  NOT NULL,

    customer_id        INT UNSIGNED NULL,      -- users.id, role customer
    agent_id           INT UNSIGNED NULL,      -- users.id, role agent
    merchant_id        INT UNSIGNED NULL,      -- users.id, role merchant
    lead_id            BIGINT UNSIGNED NULL,
    quote_id           BIGINT UNSIGNED NULL,

    product_code       VARCHAR(40)  NULL,
    product_name       VARCHAR(150) NOT NULL,
    category           VARCHAR(60)  NOT NULL DEFAULT 'loan',
    application_type   VARCHAR(60)  NOT NULL DEFAULT 'standard',
    purpose            VARCHAR(255) NULL,
    amount             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tenure_months      SMALLINT UNSIGNED NULL,
    notes              TEXT NULL,

    -- The five stages the dashboards draw as a track.
    current_stage      ENUM('submitted','kyc_check','credit_review','approved','disbursed')
                       NOT NULL DEFAULT 'submitted',
    status             ENUM('draft','requirement_raised','submitted','under_review',
                            'approved','rejected','disbursed','completed','withdrawn')
                       NOT NULL DEFAULT 'requirement_raised',

    assigned_to        INT UNSIGNED NULL,      -- reviewing admin
    decision_by        INT UNSIGNED NULL,
    reviewed_at        DATETIME NULL,
    rejection_reason   VARCHAR(255) NULL,
    disbursed_at       DATETIME NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- the merchant review screen orders by submitted_at
    submitted_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_app_number (application_number),
    KEY idx_app_customer (customer_id, status),
    KEY idx_app_agent    (agent_id, status),
    KEY idx_app_merchant (merchant_id, status),
    KEY idx_app_stage    (current_stage, status),
    CONSTRAINT fk_app_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_app_agent    FOREIGN KEY (agent_id)    REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_app_merchant FOREIGN KEY (merchant_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_app_lead     FOREIGN KEY (lead_id)     REFERENCES leads(id)  ON DELETE SET NULL,
    CONSTRAINT fk_app_quote    FOREIGN KEY (quote_id)    REFERENCES quotes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Compatibility view, READ ONLY.
--
-- The customer modules older SELECTs read customer_applications; this keeps
-- them working against the one table. Do not INSERT through it:
-- applications.application_number is NOT NULL with no default, and the view
-- does not expose it. Writes go through create_application() in
-- core/workflow.php, which also assigns the agent, records the stage event
-- and notifies all three parties.
CREATE OR REPLACE VIEW customer_applications AS
    SELECT id, customer_id AS user_id, product_code, product_name, category,
           amount, notes, status, agent_id AS assigned_agent_id, created_at, updated_at
      FROM applications;

-- Every state change, so a track can be drawn with real timestamps rather
-- than inferred from the current status alone.
DROP TABLE IF EXISTS application_events;
CREATE TABLE application_events (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    actor_id       INT UNSIGNED NULL,
    actor_role     VARCHAR(20)  NULL,
    from_stage     VARCHAR(30)  NULL,
    to_stage       VARCHAR(30)  NOT NULL,
    note           VARCHAR(255) NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_appevent (application_id, created_at),
    CONSTRAINT fk_appevent_app FOREIGN KEY (application_id)
        REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS self_service_applications;
CREATE TABLE self_service_applications (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    merchant_id      INT UNSIGNED NOT NULL,
    application_type VARCHAR(60)  NOT NULL,
    amount           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tenure_months    SMALLINT UNSIGNED NULL,
    purpose          VARCHAR(255) NULL,
    notes            TEXT NULL,
    status           ENUM('submitted','under_review','approved','rejected') NOT NULL DEFAULT 'submitted',
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_selfserv_merchant (merchant_id, status),
    CONSTRAINT fk_selfserv_user FOREIGN KEY (merchant_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 9. MONEY
-- =====================================================================

DROP TABLE IF EXISTS repayments;
CREATE TABLE repayments (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id     BIGINT UNSIGNED NULL,
    user_id            INT UNSIGNED NOT NULL,   -- who owes it
    merchant_id        INT UNSIGNED NULL,
    loan_title         VARCHAR(150) NOT NULL,
    installment_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    instalment_no      SMALLINT UNSIGNED NOT NULL DEFAULT 1,  -- customer module spelling
    amount             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_due         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_paid        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    due_date           DATE NOT NULL,
    status             ENUM('pending','paid','overdue','waived','failed') NOT NULL DEFAULT 'pending',
    payment_method     VARCHAR(50) NULL,
    mandate_id         BIGINT UNSIGNED NULL,
    paid_at            DATETIME NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rep_user (user_id, due_date, status),
    KEY idx_rep_app  (application_id),
    CONSTRAINT fk_rep_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rep_app  FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS transactions;
CREATE TABLE transactions (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id   BIGINT UNSIGNED NULL,
    merchant_id      INT UNSIGNED NULL,
    customer_id      INT UNSIGNED NULL,
    transaction_type ENUM('disbursement','repayment','settlement','refund','fee','commission')
                     NOT NULL,
    amount           DECIMAL(12,2) NOT NULL,
    reference_number VARCHAR(64)  NOT NULL,
    status           ENUM('initiated','success','failed','reversed') NOT NULL DEFAULT 'initiated',
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    transaction_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_txn_ref (reference_number),
    KEY idx_txn_merchant (merchant_id, created_at),
    KEY idx_txn_app (application_id),
    CONSTRAINT fk_txn_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per earner per application. An agent-assisted, merchant-fulfilled
-- application produces two rows, which is exactly the split the flow charts
-- commission structure describes.
DROP TABLE IF EXISTS commissions;
CREATE TABLE commissions (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    earner_id      INT UNSIGNED NOT NULL,
    earner_role    ENUM('agent','merchant') NOT NULL,
    basis_amount   DECIMAL(12,2) NOT NULL,
    rate           DECIMAL(6,2)  NOT NULL,
    commission_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
    amount         DECIMAL(12,2) NOT NULL,
    -- pending and credited are the agent module words for accrued and
    -- paid. Carried in the enum so its queries return rows instead of erroring.
    status         ENUM('accrued','approved','paid','reversed','withheld','pending','credited')
                   NOT NULL DEFAULT 'accrued',
    source         VARCHAR(60) NULL,
    payout_id      BIGINT UNSIGNED NULL,
    approved_by    INT UNSIGNED NULL,
    accrued_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at        DATETIME NULL,

    UNIQUE KEY uq_commission (application_id, earner_id, earner_role),
    KEY idx_comm_earner (earner_id, status),
    CONSTRAINT fk_comm_app   FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_comm_earner FOREIGN KEY (earner_id)     REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS commission_payouts;
CREATE TABLE commission_payouts (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    earner_id     INT UNSIGNED NOT NULL,
    earner_role   ENUM('agent','merchant') NOT NULL,
    period_start  DATE NOT NULL,
    period_end    DATE NOT NULL,
    gross_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tds_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    net_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    utr_reference VARCHAR(64) NULL,
    status        ENUM('pending','processing','paid','failed') NOT NULL DEFAULT 'pending',
    processed_by  INT UNSIGNED NULL,
    paid_at       DATETIME NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payout_earner (earner_id, status),
    CONSTRAINT fk_payout_earner FOREIGN KEY (earner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 10. COMMUNICATION AND AUDIT
-- =====================================================================

DROP TABLE IF EXISTS support_tickets;
CREATE TABLE support_tickets (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_code    VARCHAR(24)  NOT NULL,
    user_id        INT UNSIGNED NOT NULL,
    merchant_id    INT UNSIGNED NULL,
    application_id BIGINT UNSIGNED NULL,
    category       VARCHAR(60)  NOT NULL DEFAULT 'general',
    subject        VARCHAR(190) NOT NULL,
    description    TEXT         NULL,
    message        TEXT         NULL,
    body           TEXT         NULL,   -- the customer module name for the same field
    admin_response TEXT         NULL,
    priority       ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    status         ENUM('open','in_progress','waiting_customer','resolved','closed')
                   NOT NULL DEFAULT 'open',
    assigned_to    INT UNSIGNED NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket (ticket_code),
    KEY idx_ticket_user (user_id, status),
    CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS messages;
CREATE TABLE messages (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sender_id    INT UNSIGNED NULL,
    sender_name  VARCHAR(150) NULL,
    sender_role  VARCHAR(20)  NULL,
    recipient_id INT UNSIGNED NOT NULL,
    subject     VARCHAR(190) NULL,
    body        TEXT NOT NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_msg_recipient (recipient_id, is_read),
    CONSTRAINT fk_msg_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS notifications;
CREATE TABLE notifications (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    channel    ENUM('inapp','sms','email','whatsapp','push') NOT NULL DEFAULT 'inapp',
    title      VARCHAR(190) NOT NULL,
    message    TEXT NOT NULL,
    link       VARCHAR(255) NULL,
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notif_user (user_id, is_read, created_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS audit_logs;
CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id    INT UNSIGNED NULL,
    actor_role  VARCHAR(20)  NULL,
    subject_id  INT UNSIGNED NULL,
    action      VARCHAR(60)  NOT NULL,
    entity      VARCHAR(60)  NOT NULL,
    entity_id   VARCHAR(60)  NULL,
    before_json JSON NULL,
    after_json  JSON NULL,
    request_ip  VARBINARY(16) NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_subject (subject_id, created_at),
    KEY idx_audit_action  (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- 11. SEED
-- =====================================================================

INSERT INTO product_catalog
 (product_code, product_name, category, description, requires_credit, requires_kfs,
  min_score, agent_commission_rate, merchant_commission_rate, status, is_active, customer_facing) VALUES
 ('INV_MF',        'Mutual Funds',                            'investments',      'Curated mutual fund schemes across risk profiles.',       0,1,  0, 1.00, 0.00, 'active', 1, 1),
 ('INV_SIP',       'SIP & Wealth Planning',                   'investments',      'A systematic investment plan toward a goal.',             0,1,  0, 1.00, 0.00, 'active', 1, 1),
 ('INV_STOCKS',    'Stocks & ETFs',                           'investments',      'Trade listed stocks and exchange-traded funds.',          0,1,  0, 0.50, 0.00, 'active', 1, 1),
 ('INV_BONDS',     'Bonds & Fixed Deposits',                  'investments',      'Fixed-income options for steady, predictable returns.',   0,1,  0, 0.75, 0.00, 'active', 1, 1),
 ('INV_RETIRE',    'Retirement Planning',                     'investments',      'Plan and invest toward retirement.',                      0,1,  0, 1.00, 0.00, 'active', 1, 1),
 ('INV_COMPARE',   'Investment Comparison',                   'investments',      'Compare investment options side by side.',                0,0,  0, 0.00, 0.00, 'active', 1, 1),
 ('INV_ADVISORY',  'Financial Advisory Support',              'investments',      'Guidance from a financial advisor.',                      0,0,  0, 0.00, 0.00, 'active', 1, 1),
 ('DB_UPI',        'UPI & QR Payments',                       'digital_banking',  'Send, receive and pay using UPI and QR codes.',           0,0,  0, 0.00, 0.50, 'active', 1, 1),
 ('DB_AEPS',       'AEPS & Money Transfer',                   'digital_banking',  'Aadhaar-enabled payment and money transfer services.',    0,0,  0, 0.50, 0.50, 'active', 1, 1),
 ('DB_BNPL',       'BNPL Solutions',                          'digital_banking',  'Split a purchase over instalments.',                      1,1,650, 1.50, 1.00, 'active', 1, 1),
 ('DB_LOAN',       'Loan & Credit Facilitation',              'digital_banking',  'Unsecured personal credit.',                              1,1,650, 2.50, 1.50, 'active', 1, 1),
 ('DB_SERVICES',   'Digital Banking Services',                'digital_banking',  'Core digital banking account and services.',              0,0,  0, 0.50, 0.50, 'active', 1, 1),
 ('DB_MERCHONBRD', 'Merchant Onboarding',                     'digital_banking',  'Onboard as a merchant and accept digital payments.',      0,0,  0, 0.00, 0.00, 'active', 1, 0),
 ('DB_API',        'Banking API Integration',                 'digital_banking',  'Integrate INDBIN banking services via API.',              0,0,  0, 0.00, 0.00, 'active', 1, 0),
 ('DB_WHITELABEL', 'Fintech CRM & White-Label Solutions',     'digital_banking',  'White-label CRM and fintech tooling for partners.',       0,0,  0, 0.00, 0.00, 'active', 1, 0),
 ('INS_LIFECOVER', 'Life Insurance',                          'insurance',        'Term and whole-life cover.',                              0,1,  0, 5.00, 2.00, 'active', 1, 1),
 ('INS_HEALTHCARE', 'Health Insurance',                        'insurance',        'Individual and family medical cover.',                    0,1,  0, 5.00, 2.00, 'active', 1, 1),
 ('INS_MOTOR',     'Motor Insurance',                         'insurance',        'Cover for two-wheelers and cars.',                        0,1,  0, 4.00, 2.00, 'active', 1, 1),
 ('INS_ACCIDENT',  'Personal Accident Insurance',             'insurance',        'Cover against accidental injury or death.',               0,1,  0, 4.00, 2.00, 'active', 1, 1),
 ('INS_TRAVEL',    'Travel Insurance',                        'insurance',        'Cover for domestic and international trips.',             0,1,  0, 3.00, 1.00, 'active', 1, 1),
 ('INS_BUSINESS',  'Business Insurance',                      'insurance',        'Cover for a merchant''s shop, stock and liability.',      0,1,  0, 4.00, 2.00, 'active', 1, 0),
 ('INS_RENEWAL',   'Policy Renewal & Support',                'insurance',        'Renew an existing policy or get help with one.',          0,0,  0, 1.00, 0.50, 'active', 1, 1),
 ('INS_CLAIMS',    'Claims Assistance',                       'insurance',        'Help filing and tracking an insurance claim.',            0,0,  0, 1.00, 0.50, 'active', 1, 1),
 ('AI_DISCOVERY',  'AI Financial Product Discovery',          'ai_advisory',      'AI-matched product suggestions based on your profile.',   0,0,  0, 0.00, 0.00, 'active', 1, 1),
 ('AI_INSCOMP',    'Insurance Comparison',                    'ai_advisory',      'Compare insurance policies side by side.',                0,0,  0, 0.00, 0.00, 'active', 1, 1),
 ('AI_LOANELIG',   'Loan Eligibility Discovery',              'ai_advisory',      'Check what credit you are likely to qualify for.',        0,0,  0, 0.00, 0.00, 'active', 1, 1),
 ('AI_PLANNING',   'Financial Planning Support',              'ai_advisory',      'A structured plan across your financial goals.',          0,0,  0, 0.00, 0.00, 'active', 1, 1),
 ('AI_CRM',        'Customer Relationship Management',        'ai_advisory',      'A dedicated relationship manager for your account.',      0,0,  0, 0.00, 0.00, 'active', 1, 1);

-- ---------------------------------------------------------------------
-- Starter logins.
--
-- These run near the END of the file. phpMyAdmin stops at the first
-- statement that errors, so if anything above failed you will have all the
-- tables and none of these accounts, and every login will be refused.
-- sql/reset_admin.sql creates them on their own if that happens. Change every password before this leaves your laptop:
-- these hashes are in a file anyone can read.
--
--   admin@indbin.local     Admin@123
--   customer@indbin.local  Test@1234
--   agent@indbin.local     Test@1234
--   merchant@indbin.local  Test@1234
-- ---------------------------------------------------------------------
INSERT INTO users
 (role, full_name, email, mobile, password_hash, party_code, customer_code,
  business_name, referral_code, kyc_status, onboarding_step, account_status, mobile_verified)
VALUES
 ('admin','M Satyanarayana','admin@indbin.local','9000000001',
  '$2y$10$2HCLLAE4pfI43nvma4RWGeiri1hzorrYoMzIyVBCxmmzrpSMtjeuy',
  'INDBINX0000001', NULL, NULL, NULL, 'approved', 7, 'active', 1),

 ('customer','Test Customer','customer@indbin.local','9000000002',
  '$2y$10$mH/if.3L61Ooq26bPyEnRup0nwPoQ.Rci4FF6aQ5yA.JkvH.5uAZS',
  'INDBINC0000002', NULL, NULL, NULL, 'not_started', 1, 'registered', 0),

 ('agent','Test Agent','agent@indbin.local','9000000003',
  '$2y$10$mH/if.3L61Ooq26bPyEnRup0nwPoQ.Rci4FF6aQ5yA.JkvH.5uAZS',
  'INDBINA0000003', NULL, NULL, 'AGT0003', 'not_started', 1, 'registered', 0),

 ('merchant','Test Merchant','merchant@indbin.local','9000000004',
  '$2y$10$mH/if.3L61Ooq26bPyEnRup0nwPoQ.Rci4FF6aQ5yA.JkvH.5uAZS',
  'INDBINM0000004', NULL, 'Shree Kirana Store', NULL, 'not_started', 1, 'registered', 0);

INSERT INTO admins (user_id, designation, can_approve_kyc, can_override_credit, can_manage_payouts)
SELECT id, 'Chief Executive', 1, 1, 1 FROM users WHERE email = 'admin@indbin.local';

INSERT INTO agent_wallet (user_id) SELECT id FROM users WHERE role = 'agent';

-- =====================================================================
-- 12. CHECK
--     Expect 43 tables and 2 views.
-- =====================================================================
SELECT
    SUM(TABLE_TYPE = 'BASE TABLE') AS tables_created,
    SUM(TABLE_TYPE = 'VIEW')       AS views_created
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE();




