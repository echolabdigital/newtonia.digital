-- ============================================================
-- Newton IA — FLUX
-- Listas de leads + campanhas orquestradas com IA personalizada
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Listas de leads ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lead_lists (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT NOT NULL,
    name        VARCHAR(160) NOT NULL,
    source      ENUM('manual','csv','google_maps','api','hermes') DEFAULT 'manual',
    notes       TEXT NULL,
    lead_count  INT DEFAULT 0,
    created_by  INT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Leads individuais ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS leads (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    list_id      INT NOT NULL,
    tenant_id    INT NOT NULL,
    name         VARCHAR(200) NULL,
    phone        VARCHAR(40) NULL,
    email        VARCHAR(200) NULL,
    business     VARCHAR(200) NULL COMMENT 'nome do negocio, vertical',
    address      VARCHAR(400) NULL,
    city         VARCHAR(100) NULL,
    state        VARCHAR(40) NULL,
    rating       DECIMAL(2,1) NULL,
    raw_json     TEXT NULL COMMENT 'payload original do extractor',
    status       ENUM('new','contacted','engaged','converted','lost','invalid') DEFAULT 'new',
    last_status_at DATETIME NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_list (list_id),
    KEY idx_tenant_status (tenant_id, status),
    KEY idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Campanhas ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS campaigns (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT NOT NULL,
    name            VARCHAR(160) NOT NULL,
    agent_id        INT NOT NULL,
    list_id         INT NOT NULL,
    channel_id      INT NULL COMMENT 'agent_channel.id (WhatsApp instance)',
    template        TEXT NOT NULL COMMENT 'prompt-base para a IA personalizar por lead',
    personalize     TINYINT(1) DEFAULT 1 COMMENT 'usa LLM para personalizar por lead',
    throttle_per_min INT DEFAULT 8 COMMENT 'mensagens/min (anti-ban)',
    daily_cap       INT DEFAULT 500 COMMENT 'max mensagens/dia',
    status          ENUM('draft','scheduled','running','paused','completed','failed') DEFAULT 'draft',
    scheduled_at    DATETIME NULL,
    started_at      DATETIME NULL,
    completed_at    DATETIME NULL,
    total           INT DEFAULT 0,
    sent            INT DEFAULT 0,
    failed          INT DEFAULT 0,
    replied         INT DEFAULT 0,
    created_by      INT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tenant_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Mensagens da campanha (1 por lead) ────────────────────────
CREATE TABLE IF NOT EXISTS campaign_messages (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    campaign_id     INT NOT NULL,
    lead_id         INT NOT NULL,
    tenant_id       INT NOT NULL,
    content         TEXT NULL COMMENT 'mensagem final (apos personalizacao)',
    status          ENUM('pending','sending','sent','failed','skipped','replied') DEFAULT 'pending',
    error           VARCHAR(255) NULL,
    conversation_id INT NULL,
    sent_at         DATETIME NULL,
    replied_at      DATETIME NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_campaign_status (campaign_id, status),
    KEY idx_pending (status, sent_at),
    KEY idx_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
