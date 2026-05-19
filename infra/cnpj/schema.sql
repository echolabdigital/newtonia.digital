-- Newton CNPJ — PostgreSQL Schema
-- Receita Federal dados abertos (layout 2021+)

CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- ============================================================
-- Tabelas de referência
-- ============================================================

CREATE TABLE IF NOT EXISTS rf_cnaes (
    codigo    CHAR(7)      NOT NULL PRIMARY KEY,
    descricao VARCHAR(300) NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS rf_municipios (
    codigo    CHAR(7)      NOT NULL PRIMARY KEY,
    descricao VARCHAR(200) NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS rf_naturezas (
    codigo    CHAR(4)      NOT NULL PRIMARY KEY,
    descricao VARCHAR(200) NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS rf_qualificacoes (
    codigo    CHAR(2)      NOT NULL PRIMARY KEY,
    descricao VARCHAR(200) NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS rf_paises (
    codigo    CHAR(3)      NOT NULL PRIMARY KEY,
    descricao VARCHAR(100) NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS rf_motivos (
    codigo    CHAR(2)      NOT NULL PRIMARY KEY,
    descricao VARCHAR(200) NOT NULL DEFAULT ''
);

-- ============================================================
-- Empresas (CNPJ básico = 8 dígitos)
-- ============================================================

CREATE TABLE IF NOT EXISTS rf_empresas (
    cnpj_basico              CHAR(8)       NOT NULL PRIMARY KEY,
    razao_social             VARCHAR(300)  NOT NULL DEFAULT '',
    natureza_juridica        CHAR(4)       NOT NULL DEFAULT '',
    qualificacao_responsavel CHAR(2)       NOT NULL DEFAULT '',
    capital_social           NUMERIC(15,2) NOT NULL DEFAULT 0,
    porte_empresa            CHAR(2)       NOT NULL DEFAULT '00',
    ente_federativo          VARCHAR(200)  NOT NULL DEFAULT ''
);

-- ============================================================
-- Estabelecimentos (CNPJ completo = basico + ordem + dv)
-- ============================================================

CREATE TABLE IF NOT EXISTS rf_estabelecimentos (
    cnpj_basico               CHAR(8)      NOT NULL,
    cnpj_ordem                CHAR(4)      NOT NULL,
    cnpj_dv                   CHAR(2)      NOT NULL,
    identificador_mf          CHAR(1)      NOT NULL DEFAULT '1',
    nome_fantasia             VARCHAR(200) NOT NULL DEFAULT '',
    situacao_cadastral        CHAR(2)      NOT NULL DEFAULT '02',
    data_situacao_cadastral   DATE,
    motivo_situacao_cadastral CHAR(2)      NOT NULL DEFAULT '',
    nome_cidade_exterior      VARCHAR(100) NOT NULL DEFAULT '',
    pais                      CHAR(3)      NOT NULL DEFAULT '',
    data_inicio_atividade     DATE,
    cnae_principal            CHAR(7)      NOT NULL DEFAULT '',
    cnae_secundaria           TEXT         NOT NULL DEFAULT '',
    tipo_logradouro           VARCHAR(20)  NOT NULL DEFAULT '',
    logradouro                VARCHAR(300) NOT NULL DEFAULT '',
    numero                    VARCHAR(6)   NOT NULL DEFAULT '',
    complemento               VARCHAR(160) NOT NULL DEFAULT '',
    bairro                    VARCHAR(72)  NOT NULL DEFAULT '',
    cep                       CHAR(8)      NOT NULL DEFAULT '',
    uf                        CHAR(2)      NOT NULL DEFAULT '',
    municipio                 CHAR(7)      NOT NULL DEFAULT '',
    ddd1                      VARCHAR(4)   NOT NULL DEFAULT '',
    telefone1                 VARCHAR(9)   NOT NULL DEFAULT '',
    ddd2                      VARCHAR(4)   NOT NULL DEFAULT '',
    telefone2                 VARCHAR(9)   NOT NULL DEFAULT '',
    ddd_fax                   VARCHAR(4)   NOT NULL DEFAULT '',
    fax                       VARCHAR(9)   NOT NULL DEFAULT '',
    email                     VARCHAR(115) NOT NULL DEFAULT '',
    situacao_especial         VARCHAR(100) NOT NULL DEFAULT '',
    data_situacao_especial    DATE,
    PRIMARY KEY (cnpj_basico, cnpj_ordem, cnpj_dv)
);

-- ============================================================
-- Sócios
-- ============================================================

CREATE TABLE IF NOT EXISTS rf_socios (
    id                         BIGSERIAL    PRIMARY KEY,
    cnpj_basico                CHAR(8)      NOT NULL,
    identificador_socio        CHAR(1)      NOT NULL DEFAULT '2',
    nome_socio                 VARCHAR(300) NOT NULL DEFAULT '',
    cnpj_cpf_socio             VARCHAR(14)  NOT NULL DEFAULT '',
    qualificacao_socio         CHAR(2)      NOT NULL DEFAULT '',
    data_entrada_sociedade     DATE,
    pais                       CHAR(3)      NOT NULL DEFAULT '',
    representante_legal        CHAR(11)     NOT NULL DEFAULT '',
    nome_representante         VARCHAR(100) NOT NULL DEFAULT '',
    qualificacao_representante CHAR(2)      NOT NULL DEFAULT '',
    faixa_etaria               CHAR(1)      NOT NULL DEFAULT '0'
);

-- ============================================================
-- Simples Nacional / MEI
-- ============================================================

CREATE TABLE IF NOT EXISTS rf_simples (
    cnpj_basico           CHAR(8) NOT NULL PRIMARY KEY,
    opcao_simples         CHAR(1) NOT NULL DEFAULT 'N',
    data_opcao_simples    DATE,
    data_exclusao_simples DATE,
    opcao_mei             CHAR(1) NOT NULL DEFAULT 'N',
    data_opcao_mei        DATE,
    data_exclusao_mei     DATE
);

-- ============================================================
-- Índices para queries de prospecção
-- ============================================================

CREATE INDEX IF NOT EXISTS idx_est_uf            ON rf_estabelecimentos(uf);
CREATE INDEX IF NOT EXISTS idx_est_municipio     ON rf_estabelecimentos(municipio);
CREATE INDEX IF NOT EXISTS idx_est_situacao      ON rf_estabelecimentos(situacao_cadastral);
CREATE INDEX IF NOT EXISTS idx_est_cnae          ON rf_estabelecimentos(cnae_principal);
CREATE INDEX IF NOT EXISTS idx_est_uf_mun_cnae   ON rf_estabelecimentos(uf, municipio, cnae_principal);
CREATE INDEX IF NOT EXISTS idx_est_identificador ON rf_estabelecimentos(identificador_mf);
CREATE INDEX IF NOT EXISTS idx_est_abertura      ON rf_estabelecimentos(data_inicio_atividade);
CREATE INDEX IF NOT EXISTS idx_est_email         ON rf_estabelecimentos(email) WHERE email <> '';
CREATE INDEX IF NOT EXISTS idx_est_tel           ON rf_estabelecimentos(ddd1, telefone1) WHERE telefone1 <> '';
CREATE INDEX IF NOT EXISTS idx_emp_porte         ON rf_empresas(porte_empresa);
CREATE INDEX IF NOT EXISTS idx_socios_cnpj       ON rf_socios(cnpj_basico);

-- Full-text search via trigram
CREATE INDEX IF NOT EXISTS idx_emp_razao_trgm    ON rf_empresas USING GIN (razao_social gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_est_fantasia_trgm ON rf_estabelecimentos USING GIN (nome_fantasia gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_cnaes_desc_trgm   ON rf_cnaes USING GIN (descricao gin_trgm_ops);
