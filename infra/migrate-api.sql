-- ============================================================
-- Newton IA — API REST pública (Fase 1)
-- API Keys multi-tenant + logs de requisições
-- Roda apos migrate-newton.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── API Keys ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS api_keys (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id    INT NOT NULL,
    name         VARCHAR(120) NOT NULL,
    key_hash     CHAR(64) NOT NULL,
    key_prefix   VARCHAR(16) NOT NULL,
    scopes       VARCHAR(255) DEFAULT 'chat:read,chat:write,agents:read,conversations:read',
    last_used_at DATETIME NULL,
    last_used_ip VARCHAR(45) NULL,
    expires_at   DATETIME NULL,
    revoked_at   DATETIME NULL,
    created_by   INT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_key_hash (key_hash),
    KEY idx_tenant (tenant_id),
    KEY idx_prefix (key_prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Logs de requisicoes (audit + rate-limit) ──────────────────
CREATE TABLE IF NOT EXISTS api_request_logs (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT NOT NULL,
    api_key_id  INT NULL,
    endpoint    VARCHAR(80) NOT NULL,
    method      VARCHAR(8) NOT NULL,
    status_code SMALLINT NOT NULL,
    latency_ms  INT NULL,
    ip          VARCHAR(45) NULL,
    user_agent  VARCHAR(255) NULL,
    error       VARCHAR(255) NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tenant_date (tenant_id, created_at),
    KEY idx_key_date (api_key_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Outbound webhooks (Fase 2 — Make/n8n/HERMES escutarem eventos Newton) ──
CREATE TABLE IF NOT EXISTS outbound_webhooks (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT NOT NULL,
    name        VARCHAR(120) NOT NULL,
    url         VARCHAR(500) NOT NULL,
    events      VARCHAR(500) NOT NULL COMMENT 'csv: message.received,message.sent,conversation.started,conversation.ended,handoff.requested',
    secret      VARCHAR(64) NOT NULL COMMENT 'HMAC signing key',
    active      TINYINT(1) DEFAULT 1,
    last_fired_at DATETIME NULL,
    last_status SMALLINT NULL,
    fail_count  INT DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS outbound_webhook_deliveries (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    webhook_id  INT NOT NULL,
    event       VARCHAR(60) NOT NULL,
    payload     MEDIUMTEXT NOT NULL,
    status_code SMALLINT NULL,
    response    TEXT NULL,
    latency_ms  INT NULL,
    attempt     TINYINT DEFAULT 1,
    delivered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_webhook (webhook_id, delivered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
