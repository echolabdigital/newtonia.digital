-- Newton CNPJ — Listas de prospecção salvas (MySQL tenant DB)
-- Executar no banco principal da Newtonia

CREATE TABLE IF NOT EXISTS cnpj_lists (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   INT UNSIGNED NOT NULL,
    name        VARCHAR(200) NOT NULL,
    description TEXT,
    filter_json JSON,
    item_count  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cnpj_list_items (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_id       INT UNSIGNED    NOT NULL,
    tenant_id     INT UNSIGNED    NOT NULL,
    cnpj          CHAR(14)        NOT NULL,
    razao_social  VARCHAR(300)    NOT NULL DEFAULT '',
    nome_fantasia VARCHAR(200)    NOT NULL DEFAULT '',
    uf            CHAR(2)         NOT NULL DEFAULT '',
    municipio     VARCHAR(200)    NOT NULL DEFAULT '',
    cnae          CHAR(7)         NOT NULL DEFAULT '',
    cnae_desc     VARCHAR(300)    NOT NULL DEFAULT '',
    telefone      VARCHAR(20)     NOT NULL DEFAULT '',
    email         VARCHAR(115)    NOT NULL DEFAULT '',
    added_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_list_cnpj (list_id, cnpj),
    INDEX idx_list   (list_id),
    INDEX idx_tenant (tenant_id),
    CONSTRAINT fk_cnpj_list FOREIGN KEY (list_id)
        REFERENCES cnpj_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
