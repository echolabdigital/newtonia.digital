-- ============================================================
-- Newton IA — SONAR
-- Voz no WhatsApp: TTS (ElevenLabs) + Whisper transcricao
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE agents
  ADD COLUMN IF NOT EXISTS voice_enabled    TINYINT(1) DEFAULT 0 COMMENT 'aceita inbound de audio (transcribe)',
  ADD COLUMN IF NOT EXISTS voice_reply      TINYINT(1) DEFAULT 0 COMMENT 'responde em audio quando o cliente manda audio',
  ADD COLUMN IF NOT EXISTS voice_provider   VARCHAR(40) DEFAULT 'elevenlabs',
  ADD COLUMN IF NOT EXISTS voice_id         VARCHAR(80) NULL COMMENT 'voice_id no provider TTS',
  ADD COLUMN IF NOT EXISTS voice_style      VARCHAR(40) DEFAULT 'natural' COMMENT 'natural|excited|calm',
  ADD COLUMN IF NOT EXISTS voice_max_chars  INT DEFAULT 500 COMMENT 'limite por audio (custo)';

ALTER TABLE messages
  ADD COLUMN IF NOT EXISTS audio_url        VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS transcript       TEXT NULL COMMENT 'texto extraido do audio inbound';

-- Log de uso de voz (custos)
CREATE TABLE IF NOT EXISTS voice_usage (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT NOT NULL,
    agent_id    INT NOT NULL,
    direction   ENUM('in','out') NOT NULL COMMENT 'in=transcribe, out=tts',
    provider    VARCHAR(40) NOT NULL,
    char_count  INT DEFAULT 0,
    sec_count   INT DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tenant_date (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
