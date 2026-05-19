-- Newton CRM + Disparador — schema MySQL
-- Rodar uma vez no MySQL (newtonia)

-- ── CRM ──────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS crm_columns (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT NOT NULL,
    name        VARCHAR(60) NOT NULL,
    color       VARCHAR(20) DEFAULT '#6366f1',
    position    INT DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_pos (tenant_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_cards (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT NOT NULL,
    column_id     INT NOT NULL,
    cnpj          VARCHAR(14) NULL,
    razao_social  VARCHAR(255),
    nome_fantasia VARCHAR(255),
    telefone      VARCHAR(20),
    email         VARCHAR(120),
    cidade_uf     VARCHAR(80),
    cnae          VARCHAR(20),
    capital       DECIMAL(15,2) NULL,
    score         INT DEFAULT 0,
    notes         TEXT,
    position      INT DEFAULT 0,
    last_action   TIMESTAMP NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_col (tenant_id, column_id, position),
    INDEX idx_cnpj (cnpj),
    FOREIGN KEY (column_id) REFERENCES crm_columns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_card_history (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    card_id     INT NOT NULL,
    user_id     INT NULL,
    action      VARCHAR(40) NOT NULL, -- 'moved', 'note', 'whatsapp_sent', 'email_sent'
    detail      TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_card_time (card_id, created_at),
    FOREIGN KEY (card_id) REFERENCES crm_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Disparador WhatsApp ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS wa_instances (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT NOT NULL,
    name          VARCHAR(60),
    provider      VARCHAR(20) DEFAULT 'zapi', -- zapi, evolution, etc
    instance_id   VARCHAR(80),
    token         VARCHAR(120),
    phone         VARCHAR(20),
    status        VARCHAR(20) DEFAULT 'disconnected', -- connected, qr_pending, disconnected
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wa_campaigns (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT NOT NULL,
    instance_id   INT NOT NULL,
    name          VARCHAR(120) NOT NULL,
    template      TEXT NOT NULL, -- com {{nome}}, {{cidade}}, etc
    list_id       INT NULL, -- ref cnpj_lists (filtro salvo)
    delay_min_s   INT DEFAULT 30,
    delay_max_s   INT DEFAULT 90,
    daily_limit   INT DEFAULT 100,
    status        VARCHAR(20) DEFAULT 'draft', -- draft, running, paused, done
    total_targets INT DEFAULT 0,
    sent_count    INT DEFAULT 0,
    failed_count  INT DEFAULT 0,
    started_at    TIMESTAMP NULL,
    finished_at   TIMESTAMP NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id),
    FOREIGN KEY (instance_id) REFERENCES wa_instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wa_messages (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT NOT NULL,
    campaign_id   INT NOT NULL,
    cnpj          VARCHAR(14) NULL,
    phone         VARCHAR(20) NOT NULL,
    razao_social  VARCHAR(255),
    message       TEXT,
    status        VARCHAR(20) DEFAULT 'pending', -- pending, sent, failed, read, replied
    provider_msg_id VARCHAR(80),
    error         VARCHAR(255),
    scheduled_at  TIMESTAMP NULL,
    sent_at       TIMESTAMP NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_campaign_status (campaign_id, status),
    INDEX idx_phone (phone),
    INDEX idx_scheduled (scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
