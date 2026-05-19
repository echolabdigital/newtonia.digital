-- ============================================================
-- NEWTON AI — CNPJ Download Limits  (quota v2)
-- Run once against the MySQL app database
-- ============================================================

-- 1. Planos de CNPJ -------------------------------------------------------
CREATE TABLE IF NOT EXISTS cnpj_plans (
    id            INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(50)    NOT NULL,
    monthly_limit INT UNSIGNED   NOT NULL COMMENT 'Leads incluídos por mês',
    price_monthly DECIMAL(10,2)  NOT NULL,
    active        TINYINT(1)     NOT NULL DEFAULT 1,
    created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO cnpj_plans (name, monthly_limit, price_monthly) VALUES
    ('Starter',      100,  49.90),
    ('Basic',        200,  89.90),
    ('Professional', 300, 129.90),
    ('Business',     500, 199.90);

-- 2. Pacotes de créditos avulsos ------------------------------------------
CREATE TABLE IF NOT EXISTS cnpj_addon_packs (
    id         INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    quantity   INT UNSIGNED   NOT NULL COMMENT 'Leads do pacote',
    price      DECIMAL(10,2)  NOT NULL,
    active     TINYINT(1)     NOT NULL DEFAULT 1,
    created_at TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO cnpj_addon_packs (quantity, price) VALUES
    (100,   19.90),
    (1000, 149.90);

-- 3. Colunas no tenant -----------------------------------------------------
ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS cnpj_plan_id        INT UNSIGNED  DEFAULT NULL  COMMENT 'FK cnpj_plans.id',
    ADD COLUMN IF NOT EXISTS cnpj_limit_override  INT UNSIGNED  DEFAULT NULL  COMMENT 'NULL = usa limite do plano',
    ADD COLUMN IF NOT EXISTS cnpj_addon_credits   INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Créditos avulsos acumulados';

-- 4. Log de downloads (uma linha por evento) --------------------------------
CREATE TABLE IF NOT EXISTS cnpj_download_log (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT UNSIGNED    NOT NULL,
    downloaded_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    records_count INT UNSIGNED    NOT NULL DEFAULT 0,
    filters_json  TEXT            DEFAULT NULL,
    INDEX idx_tenant_month (tenant_id, downloaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Histórico de compras de addons ----------------------------------------
CREATE TABLE IF NOT EXISTS cnpj_addon_purchases (
    id           INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    tenant_id    INT UNSIGNED   NOT NULL,
    pack_id      INT UNSIGNED   NOT NULL,
    quantity     INT UNSIGNED   NOT NULL,
    price_paid   DECIMAL(10,2)  NOT NULL,
    purchased_at TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
