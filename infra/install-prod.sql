-- ============================================================
-- Newton IA — Setup de produção (banco MySQL)
-- Executar UMA vez em DB vazio antes do primeiro deploy.
-- Seguro rodar novamente: usa IF NOT EXISTS + INSERT IGNORE.
-- ============================================================
-- Uso:
--   mysql -u newt_newtonia -p newt_newtonia < infra/install-prod.sql
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '-03:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ── Usuários ──────────────────────────────────────────────────────────────────
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

-- ── Tenants ───────────────────────────────────────────────────────────────────
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

-- ── Tenant ↔ Usuário ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tenant_users (
    tenant_id INT NOT NULL,
    user_id INT NOT NULL,
    role VARCHAR(30) DEFAULT 'owner',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, user_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Planos Newton IA ──────────────────────────────────────────────────────────
-- Mapeamento de colunas legadas:
--   limit_cnpj_monthly   = limite de agentes
--   limit_contacts       = limite de mensagens/mês
--   limit_dispatch_daily = limite de canais WhatsApp
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

INSERT IGNORE INTO plans
    (tier_code, code, name, price_cents, annual_price_cents, limit_cnpj_monthly, limit_contacts, limit_dispatch_daily, users_limit, popular, visible_public, support_level, trial_days, active, display_order, features)
VALUES
    ('trial',    'newton-trial',    'Trial',    0,       0,       1,   500,   1,  1, 0, 0, 'community', 7,  1, 0, '{"agents":1,"channels":1,"messages_monthly":500,"inbox":true,"api_access":false}'),
    ('starter',  'newton-starter',  'Starter',  19700,   197000,  3,   2000,  2,  2, 0, 1, 'email',     0,  1, 1, '{"agents":3,"channels":2,"messages_monthly":2000,"inbox":true,"api_access":false}'),
    ('pro',      'newton-pro',      'Pro',      49700,   497000,  10,  8000,  5,  5, 1, 1, 'whatsapp',  0,  1, 2, '{"agents":10,"channels":5,"messages_monthly":8000,"inbox":true,"api_access":true}'),
    ('business', 'newton-business', 'Business', 149700,  1497000, 999, 30000, 15, 15, 0, 1, 'sla',       0,  1, 3, '{"agents":-1,"channels":15,"messages_monthly":30000,"inbox":true,"api_access":true}');

-- ── Configurações de sistema ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS system_settings (
    skey VARCHAR(80) PRIMARY KEY,
    svalue TEXT,
    is_secret TINYINT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO system_settings (skey, svalue, is_secret) VALUES
    ('asaas_api_key',      '',        1),
    ('asaas_env',          'sandbox', 0),
    ('asaas_webhook_token','',        1);

-- ── Feature flags por tenant ───────────────────────────────────────────────────
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

-- ── Reset de senha ─────────────────────────────────────────────────────────────
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

-- ── Rate limiting ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS login_rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(120) NOT NULL,
    attempts INT DEFAULT 1,
    blocked_until TIMESTAMP NULL DEFAULT NULL,
    last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_id (identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Preferências de usuário ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_preferences (
    user_id   INT NOT NULL,
    pref_key  VARCHAR(80) NOT NULL,
    pref_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, pref_key),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Audit log ─────────────────────────────────────────────────────────────────
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

-- ── Billing: eventos webhook ──────────────────────────────────────────────────
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

-- ── SYNAPSE — Agentes de IA ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(160) NOT NULL,
    prompt TEXT,
    model VARCHAR(80) DEFAULT 'llama-3.3-70b-versatile',
    provider VARCHAR(40) DEFAULT NULL,
    context_window INT DEFAULT 20,
    status VARCHAR(20) DEFAULT 'inactive',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── SYNAPSE — Canais conectados ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS agent_channels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    tenant_id INT NOT NULL,
    channel_type VARCHAR(20) DEFAULT 'whatsapp',
    instance_id VARCHAR(80),
    token VARCHAR(120),
    client_token VARCHAR(120),
    connected_phone VARCHAR(30),
    webhook_token VARCHAR(60),
    status VARCHAR(20) DEFAULT 'disconnected',
    config_json TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_agent (agent_id),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── SYNAPSE — Conversas ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    tenant_id INT NOT NULL,
    channel_id INT DEFAULT NULL,
    contact_phone VARCHAR(30),
    contact_name VARCHAR(120),
    status VARCHAR(20) DEFAULT 'open',
    message_count INT DEFAULT 0,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_message_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_agent (agent_id),
    INDEX idx_phone (contact_phone),
    INDEX idx_last_msg (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── SYNAPSE — Mensagens ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    direction ENUM('in','out') NOT NULL,
    content TEXT,
    type VARCHAR(20) DEFAULT 'text',
    sent_by_human TINYINT DEFAULT 0,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_conv (conversation_id),
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIM — Próximo passo: criar super-admin via /setup-admin.php
--       (deletar setup-admin.php logo após o uso)
-- ============================================================
