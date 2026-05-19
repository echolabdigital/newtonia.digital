-- ============================================================
-- Newton IA — Migração de banco existente (ex-HERMES)
-- Executar UMA vez no banco newt_newtonia em produção.
-- Uso:
--   mysql -u newt_newtonia -p newt_newtonia < infra/migrate-newton.sql
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '-03:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ── 1. Remover tabelas HERMES-específicas ──────────────────────────────────
DROP TABLE IF EXISTS cnpj_lists;
DROP TABLE IF EXISTS cnpj_list_items;
DROP TABLE IF EXISTS cnpj_download_log;
DROP TABLE IF EXISTS cnpj_static_cache;
DROP TABLE IF EXISTS cnpj_plans;
DROP TABLE IF EXISTS cnpj_addon_packs;
DROP TABLE IF EXISTS cnpj_addon_purchases;
DROP TABLE IF EXISTS crm_cards;
DROP TABLE IF EXISTS crm_card_history;
DROP TABLE IF EXISTS crm_card_tags;
DROP TABLE IF EXISTS crm_columns;
DROP TABLE IF EXISTS crm_comments;
DROP TABLE IF EXISTS crm_tags;
DROP TABLE IF EXISTS lead_pack_credits;
DROP TABLE IF EXISTS wa_campaigns;
DROP TABLE IF EXISTS wa_instances;
DROP TABLE IF EXISTS wa_messages;

-- ── 2. Atualizar planos: substituir seeds HERMES por Newton IA ─────────────
DELETE FROM plans WHERE tier_code IN ('trial','starter','pro','business');

INSERT INTO plans
    (tier_code, code, name, price_cents, annual_price_cents, limit_cnpj_monthly, limit_contacts, limit_dispatch_daily, users_limit, popular, visible_public, support_level, trial_days, active, display_order, features)
VALUES
    ('trial',    'newton-trial',    'Trial',    0,       0,       1,   500,   1,  1, 0, 0, 'community', 7,  1, 0, '{"agents":1,"channels":1,"messages_monthly":500,"inbox":true,"api_access":false}'),
    ('starter',  'newton-starter',  'Starter',  19700,   197000,  3,   2000,  2,  2, 0, 1, 'email',     0,  1, 1, '{"agents":3,"channels":2,"messages_monthly":2000,"inbox":true,"api_access":false}'),
    ('pro',      'newton-pro',      'Pro',      49700,   497000,  10,  8000,  5,  5, 1, 1, 'whatsapp',  0,  1, 2, '{"agents":10,"channels":5,"messages_monthly":8000,"inbox":true,"api_access":true}'),
    ('business', 'newton-business', 'Business', 149700,  1497000, 999, 30000, 15, 15, 0, 1, 'sla',       0,  1, 3, '{"agents":-1,"channels":15,"messages_monthly":30000,"inbox":true,"api_access":true}');

-- ── 3. Garantir colunas Newton nas tabelas SYNAPSE ─────────────────────────
-- agents: adicionar provider se não existir
ALTER TABLE agents ADD COLUMN IF NOT EXISTS provider VARCHAR(40) DEFAULT NULL;
ALTER TABLE agents ADD COLUMN IF NOT EXISTS context_window INT DEFAULT 20;

-- conversations: adicionar sent_by_human tracking
ALTER TABLE messages ADD COLUMN IF NOT EXISTS sent_by_human TINYINT DEFAULT 0;

-- ── 4. Garantir tabelas SYNAPSE (caso não existam) ─────────────────────────
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
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    INDEX idx_phone (contact_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- ── 5. Limpar login_rate_limits se não existir ─────────────────────────────
CREATE TABLE IF NOT EXISTS login_rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(120) NOT NULL,
    attempts INT DEFAULT 1,
    blocked_until TIMESTAMP NULL DEFAULT NULL,
    last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_id (identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIM DA MIGRAÇÃO
-- ============================================================
