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
