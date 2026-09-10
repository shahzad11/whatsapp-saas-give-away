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

-- What a tenant offers. Duration drives slot maths, so it is minutes, not text.
CREATE TABLE IF NOT EXISTS appointment_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    duration_minutes INT NOT NULL DEFAULT 30,
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
    duration_minutes INT NOT NULL DEFAULT 30,
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
