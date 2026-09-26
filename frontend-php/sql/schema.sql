-- WhatsApp SaaS — consolidated schema (multi-tenant, user = tenant)
--
-- Applied automatically by the MySQL container on first initialisation.
-- Contains NO credentials: the bootstrap admin is created at container start
-- from ADMIN_EMAIL / ADMIN_PASSWORD. The previous schema shipped a seeded
-- bcrypt hash, which meant anyone with the file had the production login.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Plans / billing
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    -- Minor units (paisa for PKR, cents for USD). Never a float: 19.99 is not
    -- representable in binary and money must not drift.
    price_cents INT NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'PKR',
    billing_period ENUM('month','year','none') NOT NULL DEFAULT 'month',
    -- NULL means unlimited for every quota column below.
    max_wa_accounts INT DEFAULT NULL,
    max_contacts INT DEFAULT NULL,
    max_messages_per_month INT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Seeded prices are PKR minor units: 150000 paisa = Rs. 1,500.
--
-- This is a seed, not a fixture. `ON DUPLICATE KEY UPDATE code = code` is a
-- deliberate no-op: the file re-runs on every container start, and the previous
-- version reassigned name/description/price/limits every time, so any plan an
-- admin edited in the UI silently reverted on the next restart.
INSERT INTO plans (code, name, description, price_cents, currency, billing_period, max_wa_accounts, max_contacts, max_messages_per_month, sort_order)
VALUES
  ('free',    'Free',    'Try it out with a single WhatsApp number.',   0,      'PKR', 'month', 1,    1000,   1000,  1),
  ('starter', 'Starter', 'For solo operators and small teams.',         150000, 'PKR', 'month', 3,    25000,  25000, 2),
  ('pro',     'Pro',     'For agencies running many numbers.',          450000, 'PKR', 'month', 10,   NULL,   NULL,  3)
ON DUPLICATE KEY UPDATE code = code;

-- One-off conversion of deployments seeded before PKR was the default.
-- Guarded on the exact old seeded USD amounts, so a plan whose price an admin
-- has since changed is left alone — relabelling an edited price as PKR without
-- converting it would misstate what tenants are charged.
UPDATE plans SET price_cents = 0,      currency = 'PKR' WHERE code = 'free'    AND currency = 'USD' AND price_cents = 0;
UPDATE plans SET price_cents = 150000, currency = 'PKR' WHERE code = 'starter' AND currency = 'USD' AND price_cents = 1900;
UPDATE plans SET price_cents = 450000, currency = 'PKR' WHERE code = 'pro'     AND currency = 'USD' AND price_cents = 4900;

-- Per-plan feature flags, e.g. {"chatbot": true, "llm_byok": false}.
--
-- MySQL has no `ADD COLUMN IF NOT EXISTS`, and this file re-runs on every
-- container start, so a bare ALTER would fail the whole schema on the second
-- boot. Guarded on information_schema and executed dynamically: on an existing
-- database the statement becomes `DO 0`, a no-op.
SET @add_features := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE plans ADD COLUMN features JSON DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plans' AND COLUMN_NAME = 'features'
);
PREPARE stmt_add_features FROM @add_features;
EXECUTE stmt_add_features;
DEALLOCATE PREPARE stmt_add_features;

-- Two more metered limits, guarded the same way. Both default NULL = unlimited,
-- which is what makes adding them a no-op for an existing deployment: a tenant
-- cannot lose capacity they already had just because the column now exists. The
-- admin sets real numbers in the plan editor.
--
-- max_chatbot_replies is the important one. An AI reply costs money per token,
-- and until now it was bounded only by the shared max_messages_per_month, so
-- there was no way to sell "the chatbot" separately from "messages".
SET @add_reply_limit := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE plans ADD COLUMN max_chatbot_replies INT DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plans' AND COLUMN_NAME = 'max_chatbot_replies'
);
PREPARE stmt_add_reply_limit FROM @add_reply_limit;
EXECUTE stmt_add_reply_limit;
DEALLOCATE PREPARE stmt_add_reply_limit;

SET @add_service_limit := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE plans ADD COLUMN max_services INT DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plans' AND COLUMN_NAME = 'max_services'
);
PREPARE stmt_add_service_limit FROM @add_service_limit;
EXECUTE stmt_add_service_limit;
DEALLOCATE PREPARE stmt_add_service_limit;

-- ---------------------------------------------------------------------------
-- Tenants (one user == one tenant)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    activation_token VARCHAR(64) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    plan_id INT DEFAULT NULL,
    trial_ends_at DATETIME DEFAULT NULL,
    reset_token VARCHAR(64) DEFAULT NULL,
    reset_expires DATETIME DEFAULT NULL,
    last_login_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- A plan must never be deletable out from under a live tenant.
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL,
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- A tenant created by an admin with a temporary password (issue #48) must
-- replace it before they can do anything else. Enforced in requireLogin(), so
-- it holds on every authenticated request rather than only on the login page:
-- a temporary password that an admin read out over the phone is a shared
-- secret, and it stops being one at first use.
--
-- Guarded like the plan columns above, and DEFAULT 0, so every account that
-- already exists is unaffected.
SET @add_must_change := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_change_password'
);
PREPARE stmt_add_must_change FROM @add_must_change;
EXECUTE stmt_add_must_change;
DEALLOCATE PREPARE stmt_add_must_change;

CREATE TABLE IF NOT EXISTS subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan_id INT NOT NULL,
    status ENUM('trialing','active','past_due','canceled') NOT NULL DEFAULT 'active',
    current_period_start DATETIME DEFAULT NULL,
    current_period_end DATETIME DEFAULT NULL,
    cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
    external_ref VARCHAR(120) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id),
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB;

-- Manually recorded payments. There is deliberately NO payment gateway: tenants
-- pay out of band and an admin records it here.
--
-- `amount_minor` is minor units, like plans.price_cents (which is misnamed —
-- it holds paisa for PKR, not cents). The currency is stored per payment
-- because it records what was actually received; a later change to the instance
-- currency must not rewrite history.
CREATE TABLE IF NOT EXISTS payments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    -- Which plan the payment was for. SET NULL rather than CASCADE: deleting a
    -- plan must never erase the record of money received.
    plan_id INT DEFAULT NULL,
    amount_minor BIGINT NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PKR',
    period_start DATE DEFAULT NULL,
    period_end DATE DEFAULT NULL,
    method VARCHAR(40) DEFAULT NULL,
    reference VARCHAR(120) DEFAULT NULL,
    note VARCHAR(500) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_time (user_id, created_at),
    INDEX idx_period (period_end),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_setting (user_id, setting_key)
) ENGINE=InnoDB;

-- Optional tenant identity/contact detail.
--
-- A separate table rather than more columns on `users`, because `users` is on
-- the hot path: getCurrentUser() reads it on every authenticated request via
-- requireLogin(). These fields are sparse and read on three pages, so widening
-- that row every request buys nothing, and it keeps authentication columns apart
-- from user-supplied content.
--
-- user_id is the primary key, which makes the 1:1 relationship structural — a
-- tenant cannot end up with two profiles. Every field is nullable: registration
-- stays a two-field form, so nothing here may ever be required.
CREATE TABLE IF NOT EXISTS user_profiles (
    user_id INT NOT NULL PRIMARY KEY,
    company_name VARCHAR(150) DEFAULT NULL,
    -- E.164 digits, no '+' — matches how wa_accounts.phone_number stores them.
    whatsapp_number VARCHAR(20) DEFAULT NULL,
    address_line1 VARCHAR(200) DEFAULT NULL,
    address_line2 VARCHAR(200) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    state_region VARCHAR(100) DEFAULT NULL,
    postal_code VARCHAR(20) DEFAULT NULL,
    -- ISO 3166-1 alpha-2, validated against includes/countries.php.
    country CHAR(2) DEFAULT NULL,
    contact_email TINYINT(1) NOT NULL DEFAULT 1,
    contact_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Instance-wide settings: the platform owner's, not a tenant's. Defined after
-- `users` because updated_by references it.
--
-- Deliberately schemaless key/value: the admin settings pages accrete knobs
-- (currency, timezone, SMTP, payment instructions) and each one should not cost
-- a migration. Defaults live in includes/settings.php, so an absent row still
-- resolves and the seed cannot drift from the code.
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value TEXT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Public registration is gone (issue #48), and this row is how it used to be
-- turned back on.
--
-- Deleted rather than left inert, and deleted on every schema apply rather than
-- once: restoring a database dump taken while sign-up was open would otherwise
-- carry the row back in. Nothing reads the key any more — allowRegistration()
-- and the ALLOW_REGISTRATION constant were both removed — so this is belt and
-- braces against a setting that must never be resurrected by configuration
-- drift.
DELETE FROM app_settings WHERE setting_key = 'allow_registration';

-- White-label logo and favicon bytes (issue #23).
--
-- In the database rather than on disk because the frontend container has no
-- writable volume: a file written under the document root is lost on the next
-- image build, and every deploy here builds from source. The database survives a
-- release and is dumped before each one. Only two rows are ever expected, keyed
-- by what the image is for, so replacing a logo cannot accumulate orphans.
--
-- MEDIUMBLOB rather than BLOB for headroom; includes/branding.php caps an upload
-- at 512 KB, which is the limit that actually applies.
CREATE TABLE IF NOT EXISTS brand_assets (
    kind VARCHAR(16) NOT NULL PRIMARY KEY,      -- logo | favicon
    mime_type VARCHAR(64) NOT NULL,
    content MEDIUMBLOB NOT NULL,
    byte_size INT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Feature flags for the seeded plans.
--
-- Two problems are fixed here. First, `features` was never seeded at all, so a
-- fresh install had NULL on every plan and `planHasFeature()` reads NULL as "no
-- features" — meaning nothing, not even Pro, could use the chatbot until an
-- admin found the checkboxes. Second, `media_send` and `csv_export` were
-- editable in the plan editor but enforced nowhere, so their stored values
-- cannot have been deliberate: Pro shipped with csv_export=false while every Pro
-- tenant could still export. Enforcing them without correcting the data would
-- have removed working features from paying tenants.
--
-- Both must apply exactly once. This file re-runs on every container start, so a
-- bare UPDATE would re-enable a flag an admin had since deliberately turned off
-- — the same trap the plan seed above documents. The marker row is the guard and
-- is written last, so an interrupted run retries rather than half-applies.

-- A row that has never had a document gets the full intended ladder. Restricted
-- to NULL so an admin's existing choices are never overwritten.
UPDATE plans SET features = '{"chatbot": false, "llm_byok": false, "media_send": false, "csv_export": false,
                              "appointments": false, "handoff": false, "voice_transcription": false}'
 WHERE code = 'free'    AND features IS NULL;
UPDATE plans SET features = '{"chatbot": false, "llm_byok": false, "media_send": true,  "csv_export": true,
                              "appointments": false, "handoff": false, "voice_transcription": false}'
 WHERE code = 'starter' AND features IS NULL;
UPDATE plans SET features = '{"chatbot": true,  "llm_byok": true,  "media_send": true,  "csv_export": true,
                              "appointments": true,  "handoff": true,  "voice_transcription": true}'
 WHERE code = 'pro'     AND features IS NULL;

-- Deployments that already have a document keep every deliberate choice; only
-- the two flags that could not have been deliberate are corrected. JSON_SET
-- creates the key when absent and overwrites it when present.
UPDATE plans
   SET features = JSON_SET(COALESCE(features, JSON_OBJECT()),
                           '$.media_send', TRUE,
                           '$.csv_export', TRUE)
 WHERE code IN ('starter', 'pro')
   AND NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'migrated_plan_flags_v1');

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('migrated_plan_flags_v1', '1');

-- appointments / handoff / voice_transcription become plan levers of their own.
--
-- Each is seeded from the plan's existing `chatbot` value, which is exactly
-- behaviour-preserving: all three are chatbot sub-features and, before this,
-- every one of them was reachable precisely when `chatbot` was on. Booking was
-- gated by appointments.php's `chatbot` check; handoff and transcription are only
-- reachable from the reply path, which the same flag guards. Defaulting them to
-- false instead would have taken working features away from the top tier — which
-- is the mistake media_send/csv_export nearly caused above.
--
-- Marker-guarded and JSON_SET-if-absent, so a plan an admin has since edited
-- keeps that choice on the next boot.
UPDATE plans
   SET features = JSON_SET(COALESCE(features, JSON_OBJECT()),
                           '$.appointments',        COALESCE(JSON_EXTRACT(features, '$.chatbot'), CAST('false' AS JSON)),
                           '$.handoff',             COALESCE(JSON_EXTRACT(features, '$.chatbot'), CAST('false' AS JSON)),
                           '$.voice_transcription', COALESCE(JSON_EXTRACT(features, '$.chatbot'), CAST('false' AS JSON)))
 WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'migrated_plan_flags_v2');

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('migrated_plan_flags_v2', '1');

-- Metered usage, bucketed per calendar month so quotas can reset.
CREATE TABLE IF NOT EXISTS usage_counters (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    period_ym CHAR(7) NOT NULL,          -- 'YYYY-MM'
    metric VARCHAR(40) NOT NULL,         -- e.g. 'messages_sent'
    value BIGINT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_usage (user_id, period_ym, metric)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(60) NOT NULL,
    entity VARCHAR(60) DEFAULT NULL,
    entity_id VARCHAR(64) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    meta JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Audit rows outlive the account they describe.
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_time (user_id, created_at),
    INDEX idx_action_time (action, created_at)
) ENGINE=InnoDB;

-- Backs login throttling. Keyed by email AND ip so neither a single account nor
-- a single source address can be hammered.
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_time (email, created_at),
    INDEX idx_ip_time (ip_address, created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- WhatsApp data (all tenant-scoped through wa_accounts.user_id)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS wa_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_id VARCHAR(36) NOT NULL UNIQUE,
    label VARCHAR(100) DEFAULT NULL,
    status ENUM('qr_required','connected','disconnected','reconnecting','logged_out','failed') DEFAULT 'qr_required',
    phone_number VARCHAR(20) DEFAULT NULL,
    push_name VARCHAR(100) DEFAULT NULL,
    connected_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wa_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    session_id VARCHAR(36) NOT NULL,
    chat_id VARCHAR(100) NOT NULL,
    contact_name VARCHAR(200) DEFAULT NULL,
    phone_number VARCHAR(20) DEFAULT NULL,
    last_message TEXT DEFAULT NULL,
    last_message_time DATETIME DEFAULT NULL,
    is_group TINYINT(1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES wa_accounts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_contact (session_id, chat_id),
    -- Reads are scoped by account_id now, so that is the index that matters.
    INDEX idx_account_time (account_id, last_message_time DESC)
) ENGINE=InnoDB;

-- Archived chats are isolated in the UI the way WhatsApp Web does it, so the
-- flag has to be queryable rather than derived. Added with the same
-- information_schema guard as plans.features: no ADD COLUMN IF NOT EXISTS, and
-- this file re-runs on every container start.
SET @add_archived := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE wa_contacts ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_contacts' AND COLUMN_NAME = 'is_archived'
);
PREPARE stmt_add_archived FROM @add_archived;
EXECUTE stmt_add_archived;
DEALLOCATE PREPARE stmt_add_archived;

-- The main list excludes archived chats and both views sort on recency, so the
-- existing (account_id, last_message_time) index no longer covers the query.
SET @add_arch_idx := (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_account_archived_time ON wa_contacts (account_id, is_archived, last_message_time DESC)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_contacts' AND INDEX_NAME = 'idx_account_archived_time'
);
PREPARE stmt_add_arch_idx FROM @add_arch_idx;
EXECUTE stmt_add_arch_idx;
DEALLOCATE PREPARE stmt_add_arch_idx;

CREATE TABLE IF NOT EXISTS wa_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    session_id VARCHAR(36) NOT NULL,
    message_id VARCHAR(100) NOT NULL,
    chat_id VARCHAR(100) NOT NULL,
    sender_name VARCHAR(200) DEFAULT NULL,
    from_me TINYINT(1) DEFAULT 0,
    message_text TEXT DEFAULT NULL,
    media_type VARCHAR(20) DEFAULT 'text',
    media_mime VARCHAR(100) DEFAULT NULL,
    media_filename VARCHAR(255) DEFAULT NULL,
    message_timestamp DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES wa_accounts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_msg (session_id, message_id),
    INDEX idx_account_chat (account_id, chat_id, message_timestamp),
    INDEX idx_account_time (account_id, message_timestamp)
) ENGINE=InnoDB;

-- The admin overview's 60-day outbound-activity series scans by timestamp
-- alone, which the per-account indexes above do not cover.
SET @add_message_time_idx := (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_message_time ON wa_messages (message_timestamp)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_messages' AND INDEX_NAME = 'idx_message_time'
);
PREPARE stmt_add_message_time_idx FROM @add_message_time_idx;
EXECUTE stmt_add_message_time_idx;
DEALLOCATE PREPARE stmt_add_message_time_idx;

-- Who actually spoke, inside a group. key.remoteJid is the group; the sender is
-- key.participant. Stored alongside the resolved name so the name can be
-- re-resolved later: a participant's contact entry usually arrives after their
-- messages do.
SET @add_sender_jid := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE wa_messages ADD COLUMN sender_jid VARCHAR(100) DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_messages' AND COLUMN_NAME = 'sender_jid'
);
PREPARE stmt_add_sender_jid FROM @add_sender_jid;
EXECUTE stmt_add_sender_jid;
DEALLOCATE PREPARE stmt_add_sender_jid;

-- The shared-contact and location payloads (#17). Everything else a message
-- needs to render was already stored, but these two were only ever read from
-- the backend's live response and re-attached on the fly. That worked only
-- because every poll re-fetched the entire thread — the thing #17 exists to
-- stop. Without them stored, a contact or location message older than the
-- incremental watermark would fall through to the "unsupported media"
-- placeholder.
--
-- JSON rather than two sets of typed columns: a vCard list is variable-length,
-- and neither payload is ever queried by value — it is written once and read
-- back whole.
SET @add_media_meta := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE wa_messages ADD COLUMN media_meta JSON DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_messages' AND COLUMN_NAME = 'media_meta'
);
PREPARE stmt_add_media_meta FROM @add_media_meta;
EXECUTE stmt_add_media_meta;
DEALLOCATE PREPARE stmt_add_media_meta;

-- Meta WhatsApp Cloud API as a second connection type (Phase 27).
--
-- `provider` is the isolation guarantee: it defaults to 'baileys', so every
-- existing row and every existing query behaves exactly as before, and only an
-- account a tenant explicitly creates through connect-cloud.php is 'cloud'.
-- The cloud_* columns hold the per-tenant Meta app credentials; the token and
-- app secret are stored encrypted (encryptSecret(…, 'wa-cloud-v1')) and are
-- never returned to any page.
SET @add_provider := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE wa_accounts ADD COLUMN provider VARCHAR(16) NOT NULL DEFAULT ''baileys''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_accounts' AND COLUMN_NAME = 'provider'
);
PREPARE stmt_add_provider FROM @add_provider;
EXECUTE stmt_add_provider;
DEALLOCATE PREPARE stmt_add_provider;

SET @add_cloud_pnid := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE wa_accounts ADD COLUMN cloud_phone_number_id VARCHAR(32) DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_accounts' AND COLUMN_NAME = 'cloud_phone_number_id'
);
PREPARE stmt_add_cloud_pnid FROM @add_cloud_pnid;
EXECUTE stmt_add_cloud_pnid;
DEALLOCATE PREPARE stmt_add_cloud_pnid;

-- One Meta phone number id can only be wired to one account; Meta delivers to
-- whichever webhook the app points at, so two rows claiming the same id would
-- silently split the traffic.
SET @add_cloud_pnid_idx := (
    SELECT IF(COUNT(*) = 0,
        'CREATE UNIQUE INDEX uniq_wa_cloud_pnid ON wa_accounts (cloud_phone_number_id)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_accounts' AND INDEX_NAME = 'uniq_wa_cloud_pnid'
);
PREPARE stmt_add_cloud_pnid_idx FROM @add_cloud_pnid_idx;
EXECUTE stmt_add_cloud_pnid_idx;
DEALLOCATE PREPARE stmt_add_cloud_pnid_idx;

SET @add_cloud_waba := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE wa_accounts ADD COLUMN cloud_waba_id VARCHAR(32) DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_accounts' AND COLUMN_NAME = 'cloud_waba_id'
);
PREPARE stmt_add_cloud_waba FROM @add_cloud_waba;
EXECUTE stmt_add_cloud_waba;
DEALLOCATE PREPARE stmt_add_cloud_waba;

SET @add_cloud_token := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE wa_accounts ADD COLUMN cloud_access_token_encrypted TEXT DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_accounts' AND COLUMN_NAME = 'cloud_access_token_encrypted'
);
PREPARE stmt_add_cloud_token FROM @add_cloud_token;
EXECUTE stmt_add_cloud_token;
DEALLOCATE PREPARE stmt_add_cloud_token;

SET @add_cloud_secret := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE wa_accounts ADD COLUMN cloud_app_secret_encrypted TEXT DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_accounts' AND COLUMN_NAME = 'cloud_app_secret_encrypted'
);
PREPARE stmt_add_cloud_secret FROM @add_cloud_secret;
EXECUTE stmt_add_cloud_secret;
DEALLOCATE PREPARE stmt_add_cloud_secret;

-- The per-account webhook key is what makes webhooks/meta.php unguessable; it
-- goes in the callback URL, so it must be unique across accounts.
SET @add_cloud_key := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE wa_accounts ADD COLUMN cloud_webhook_key VARCHAR(64) DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_accounts' AND COLUMN_NAME = 'cloud_webhook_key'
);
PREPARE stmt_add_cloud_key FROM @add_cloud_key;
EXECUTE stmt_add_cloud_key;
DEALLOCATE PREPARE stmt_add_cloud_key;

SET @add_cloud_key_idx := (
    SELECT IF(COUNT(*) = 0,
        'CREATE UNIQUE INDEX uniq_wa_cloud_webhook_key ON wa_accounts (cloud_webhook_key)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_accounts' AND INDEX_NAME = 'uniq_wa_cloud_webhook_key'
);
PREPARE stmt_add_cloud_key_idx FROM @add_cloud_key_idx;
EXECUTE stmt_add_cloud_key_idx;
DEALLOCATE PREPARE stmt_add_cloud_key_idx;

SET @add_cloud_verify := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE wa_accounts ADD COLUMN cloud_verify_token VARCHAR(64) DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_accounts' AND COLUMN_NAME = 'cloud_verify_token'
);
PREPARE stmt_add_cloud_verify FROM @add_cloud_verify;
EXECUTE stmt_add_cloud_verify;
DEALLOCATE PREPARE stmt_add_cloud_verify;

SET @add_cloud_err := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE wa_accounts ADD COLUMN cloud_last_error VARCHAR(255) DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_accounts' AND COLUMN_NAME = 'cloud_last_error'
);
PREPARE stmt_add_cloud_err FROM @add_cloud_err;
EXECUTE stmt_add_cloud_err;
DEALLOCATE PREPARE stmt_add_cloud_err;

-- ---------------------------------------------------------------------------
-- LLM chatbot (issues #9, #14, #16, #22)
-- ---------------------------------------------------------------------------

-- A provider is a vendor account the platform owner pays for. The API key is
-- encrypted with the same libsodium secretbox used for the SMTP password
-- (includes/crypto.php) and is never returned to any browser, admin or tenant.
CREATE TABLE IF NOT EXISTS llm_providers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,           -- openai | anthropic | google
    label VARCHAR(80) NOT NULL,
    api_key_encrypted TEXT DEFAULT NULL,
    base_url VARCHAR(255) DEFAULT NULL,  -- override for gateways/proxies
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_tested_at DATETIME DEFAULT NULL,
    last_test_ok TINYINT(1) DEFAULT NULL,
    last_test_error VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_provider (code)
) ENGINE=InnoDB;

-- Models are listed explicitly rather than fetched: the admin decides what
-- tenants may spend money on, and a vendor adding an expensive model must never
-- silently become selectable.
CREATE TABLE IF NOT EXISTS llm_models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider_id INT NOT NULL,
    model_code VARCHAR(100) NOT NULL,    -- as the vendor's API expects it
    label VARCHAR(100) NOT NULL,
    kind ENUM('chat','transcribe') NOT NULL DEFAULT 'chat',
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (provider_id) REFERENCES llm_providers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_model (provider_id, model_code),
    INDEX idx_kind_enabled (kind, is_enabled)
) ENGINE=InnoDB;

-- Which plans may use which model. Absence of a row means no access, so a new
-- model is unavailable until the admin grants it — never the other way round.
CREATE TABLE IF NOT EXISTS plan_llm_models (
    plan_id INT NOT NULL,
    model_id INT NOT NULL,
    PRIMARY KEY (plan_id, model_id),
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE,
    FOREIGN KEY (model_id) REFERENCES llm_models(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One chatbot configuration per tenant.
--
-- reply_to_groups / reply_to_archived exist as columns but default to 0 and the
-- UI does not offer them yet (#22): the bot must never speak in a group or
-- revive an archived chat. They are columns so the future opt-in described in
-- #22 does not need a migration.
CREATE TABLE IF NOT EXISTS chatbot_configs (
    user_id INT NOT NULL PRIMARY KEY,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    model_id INT DEFAULT NULL,
    byo_provider_code VARCHAR(32) DEFAULT NULL,
    byo_model_code VARCHAR(100) DEFAULT NULL,
    byo_api_key_encrypted TEXT DEFAULT NULL,
    knowledge_base MEDIUMTEXT DEFAULT NULL,
    greeting TEXT DEFAULT NULL,
    fallback_message TEXT DEFAULT NULL,
    tone VARCHAR(32) NOT NULL DEFAULT 'professional',
    max_tokens INT NOT NULL DEFAULT 400,
    history_messages INT NOT NULL DEFAULT 10,
    reply_to_groups TINYINT(1) NOT NULL DEFAULT 0,
    reply_to_archived TINYINT(1) NOT NULL DEFAULT 0,
    active_hours_start CHAR(5) DEFAULT NULL,   -- 'HH:MM' in the tenant timezone
    active_hours_end CHAR(5) DEFAULT NULL,
    outside_hours_message TEXT DEFAULT NULL,
    transcribe_audio TINYINT(1) NOT NULL DEFAULT 0,
    voice_language VARCHAR(8) NOT NULL DEFAULT 'auto',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (model_id) REFERENCES llm_models(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Per-reply record. Deliberately metadata only: no message text and no model
-- output is stored, because the admin console reads this table and admins must
-- never see message content. `outcome` is what makes a silent bot debuggable.
CREATE TABLE IF NOT EXISTS chatbot_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_id INT DEFAULT NULL,
    chat_key CHAR(64) DEFAULT NULL,      -- sha256(chat jid): correlate without storing who
    outcome VARCHAR(32) NOT NULL,        -- replied | skipped_group | skipped_archived | quota | error | ...
    detail VARCHAR(255) DEFAULT NULL,
    model_id INT DEFAULT NULL,
    prompt_tokens INT DEFAULT NULL,
    completion_tokens INT DEFAULT NULL,
    latency_ms INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (model_id) REFERENCES llm_models(id) ON DELETE SET NULL,
    INDEX idx_user_time (user_id, created_at),
    INDEX idx_outcome_time (outcome, created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Appointment booking over WhatsApp (issue #14)
-- ---------------------------------------------------------------------------

-- What a tenant offers: a name and a description. How long it takes is not the
-- service's business — every appointment is one slot, and the slot length is
-- `chatbot_configs.appointment_slot_minutes`.
--
-- `duration_minutes` is no longer read or written. It is kept, and kept
-- NOT NULL DEFAULT 30 so an INSERT that omits it is valid, for the same reason
-- `chatbot_configs.greeting` is kept: a future per-service length then needs no
-- migration. Two numbers describing one appointment could disagree, and did.
CREATE TABLE IF NOT EXISTS appointment_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    duration_minutes INT NOT NULL DEFAULT 30,   -- vestigial; see above
    description VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_active (user_id, is_active)
) ENGINE=InnoDB;

-- When the tenant is open, in their own timezone. weekday follows PHP's 'w':
-- 0 = Sunday … 6 = Saturday.
CREATE TABLE IF NOT EXISTS appointment_availability (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    weekday TINYINT NOT NULL,
    start_time CHAR(5) NOT NULL,
    end_time CHAR(5) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_day (user_id, weekday)
) ENGINE=InnoDB;

-- scheduled_at is UTC, like every other timestamp in this schema. It is
-- rendered in the tenant's timezone and was spoken about in the customer's
-- words, but it is stored as an instant so a timezone change cannot move
-- somebody's booking.
CREATE TABLE IF NOT EXISTS appointments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_id INT DEFAULT NULL,
    service_id INT DEFAULT NULL,
    service_name VARCHAR(100) NOT NULL,      -- copied: renaming a service must not rewrite history
    duration_minutes INT NOT NULL DEFAULT 30,   -- copied too: how long *this* booking is
    customer_phone VARCHAR(32) DEFAULT NULL,
    customer_name VARCHAR(120) DEFAULT NULL,
    chat_id VARCHAR(100) DEFAULT NULL,
    scheduled_at DATETIME NOT NULL,
    status ENUM('booked','completed','cancelled','no_show') NOT NULL DEFAULT 'booked',
    notes VARCHAR(500) DEFAULT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'chatbot',   -- chatbot | manual
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES wa_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (service_id) REFERENCES appointment_services(id) ON DELETE SET NULL,
    INDEX idx_user_time (user_id, scheduled_at),
    INDEX idx_user_status (user_id, status, scheduled_at),
    INDEX idx_chat (user_id, chat_id, status)
) ENGINE=InnoDB;

-- One row per reminder per appointment. The unique key is what makes the sender
-- idempotent: a scheduler that runs twice cannot message the customer twice.
--
-- `sending` is a state, not a decoration (#39). The sender used to move a row
-- straight to `sent` before calling WhatsApp, so a process killed mid-send left
-- a row claiming it had delivered a message that never went out — the one way
-- this could lose a reminder in total silence. The claim now parks the row in
-- `sending` with claimed_at, and only success writes `sent`.
--
-- `attempts` and `retry_after` bound the retry: a transient WhatsApp or database
-- failure is worth another go a minute later, the same failure fifty times in a
-- row is not, and neither is any attempt at all once the appointment has been
-- and gone.
CREATE TABLE IF NOT EXISTS appointment_reminders (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    appointment_id BIGINT NOT NULL,
    minutes_before INT NOT NULL,
    send_at DATETIME NOT NULL,
    sent_at DATETIME DEFAULT NULL,
    claimed_at DATETIME DEFAULT NULL,
    attempts INT NOT NULL DEFAULT 0,
    retry_after DATETIME DEFAULT NULL,
    status ENUM('pending','sending','sent','failed','skipped','missed') NOT NULL DEFAULT 'pending',
    detail VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    UNIQUE KEY unique_reminder (appointment_id, minutes_before),
    INDEX idx_due (status, send_at)
) ENGINE=InnoDB;

-- The same three columns for a database that predates them, guarded on
-- information_schema like every other migration in this file: MySQL has no
-- ADD COLUMN IF NOT EXISTS and this file re-runs on every container start.
SET @add_reminder_retry := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE appointment_reminders
            ADD COLUMN claimed_at DATETIME DEFAULT NULL,
            ADD COLUMN attempts INT NOT NULL DEFAULT 0,
            ADD COLUMN retry_after DATETIME DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointment_reminders' AND COLUMN_NAME = 'attempts'
);
PREPARE stmt_add_reminder_retry FROM @add_reminder_retry;
EXECUTE stmt_add_reminder_retry;
DEALLOCATE PREPARE stmt_add_reminder_retry;

-- Widening the ENUM is additive: no existing value changes meaning, so rows
-- written by the old sender keep reading exactly as they did. Guarded on the
-- column type rather than the column name, since the column has always existed.
SET @add_reminder_states := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE appointment_reminders
            MODIFY COLUMN status ENUM(''pending'',''sending'',''sent'',''failed'',''skipped'',''missed'')
            NOT NULL DEFAULT ''pending''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointment_reminders'
      AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE '%sending%'
);
PREPARE stmt_add_reminder_states FROM @add_reminder_states;
EXECUTE stmt_add_reminder_states;
DEALLOCATE PREPARE stmt_add_reminder_states;

-- The due query filters on status and orders by retry_after/send_at; the old
-- (status, send_at) index no longer covers it.
SET @add_retry_idx := (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_due_retry ON appointment_reminders (status, retry_after, send_at)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointment_reminders' AND INDEX_NAME = 'idx_due_retry'
);
PREPARE stmt_add_retry_idx FROM @add_retry_idx;
EXECUTE stmt_add_retry_idx;
DEALLOCATE PREPARE stmt_add_retry_idx;

-- One row per customer-visible change to an appointment (#45).
--
-- A tenant who cancels or moves a booking from the dashboard owes the customer
-- a message, and the only safe way to send one is to write down that it is
-- owed. `fingerprint` is what the change *was* — the cancelled instant, or the
-- pair of instants a move went between — so the unique key is the whole of the
-- de-duplication: a form submitted twice, a retried POST and a page refresh all
-- describe the same change and therefore the same row, and only the first of
-- them can claim it.
--
-- The state machine is deliberately the reminders' one, for the same reasons:
-- `sending` is a claim with an owner and a timestamp so a killed process cannot
-- leave a row claiming to have delivered a message that never went out, `failed`
-- is retryable and visible to the tenant, and `skipped` is the honest record of
-- an appointment with nobody to tell (a manual booking with no linked account).
--
-- `body` is stored rather than rebuilt: a retry must send what the customer was
-- always going to be told, not a sentence regenerated against a calendar that
-- has moved on since.
--
-- `channel` distinguishes a message the dashboard sent from one the chatbot had
-- already said in its own reply. The chatbot writes its row as `sent`, which is
-- what stops the dashboard telling the same customer the same thing twice.
CREATE TABLE IF NOT EXISTS appointment_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    appointment_id BIGINT NOT NULL,
    user_id INT NOT NULL,
    kind VARCHAR(20) NOT NULL,
    fingerprint VARCHAR(120) NOT NULL,
    body TEXT NOT NULL,
    channel VARCHAR(20) NOT NULL DEFAULT 'dashboard',
    status ENUM('sending','sent','failed','skipped') NOT NULL DEFAULT 'sending',
    attempts INT NOT NULL DEFAULT 0,
    detail VARCHAR(255) DEFAULT NULL,
    claimed_at DATETIME DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_notice (appointment_id, fingerprint),
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB;

-- Appointment settings ride on chatbot_configs: the booking flow is the chatbot.
SET @add_appt := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE chatbot_configs
            ADD COLUMN appointments_enabled TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN appointment_lead_minutes INT NOT NULL DEFAULT 60,
            ADD COLUMN appointment_horizon_days INT NOT NULL DEFAULT 30,
            ADD COLUMN reminder_minutes VARCHAR(64) DEFAULT ''1440,60'',
            ADD COLUMN booking_confirmation TEXT DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatbot_configs' AND COLUMN_NAME = 'appointments_enabled'
);
PREPARE stmt_add_appt FROM @add_appt;
EXECUTE stmt_add_appt;
DEALLOCATE PREPARE stmt_add_appt;

-- The size of one block in the diary. Offered start times step by this, from
-- each opening time, so a 30-minute grid offers 09:00, 09:30, 10:00 and nothing
-- between them — appointments sit against each other instead of being spread
-- over a finer grid than the business actually works to.
--
-- Its own guard rather than a line in the block above: that one only fires on a
-- database that predates appointments entirely, so a tenant already using
-- booking would never have got this column.
SET @add_slot := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE chatbot_configs ADD COLUMN appointment_slot_minutes INT NOT NULL DEFAULT 30',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatbot_configs' AND COLUMN_NAME = 'appointment_slot_minutes'
);
PREPARE stmt_add_slot FROM @add_slot;
EXECUTE stmt_add_slot;
DEALLOCATE PREPARE stmt_add_slot;

-- ---------------------------------------------------------------------------
-- Reply delay (issue #51)
-- ---------------------------------------------------------------------------

-- How long the bot waits before answering. Instant, identical replies are the
-- pattern WhatsApp's anti-spam flags, so the wait is configurable per tenant
-- (the dropdown offers a fixed list, normalised in chatbotNormaliseReplyDelay).
-- Five minutes is the safe default for a column added to tenants who never
-- asked for one.
SET @add_delay := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE chatbot_configs ADD COLUMN reply_delay_seconds INT NOT NULL DEFAULT 300',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatbot_configs' AND COLUMN_NAME = 'reply_delay_seconds'
);
PREPARE stmt_add_delay FROM @add_delay;
EXECUTE stmt_add_delay;
DEALLOCATE PREPARE stmt_add_delay;

-- Which language the tenant's customers speak, used to script-lock the
-- transcription prompt: Urdu and Hindi sound the same when spoken, and the
-- model picks a script for the transcript (and then for the reply) — wrong
-- script means Urdu speakers are answered in Hindi. 'auto' resolves per
-- customer: their phone's country code, then the tenant's timezone.
SET @add_voice_lang := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE chatbot_configs ADD COLUMN voice_language VARCHAR(8) NOT NULL DEFAULT ''auto''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatbot_configs' AND COLUMN_NAME = 'voice_language'
);
PREPARE stmt_add_voice_lang FROM @add_voice_lang;
EXECUTE stmt_add_voice_lang;
DEALLOCATE PREPARE stmt_add_voice_lang;

-- Replies parked until their delay elapses. One row per chat — the unique key
-- is the coalescing rule: a burst of messages from the same customer overwrites
-- the payload and pushes due_at forward, so only the newest message is answered
-- and the wait restarts with each new message. `claimed_at` is the lock that
-- keeps an in-request wait and the reminder tick from both answering; a claim
-- older than five minutes is treated as abandoned and reclaimed.
CREATE TABLE IF NOT EXISTS chatbot_pending_replies (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    chat_key CHAR(64) NOT NULL,            -- sha256(chat jid), same as chatbot_events.chat_key (chatbotChatKey())
    payload JSON NOT NULL,                 -- the exact $msg array chatbotHandleInbound() received, latest message wins
    due_at DATETIME NOT NULL,              -- UTC
    claimed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chat (user_id, chat_key),
    INDEX idx_due (due_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Calendar cards (issue #47)
-- ---------------------------------------------------------------------------

-- iCalendar SEQUENCE: bumped on every reschedule, cancel and reinstate so the
-- card a calendar app receives for a changed booking updates the event it
-- already has instead of duplicating it.
SET @add_ics_seq := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE appointments ADD COLUMN ics_sequence INT NOT NULL DEFAULT 0',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'ics_sequence'
);
PREPARE stmt_add_ics_seq FROM @add_ics_seq;
EXECUTE stmt_add_ics_seq;
DEALLOCATE PREPARE stmt_add_ics_seq;

-- ---------------------------------------------------------------------------
-- Human handoff (issue #16)
-- ---------------------------------------------------------------------------

-- One row per conversation that has left the bot. The chat id is stored in the
-- clear here, unlike chatbot_events: this is the tenant's own working queue, not
-- something the admin console reads.
CREATE TABLE IF NOT EXISTS chat_handoffs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_id INT DEFAULT NULL,
    chat_id VARCHAR(100) NOT NULL,
    customer_name VARCHAR(200) DEFAULT NULL,
    customer_phone VARCHAR(32) DEFAULT NULL,
    status ENUM('waiting','claimed','resolved','abandoned') NOT NULL DEFAULT 'waiting',
    reason VARCHAR(120) DEFAULT NULL,          -- what triggered it
    topic VARCHAR(255) DEFAULT NULL,           -- the message that asked for a human
    requested_at DATETIME NOT NULL,
    claimed_at DATETIME DEFAULT NULL,
    claimed_by INT DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    last_customer_at DATETIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES wa_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (claimed_by) REFERENCES users(id) ON DELETE SET NULL,
    -- One live handoff per chat. The partial-index trick MySQL lacks is done in
    -- code instead: handoffOpenForChat() is the single writer.
    INDEX idx_user_status (user_id, status, requested_at),
    INDEX idx_chat (user_id, chat_id, status)
) ENGINE=InnoDB;

-- Handoff settings ride on chatbot_configs, like appointments: it is the same
-- conversation, just no longer with the bot.
SET @add_handoff := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE chatbot_configs
            ADD COLUMN handoff_enabled TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN handoff_phrases VARCHAR(500) DEFAULT ''agent,human,representative,talk to someone,speak to a person'',
            ADD COLUMN handoff_ack_message TEXT DEFAULT NULL,
            ADD COLUMN handoff_resume_message TEXT DEFAULT NULL,
            ADD COLUMN handoff_notify_number VARCHAR(32) DEFAULT NULL,
            ADD COLUMN handoff_notify_email VARCHAR(255) DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatbot_configs' AND COLUMN_NAME = 'handoff_enabled'
);
PREPARE stmt_add_handoff FROM @add_handoff;
EXECUTE stmt_add_handoff;
DEALLOCATE PREPARE stmt_add_handoff;

-- Sharing the notification number with the customer (issue #26).
--
-- Two columns and both default to off. The notification number is staff contact
-- detail: it is only ever put in front of a customer when the tenant has
-- explicitly said so, which is what handoff_share_number is. The wording is
-- theirs too, because "call us" reads very differently for a clinic and a
-- takeaway.
SET @add_share := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE chatbot_configs
            ADD COLUMN handoff_share_number TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN handoff_share_message VARCHAR(500) DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatbot_configs' AND COLUMN_NAME = 'handoff_share_number'
);
PREPARE stmt_add_share FROM @add_share;
EXECUTE stmt_add_share;
DEALLOCATE PREPARE stmt_add_share;

-- ---------------------------------------------------------------------------
-- Lead generation (issue #50)
-- ---------------------------------------------------------------------------
--
-- The platform owner's own prospecting tool: find local businesses through
-- SerpApi's Google Maps engine, keep what came back, and call them.
--
-- Admin-only, and deliberately NOT tenant-scoped. There is no user_id on
-- `leads`: these are the owner's prospects, not a tenant's contacts, and a
-- tenant must never see them. Every table here is reached only from /admin.
--
-- This is not a CRM. There is no pipeline, no owner, no stage and no notes —
-- only "we have seen this business, here is how to contact it, and when it
-- first and last appeared in a search". Anything more is a product decision
-- nobody has made yet, and columns are much easier to add than to remove.

-- The searches worth keeping around, so the owner is not retyping
-- "hair transplant clinic" every morning.
CREATE TABLE IF NOT EXISTS lead_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(100) NOT NULL,            -- what the admin sees
    query VARCHAR(200) NOT NULL,            -- what SerpApi is asked
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_query (query)
) ENGINE=InnoDB;

-- Saved location strings. Free-form text, because that is what SerpApi's
-- `location` parameter takes — it resolves the text to coordinates itself.
CREATE TABLE IF NOT EXISTS lead_areas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(100) NOT NULL,
    location VARCHAR(200) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_location (location)
) ENGINE=InnoDB;

-- The ledger. Every page fetched is one SerpApi credit, and a credit is money,
-- so what was spent and what it bought is recorded rather than inferred.
--
-- `resolved_ll` is stored because it is the thing a `location` text search
-- cannot be repeated without: SerpApi turns the text into coordinates, and only
-- the coordinates identify the search that actually ran.
CREATE TABLE IF NOT EXISTS lead_searches (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    query VARCHAR(200) NOT NULL,
    location VARCHAR(200) DEFAULT NULL,
    resolved_ll VARCHAR(64) DEFAULT NULL,
    radius_m INT DEFAULT NULL,
    min_rating DECIMAL(2,1) DEFAULT NULL,
    open_now TINYINT(1) NOT NULL DEFAULT 0,
    pages_fetched INT NOT NULL DEFAULT 0,
    credits_used INT NOT NULL DEFAULT 0,
    results_count INT NOT NULL DEFAULT 0,
    new_count INT NOT NULL DEFAULT 0,
    seen_count INT NOT NULL DEFAULT 0,
    -- SET NULL, not CASCADE: deleting an admin account must not erase the
    -- record of credits their searches spent.
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- One row per business, ever.
--
-- `place_id` is UNIQUE and is what makes a repeated search cheap in attention
-- rather than in noise: the same clinic found by three different queries is one
-- row whose `last_seen_at` moves. `first_seen_at` is what "new" means on the
-- results page, and it is never updated — that is the whole point of it.
--
-- `phone_digits` is denormalised alongside `phone` because the two answer
-- different questions: `phone` is what Google displayed and is what a human
-- reads, `phone_digits` is bare E.164 and is what a wa.me link and a duplicate
-- check need. Deriving one from the other at read time would put the dial-code
-- rules in every caller.
CREATE TABLE IF NOT EXISTS leads (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    place_id VARCHAR(128) NOT NULL,
    data_cid VARCHAR(64) DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    address VARCHAR(500) DEFAULT NULL,
    phone VARCHAR(64) DEFAULT NULL,
    phone_digits VARCHAR(20) DEFAULT NULL,
    website VARCHAR(500) DEFAULT NULL,
    rating DECIMAL(2,1) DEFAULT NULL,
    reviews INT DEFAULT NULL,
    types VARCHAR(255) DEFAULT NULL,
    open_state VARCHAR(120) DEFAULT NULL,
    operating_hours JSON DEFAULT NULL,
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    thumbnail VARCHAR(1000) DEFAULT NULL,
    -- Which query and area turned this up. Context for "why is this in my
    -- list", not a foreign key: the search row may be pruned, the lead stays.
    -- Kept current with last_seen_at rather than frozen with first_seen_at, and
    -- shown as two columns in the saved-leads table: "which search put this in
    -- front of me" is the question a list of 200 businesses cannot be read
    -- without. A NULL source_location means the row predates the fix that made
    -- an area mandatory, and the table says so rather than showing a blank.
    source_query VARCHAR(200) DEFAULT NULL,
    source_location VARCHAR(200) DEFAULT NULL,
    first_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_place (place_id),
    INDEX idx_last_seen (last_seen_at),
    INDEX idx_phone (phone_digits),
    INDEX idx_title (title)
) ENGINE=InnoDB;

-- A starting set of categories, insert-only.
--
-- INSERT IGNORE against the UNIQUE query, for the same reason the plans seed is
-- insert-only: this runs on every boot, and an admin who deleted a category they
-- do not sell to must not find it back tomorrow.
INSERT IGNORE INTO lead_categories (label, query, sort_order) VALUES
    ('Dentists',              'dentist',                  10),
    ('Hair transplant',       'hair transplant clinic',   20),
    ('Eye doctors',           'eye doctor',               30),
    ('Dermatologists',        'dermatologist',            40),
    ('Salons',                'beauty salon',             50),
    ('Gyms',                  'gym',                      60),
    ('Physiotherapy',         'physiotherapy clinic',     70),
    ('Veterinary clinics',    'veterinary clinic',        80),
    ('Real estate agents',    'real estate agency',       90),
    ('Law firms',             'law firm',                100),
    ('Travel agencies',       'travel agency',           110),
    ('Restaurants',           'restaurant',              120);

-- A starting set of areas, insert-only for the same reason.
--
-- This list is not a convenience. An empty `lead_areas` left the Area box on the
-- leads page with nothing but a grey placeholder to suggest what belongs in it,
-- and a blank area does not fail a search — SerpApi passes it to Google with no
-- origin, Google geolocates the request to SerpApi's own datacentre in Northern
-- Virginia, and twenty American businesses come back looking exactly like a
-- working search. Every area here resolves in SerpApi's location database.
INSERT IGNORE INTO lead_areas (label, location, sort_order) VALUES
    ('Lahore',      'Lahore, Pakistan',       10),
    ('Karachi',     'Karachi, Pakistan',      20),
    ('Islamabad',   'Islamabad, Pakistan',    30),
    ('Rawalpindi',  'Rawalpindi, Pakistan',   40),
    ('Faisalabad',  'Faisalabad, Pakistan',   50),
    ('Multan',      'Multan, Pakistan',       60),
    ('Peshawar',    'Peshawar, Pakistan',     70),
    ('Dubai',       'Dubai, United Arab Emirates', 80);

-- ---------------------------------------------------------------------------
-- Session invalidation (#13)
-- ---------------------------------------------------------------------------
--
-- Changing a password, suspending an account or "log out other devices" must
-- end every session the account has — including ones held by someone who is
-- not the owner. Session stores are opaque, so the kill switch is a counter on
-- the row: guards compare the stamp minted at login against the column and a
-- mismatch logs out. Bump the counter and every older session dies at once.
--
-- DEFAULT 0 and guarded like the plan columns, so deploying this on a database
-- full of live sessions breaks none of them: a session that predates the
-- column has no stamp, which reads as the same 0 the row starts at.
SET @add_session_version := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN session_version INT NOT NULL DEFAULT 0',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'session_version'
);
PREPARE stmt_add_session_version FROM @add_session_version;
EXECUTE stmt_add_session_version;
DEALLOCATE PREPARE stmt_add_session_version;

-- ---------------------------------------------------------------------------
-- Verified email changes (#5)
-- ---------------------------------------------------------------------------
--
-- An email change is no longer immediate. The new address is parked on the row
-- and only promoted once a link sent *to that address* is clicked — a stolen
-- session used to be able to repoint the account at an address the attacker
-- owned and take it over through forgot-password. The token is stored hashed
-- (sha256 hex) for the same reason reset_token should be: the column is a
-- bearer credential, and a dump must not hand out live ones.
SET @add_pending_email := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN pending_email VARCHAR(255) NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'pending_email'
);
PREPARE stmt_add_pending_email FROM @add_pending_email;
EXECUTE stmt_add_pending_email;
DEALLOCATE PREPARE stmt_add_pending_email;

SET @add_email_token := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN email_change_token CHAR(64) NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_change_token'
);
PREPARE stmt_add_email_token FROM @add_email_token;
EXECUTE stmt_add_email_token;
DEALLOCATE PREPARE stmt_add_email_token;

SET @add_email_expires := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN email_change_expires DATETIME NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_change_expires'
);
PREPARE stmt_add_email_expires FROM @add_email_expires;
EXECUTE stmt_add_email_expires;
DEALLOCATE PREPARE stmt_add_email_expires;

-- ---------------------------------------------------------------------------
-- Chatbot loop guard (#9)
-- ---------------------------------------------------------------------------
--
-- Per (tenant, chat) counters that make a reply loop visible: how many sends
-- went out inside the current 10-minute window, when the last one went, how
-- many inbounds arrived faster than a person can type, and when the
-- out-of-hours message last fired. Keyed by chat_key — sha256 of the jid —
-- for the same reason chatbot_events is: the admin console reads outcomes and
-- must never see who a tenant talks to.
CREATE TABLE IF NOT EXISTS chatbot_chat_state (
    user_id INT NOT NULL,
    chat_key CHAR(64) NOT NULL,
    window_started_at DATETIME NULL,       -- UTC; start of the current reply window
    window_count INT NOT NULL DEFAULT 0,   -- bot sends inside that window
    last_bot_send_at DATETIME NULL,        -- UTC; what the fast-reply streak is measured against
    fast_streak INT NOT NULL DEFAULT 0,    -- consecutive inbounds <3s after the last bot send
    hours_msg_sent_at DATETIME NULL,       -- UTC; last out-of-hours message, capped at one per 12h
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, chat_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- #10: how many upcoming bookings one customer may hold through the chatbot.
-- 0 means unlimited; the column rides on chatbot_configs like the rest of the
-- booking settings.
SET @add_appt_max_upcoming := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE chatbot_configs ADD COLUMN appointment_max_upcoming INT NOT NULL DEFAULT 1',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatbot_configs' AND COLUMN_NAME = 'appointment_max_upcoming'
);
PREPARE stmt_add_appt_max_upcoming FROM @add_appt_max_upcoming;
EXECUTE stmt_add_appt_max_upcoming;
DEALLOCATE PREPARE stmt_add_appt_max_upcoming;

-- ---------------------------------------------------------------------------
-- Subscription expiry (#8)
-- ---------------------------------------------------------------------------
--
-- 'expired' marks a paid subscription whose grace period has run out — the row
-- stays as history while the tenant is moved to a free plan. Widening the ENUM
-- is additive; guarded on COLUMN_TYPE so re-applying is a no-op.
SET @add_sub_expired := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE subscriptions MODIFY COLUMN status ENUM(''trialing'',''active'',''past_due'',''canceled'',''expired'') NOT NULL DEFAULT ''active''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'status'
      AND COLUMN_TYPE LIKE '%''expired''%'
);
PREPARE stmt_add_sub_expired FROM @add_sub_expired;
EXECUTE stmt_add_sub_expired;
DEALLOCATE PREPARE stmt_add_sub_expired;

-- ---------------------------------------------------------------------------
-- Cleanup indexes (#29)
-- ---------------------------------------------------------------------------
--
-- The daily log sweep deletes by age; without a created_at index each DELETE
-- scans the whole table it is trimming.
SET @idx_login_attempts := (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_created ON login_attempts (created_at)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'idx_created'
);
PREPARE stmt_idx_login_attempts FROM @idx_login_attempts;
EXECUTE stmt_idx_login_attempts;
DEALLOCATE PREPARE stmt_idx_login_attempts;

SET @idx_chatbot_events := (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_created ON chatbot_events (created_at)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatbot_events' AND INDEX_NAME = 'idx_created'
);
PREPARE stmt_idx_chatbot_events FROM @idx_chatbot_events;
EXECUTE stmt_idx_chatbot_events;
DEALLOCATE PREPARE stmt_idx_chatbot_events;

SET @idx_audit_log := (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_created ON audit_log (created_at)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND INDEX_NAME = 'idx_created'
);
PREPARE stmt_idx_audit_log FROM @idx_audit_log;
EXECUTE stmt_idx_audit_log;
DEALLOCATE PREPARE stmt_idx_audit_log;

SET @idx_appt_notices := (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_created ON appointment_notifications (created_at)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointment_notifications' AND INDEX_NAME = 'idx_created'
);
PREPARE stmt_idx_appt_notices FROM @idx_appt_notices;
EXECUTE stmt_idx_appt_notices;
DEALLOCATE PREPARE stmt_idx_appt_notices;
