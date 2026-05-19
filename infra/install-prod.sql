-- ============================================================
-- HERMES.b2b — Setup de produção (banco MySQL)
-- Executar UMA vez em DB vazio antes do primeiro deploy.
-- Seguro rodar novamente: usa IF NOT EXISTS + INSERT IGNORE.
-- ============================================================
-- Uso:
--   mysql -u newton -p newtonia < install-prod.sql
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '-03:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ── Usuários ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(180) NOT NULL,
    name VARCHAR(120),
    password_hash VARCHAR(255) NOT NULL,
    is_super_admin TINYINT DEFAULT 0,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Tenants ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(80),
    brand_name VARCHAR(160),
    brand_color VARCHAR(20),
    plan_id INT DEFAULT NULL,
    status VARCHAR(30) DEFAULT 'pending',
    cnpj VARCHAR(20) DEFAULT NULL,
    email VARCHAR(180) DEFAULT NULL,
    trial_started_at TIMESTAMP NULL DEFAULT NULL,
    suspended_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Tenant ↔ Usuário ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tenant_users (
    tenant_id INT NOT NULL,
    user_id INT NOT NULL,
    role VARCHAR(30) DEFAULT 'owner',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, user_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Planos ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tier_code VARCHAR(20) NOT NULL DEFAULT '',
    code VARCHAR(60) NOT NULL DEFAULT '',
    name VARCHAR(80) NOT NULL DEFAULT '',
    price_cents INT NOT NULL DEFAULT 0,
    annual_price_cents INT DEFAULT 0,
    limit_dispatch_daily INT DEFAULT 0,
    limit_contacts INT DEFAULT 0,
    limit_extractor_monthly INT DEFAULT 0,
    limit_cnpj_monthly INT DEFAULT 0,
    users_limit INT DEFAULT 1,
    mail_self_limit INT DEFAULT 1,
    popular TINYINT DEFAULT 0,
    visible_public TINYINT DEFAULT 1,
    support_level VARCHAR(20) DEFAULT 'email',
    trial_days INT DEFAULT 0,
    features TEXT,
    active TINYINT DEFAULT 1,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tier (tier_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed dos 4 tiers HERMES
INSERT IGNORE INTO plans
    (tier_code, code, name, price_cents, annual_price_cents, limit_cnpj_monthly, limit_contacts, users_limit, mail_self_limit, popular, visible_public, support_level, trial_days, active, display_order)
VALUES
    ('trial',    'hermes-trial',    'Trial',    0,       0,        30,  100,   1,  1, 0, 0, 'community', 3,  1, 0),
    ('starter',  'hermes-starter',  'Starter',  14700,   147000,   50,  300,   1,  1, 0, 1, 'email',     0,  1, 1),
    ('pro',      'hermes-pro',      'Pro',      39700,   397000,   200, 2000,  3,  3, 1, 1, 'priority',  0,  1, 2),
    ('business', 'hermes-business', 'Business', 79700,   797000,   500, 10000, 10, 10, 0, 1, 'dedicated', 0,  1, 3);

-- ── Configurações de sistema ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS system_settings (
    skey VARCHAR(80) PRIMARY KEY,
    svalue TEXT,
    is_secret TINYINT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seeds: configurações padrão (substituir via /admin/integrations.php)
INSERT IGNORE INTO system_settings (skey, svalue, is_secret) VALUES
    ('asaas_api_key',      '',            1),
    ('asaas_env',          'sandbox',     0),
    ('asaas_webhook_token','',            1);

-- ── Feature flags por tenant ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tenant_features (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    feature VARCHAR(60) NOT NULL,
    enabled TINYINT DEFAULT 0,
    override TINYINT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tf (tenant_id, feature),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Reset de senha ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Billing: customers ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS asaas_customers (
    tenant_id INT PRIMARY KEY,
    asaas_customer_id VARCHAR(40) NOT NULL,
    name VARCHAR(160),
    email VARCHAR(160),
    cpf_cnpj VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_asaas (asaas_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Billing: subscriptions ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS asaas_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    asaas_subscription_id VARCHAR(40) NOT NULL,
    plan_id INT NOT NULL,
    tier_code VARCHAR(20),
    billing_type VARCHAR(20),
    cycle VARCHAR(20),
    value_cents INT,
    next_due_date DATE NULL,
    status VARCHAR(30) DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_asaas_sub (asaas_subscription_id),
    INDEX idx_tenant (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Billing: payments ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS asaas_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    asaas_payment_id VARCHAR(40) NOT NULL,
    asaas_subscription_id VARCHAR(40) NULL,
    kind VARCHAR(30) DEFAULT 'subscription',
    value_cents INT,
    billing_type VARCHAR(20),
    status VARCHAR(30),
    due_date DATE NULL,
    paid_date DATE NULL,
    invoice_url TEXT,
    bank_slip_url TEXT,
    pix_qr_code TEXT,
    payload JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_asaas_pay (asaas_payment_id),
    INDEX idx_tenant_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Billing: webhook events (dedup) ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS asaas_events (
    id VARCHAR(40) PRIMARY KEY,
    event VARCHAR(60),
    payment_id VARCHAR(40),
    subscription_id VARCHAR(40),
    payload JSON,
    status VARCHAR(20) DEFAULT 'processed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event (event),
    INDEX idx_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Lead pack credits ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lead_pack_credits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    asaas_payment_id VARCHAR(40) NULL,
    leads INT NOT NULL,
    value_cents INT,
    status VARCHAR(20) DEFAULT 'pending',
    granted_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── CRM ───────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS crm_columns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(20) DEFAULT '#10b981',
    position INT DEFAULT 0,
    is_default TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(60) NOT NULL,
    color VARCHAR(20) DEFAULT '#10b981',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    column_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    cnpj VARCHAR(20) DEFAULT NULL,
    contact_name VARCHAR(120) DEFAULT NULL,
    contact_email VARCHAR(180) DEFAULT NULL,
    contact_phone VARCHAR(30) DEFAULT NULL,
    value_cents INT DEFAULT 0,
    assignee_user_id INT DEFAULT NULL,
    position INT DEFAULT 0,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_tenant_col (tenant_id, column_id),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_card_tags (
    card_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (card_id, tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_card_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    card_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    action VARCHAR(60),
    detail TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_card (card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    card_id INT NOT NULL,
    user_id INT NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_card (card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── CNPJ / Radar ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cnpj_lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cnpj_download_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    cnpj VARCHAR(20) NOT NULL,
    list_id INT DEFAULT NULL,
    records_count INT DEFAULT 1,
    filters_json JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_month (tenant_id, created_at),
    INDEX idx_tenant_cnpj (tenant_id, cnpj)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cnpj_static_cache (
    cache_key VARCHAR(100) PRIMARY KEY,
    cache_value JSON,
    expires_at TIMESTAMP,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Preferências de usuário ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_preferences (
    user_id   INT NOT NULL,
    pref_key  VARCHAR(80) NOT NULL,
    pref_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, pref_key),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Audit log (genérico) ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    tenant_id INT DEFAULT NULL,
    action VARCHAR(80) NOT NULL,
    target_type VARCHAR(40) DEFAULT NULL,
    target_id VARCHAR(40) DEFAULT NULL,
    meta TEXT DEFAULT NULL,
    ip VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIM — Próximo passo: criar super-admin via /setup-admin.php
--       (deletar setup-admin.php logo após o uso)
-- ============================================================
