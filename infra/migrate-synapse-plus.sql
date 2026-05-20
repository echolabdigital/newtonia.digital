-- ============================================================
-- Newton IA — SYNAPSE Plus
-- Knowledge base + keyword triggers + conversation summaries
-- Roda apos migrate-newton.sql e migrate-api.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Base de conhecimento por agente ───────────────────────────
CREATE TABLE IF NOT EXISTS agent_knowledge (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    agent_id     INT NOT NULL,
    tenant_id    INT NOT NULL,
    title        VARCHAR(200) NOT NULL,
    content      MEDIUMTEXT NOT NULL,
    source_type  ENUM('text','url','file') DEFAULT 'text',
    source_url   VARCHAR(500) NULL,
    enabled      TINYINT(1) DEFAULT 1,
    char_count   INT DEFAULT 0,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_agent (agent_id, enabled),
    KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Gatilhos por palavra-chave ────────────────────────────────
CREATE TABLE IF NOT EXISTS agent_keywords (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    agent_id    INT NOT NULL,
    tenant_id   INT NOT NULL,
    keyword     VARCHAR(120) NOT NULL COMMENT 'termo ou expressao a detectar (case-insensitive)',
    match_type  ENUM('contains','exact','starts_with','regex') DEFAULT 'contains',
    action      ENUM('handoff','tag','webhook','pause') NOT NULL,
    action_data VARCHAR(255) NULL COMMENT 'tag name, webhook url ou metadata adicional',
    direction   ENUM('in','out','any') DEFAULT 'in' COMMENT 'mensagem inbound, outbound ou ambas',
    active      TINYINT(1) DEFAULT 1,
    hit_count   INT DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_agent (agent_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Resumos de conversa (gerados pela IA no handoff) ──────────
CREATE TABLE IF NOT EXISTS conversation_summaries (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    tenant_id       INT NOT NULL,
    summary         TEXT NOT NULL,
    sentiment       ENUM('positive','neutral','negative','urgent') DEFAULT 'neutral',
    intent          VARCHAR(120) NULL,
    next_step       VARCHAR(255) NULL,
    generated_by    VARCHAR(50) DEFAULT 'auto',
    model_used      VARCHAR(80) NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_conv (conversation_id),
    KEY idx_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Tags de conversa (para ação 'tag' do keyword trigger) ─────
CREATE TABLE IF NOT EXISTS conversation_tags (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    tag             VARCHAR(80) NOT NULL,
    source          VARCHAR(40) DEFAULT 'keyword' COMMENT 'keyword|manual|api',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tag (conversation_id, tag),
    KEY idx_tag (tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
