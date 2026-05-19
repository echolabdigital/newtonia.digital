<?php
/**
 * HERMES.b2b — Plans / Pricing tiers
 * Reaproveita a tabela `plans` (legada), expande colunas e faz seed dos 4 tiers HERMES.
 */

function plans_ensure_hermes_schema(): void
{
    static $done = false;
    if ($done) return;

    // 1) Garante a tabela `plans` com schema completo (cria do zero se não existir)
    try {
        db_q("CREATE TABLE IF NOT EXISTS plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tier_code VARCHAR(20) NOT NULL DEFAULT '',
            code VARCHAR(60) NOT NULL DEFAULT '',
            name VARCHAR(80) NOT NULL DEFAULT '',
            price_cents INT NOT NULL DEFAULT 0,
            annual_price_cents INT DEFAULT 0,
            limit_dispatch_daily INT DEFAULT 0,
            limit_contacts INT DEFAULT 0,
            limit_extractor_monthly INT DEFAULT 0,
            limit_cnpj_monthly INT DEFAULT 0,
            users_limit INT DEFAULT 1,
            mail_self_limit INT DEFAULT 1,
            popular TINYINT DEFAULT 0,
            visible_public TINYINT DEFAULT 1,
            support_level VARCHAR(20) DEFAULT 'email',
            trial_days INT DEFAULT 0,
            features TEXT,
            active TINYINT DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_tier (tier_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {
        error_log('[plans CREATE] ' . $e->getMessage());
    }

    // 2) Garante TODAS as colunas (idempotente — ignora se já existir)
    // Cobre tanto o caso de tabela legada quanto schema incompleto
    $alters = [
        "ALTER TABLE plans ADD COLUMN tier_code VARCHAR(20) NOT NULL DEFAULT ''",
        "ALTER TABLE plans ADD COLUMN code VARCHAR(60) NOT NULL DEFAULT ''",
        "ALTER TABLE plans ADD COLUMN name VARCHAR(80) NOT NULL DEFAULT ''",
        "ALTER TABLE plans ADD COLUMN price_cents INT NOT NULL DEFAULT 0",
        "ALTER TABLE plans ADD COLUMN annual_price_cents INT DEFAULT 0",
        "ALTER TABLE plans ADD COLUMN limit_dispatch_daily INT DEFAULT 0",
        "ALTER TABLE plans ADD COLUMN limit_contacts INT DEFAULT 0",
        "ALTER TABLE plans ADD COLUMN limit_extractor_monthly INT DEFAULT 0",
        "ALTER TABLE plans ADD COLUMN limit_cnpj_monthly INT DEFAULT 0",
        "ALTER TABLE plans ADD COLUMN users_limit INT DEFAULT 1",
        "ALTER TABLE plans ADD COLUMN mail_self_limit INT DEFAULT 1",
        "ALTER TABLE plans ADD COLUMN popular TINYINT DEFAULT 0",
        "ALTER TABLE plans ADD COLUMN visible_public TINYINT DEFAULT 1",
        "ALTER TABLE plans ADD COLUMN support_level VARCHAR(20) DEFAULT 'email'",
        "ALTER TABLE plans ADD COLUMN trial_days INT DEFAULT 0",
        "ALTER TABLE plans ADD COLUMN features TEXT",
        "ALTER TABLE plans ADD COLUMN active TINYINT DEFAULT 1",
        "ALTER TABLE plans ADD COLUMN display_order INT DEFAULT 0",
        "ALTER TABLE plans ADD UNIQUE KEY uniq_tier (tier_code)",
    ];
    foreach ($alters as $sql) {
        try { db_q($sql); } catch (\Throwable $e) { /* já existe — OK */ }
    }

    // 2b) Garante colunas de lifecycle em tenants (trial + suspensão)
    $tenant_alters = [
        "ALTER TABLE tenants ADD COLUMN trial_started_at TIMESTAMP NULL DEFAULT NULL",
        "ALTER TABLE tenants ADD COLUMN suspended_at TIMESTAMP NULL DEFAULT NULL",
    ];
    foreach ($tenant_alters as $sql) {
        try { db_q($sql); } catch (\Throwable $e) { /* já existe — OK */ }
    }

    // 3) Seed dos 4 tiers HERMES (insere apenas se tier_code não existir)
    $tiers = hermes_default_tiers();
    foreach ($tiers as $t) {
        $exists = (int) db_val('SELECT COUNT(*) FROM plans WHERE tier_code = ?', [$t['tier_code']]);
        if (!$exists) {
            try {
                db_insert('plans', array_merge($t, ['features' => json_encode([])]));
            } catch (\Throwable $e) {
                error_log('[plans seed] ' . $e->getMessage());
            }
        }
    }
    $done = true;
}

// Definições oficiais dos 4 tiers HERMES.b2b (valores em centavos)
function hermes_default_tiers(): array
{
    return [
        [
            'tier_code'         => 'trial',
            'code'              => 'hermes-trial',
            'name'              => 'Trial',
            'price_cents'       => 0,
            'annual_price_cents'=> 0,
            'users_limit'       => 1,
            'limit_contacts'    => 100,    // Pipeline cards
            'limit_cnpj_monthly'=> 30,     // Radar extrações
            'mail_self_limit'   => 1,
            'popular'           => 0,
            'visible_public'    => 0,
            'support_level'     => 'community',
            'trial_days'        => 3,
            'active'            => 1,
            'display_order'     => 0,
        ],
        [
            'tier_code'         => 'starter',
            'code'              => 'hermes-starter',
            'name'              => 'Starter',
            'price_cents'       => 14700,   // R$ 147,00
            'annual_price_cents'=> 147000,  // R$ 1.470,00
            'users_limit'       => 1,
            'limit_contacts'    => 300,
            'limit_cnpj_monthly'=> 50,
            'mail_self_limit'   => 1,
            'popular'           => 0,
            'visible_public'    => 1,
            'support_level'     => 'email',
            'trial_days'        => 0,
            'active'            => 1,
            'display_order'     => 1,
        ],
        [
            'tier_code'         => 'pro',
            'code'              => 'hermes-pro',
            'name'              => 'Pro',
            'price_cents'       => 39700,   // R$ 397,00
            'annual_price_cents'=> 397000,  // R$ 3.970,00
            'users_limit'       => 3,
            'limit_contacts'    => 2000,
            'limit_cnpj_monthly'=> 200,
            'mail_self_limit'   => 3,
            'popular'           => 1,
            'visible_public'    => 1,
            'support_level'     => 'whatsapp',
            'trial_days'        => 0,
            'active'            => 1,
            'display_order'     => 2,
        ],
        [
            'tier_code'         => 'business',
            'code'              => 'hermes-business',
            'name'              => 'Business',
            'price_cents'       => 79700,   // R$ 797,00
            'annual_price_cents'=> 797000,  // R$ 7.970,00
            'users_limit'       => 10,
            'limit_contacts'    => 10000,
            'limit_cnpj_monthly'=> 500,
            'mail_self_limit'   => 10,
            'popular'           => 0,
            'visible_public'    => 1,
            'support_level'     => 'sla',
            'trial_days'        => 0,
            'active'            => 1,
            'display_order'     => 3,
        ],
    ];
}

// Lead packs (não vão pra tabela `plans` — são one-time purchases)
function hermes_lead_packs(): array
{
    return [
        ['code' => 'pack-100',   'leads' => 100,   'price_cents' => 9700,    'per_lead' => 0.97],
        ['code' => 'pack-500',   'leads' => 500,   'price_cents' => 39700,   'per_lead' => 0.79],
        ['code' => 'pack-1000',  'leads' => 1000,  'price_cents' => 69700,   'per_lead' => 0.70],
        ['code' => 'pack-5000',  'leads' => 5000,  'price_cents' => 279700,  'per_lead' => 0.56],
    ];
}

// Buscar planos HERMES (ordenados)
function hermes_plans_list(bool $only_public = false): array
{
    plans_ensure_hermes_schema();
    $where = $only_public ? "WHERE visible_public = 1 AND active = 1 AND tier_code <> ''" : "WHERE tier_code <> ''";
    return db_all("SELECT * FROM plans $where ORDER BY display_order, id");
}

function hermes_plan_by_code(string $tierCode): ?array
{
    plans_ensure_hermes_schema();
    return db_one("SELECT * FROM plans WHERE tier_code = ?", [$tierCode]);
}

// Formatador de preço
function brl_cents_fmt(int $cents): string
{
    if ($cents === 0) return 'Grátis';
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

// Label do support level
function support_label(string $level): string
{
    return [
        'community' => 'Comunidade',
        'email'     => 'E-mail (48h)',
        'whatsapp'  => 'WhatsApp prioritário (8h úteis)',
        'sla'       => 'SLA + gerente conta',
        'dedicated' => 'SLA dedicado',
    ][$level] ?? $level;
}
