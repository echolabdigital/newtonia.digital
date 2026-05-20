-- ============================================================
-- Newton IA — PULSE
-- Agendamento + qualificacao SPIN Selling
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Config de agenda por agente ───────────────────────────────
ALTER TABLE agents
  ADD COLUMN IF NOT EXISTS pulse_enabled    TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS pulse_hours      VARCHAR(500) DEFAULT '{"mon":"09:00-18:00","tue":"09:00-18:00","wed":"09:00-18:00","thu":"09:00-18:00","fri":"09:00-18:00"}' COMMENT 'JSON dia=>faixa',
  ADD COLUMN IF NOT EXISTS pulse_slot_min   INT DEFAULT 30  COMMENT 'duracao do slot em minutos',
  ADD COLUMN IF NOT EXISTS pulse_buffer_min INT DEFAULT 15  COMMENT 'intervalo entre slots',
  ADD COLUMN IF NOT EXISTS pulse_timezone   VARCHAR(40) DEFAULT 'America/Sao_Paulo',
  ADD COLUMN IF NOT EXISTS pulse_max_per_day INT DEFAULT 10,
  ADD COLUMN IF NOT EXISTS pulse_meeting_kind VARCHAR(40) DEFAULT 'video' COMMENT 'video|in_person|phone',
  ADD COLUMN IF NOT EXISTS pulse_meeting_link VARCHAR(500) NULL COMMENT 'link/endereco padrao da reuniao';

-- ── Agendamentos ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS appointments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT NOT NULL,
    agent_id        INT NOT NULL,
    conversation_id INT NULL,
    contact_name    VARCHAR(200) NULL,
    contact_phone   VARCHAR(40) NULL,
    contact_email   VARCHAR(200) NULL,
    title           VARCHAR(200) NOT NULL,
    notes           TEXT NULL,
    starts_at       DATETIME NOT NULL,
    ends_at         DATETIME NOT NULL,
    meeting_kind    VARCHAR(40) DEFAULT 'video',
    meeting_link    VARCHAR(500) NULL,
    status          ENUM('scheduled','confirmed','rescheduled','cancelled','no_show','completed') DEFAULT 'scheduled',
    reminded_24h    TINYINT(1) DEFAULT 0,
    reminded_1h     TINYINT(1) DEFAULT 0,
    confirmed_at    DATETIME NULL,
    cancelled_at    DATETIME NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tenant_starts (tenant_id, starts_at),
    KEY idx_agent_starts (agent_id, starts_at),
    KEY idx_status (status),
    KEY idx_reminders (status, reminded_24h, reminded_1h, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── SPIN qualification (1 por conversation) ───────────────────
CREATE TABLE IF NOT EXISTS spin_qualifications (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT NOT NULL,
    conversation_id INT NOT NULL,
    situation       TEXT NULL,
    problem         TEXT NULL,
    implication     TEXT NULL,
    need_payoff     TEXT NULL,
    score           TINYINT NULL COMMENT '0-100 (probabilidade de fechar)',
    temperature     ENUM('cold','warm','hot') DEFAULT 'cold',
    next_step       VARCHAR(255) NULL,
    generated_by    VARCHAR(40) DEFAULT 'auto',
    model_used      VARCHAR(80) NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_conv (conversation_id),
    KEY idx_tenant_temp (tenant_id, temperature)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
