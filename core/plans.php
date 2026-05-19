<?php
/**
 * Newton IA — Planos e tiers
 * Mapeamento de colunas legadas:
 *   limit_cnpj_monthly   → limite de agentes
 *   limit_contacts       → limite de mensagens/mês
 *   limit_dispatch_daily → limite de canais WhatsApp
 */

function newton_plans_init(): void
{
    static $done = false;
    if ($done) return;

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

    $tenant_alters = [
        "ALTER TABLE tenants ADD COLUMN trial_started_at TIMESTAMP NULL DEFAULT NULL",
        "ALTER TABLE tenants ADD COLUMN suspended_at TIMESTAMP NULL DEFAULT NULL",
    ];
    foreach ($tenant_alters as $sql) {
        try { db_q($sql); } catch (\Throwable $e) { /* já existe */ }
    }

    foreach (newton_default_tiers() as $t) {
        $exists = (int) db_val('SELECT COUNT(*) FROM plans WHERE tier_code = ?', [$t['tier_code']]);
        if (!$exists) {
            try { db_insert('plans', $t); } catch (\Throwable $e) {
                error_log('[plans seed] ' . $e->getMessage());
            }
        }
    }

    $done = true;
}

// Alias para compatibilidade com billing.php existente
function plans_ensure_hermes_schema(): void { newton_plans_init(); }

function newton_default_tiers(): array
{
    return [
        [
            'tier_code'           => 'trial',
            'code'                => 'newton-trial',
            'name'                => 'Trial',
            'price_cents'         => 0,
            'annual_price_cents'  => 0,
            'limit_cnpj_monthly'  => 1,
            'limit_contacts'      => 500,
            'limit_dispatch_daily'=> 1,
            'users_limit'         => 1,
            'mail_self_limit'     => 0,
            'popular'             => 0,
            'visible_public'      => 0,
            'support_level'       => 'community',
            'trial_days'          => 7,
            'active'              => 1,
            'display_order'       => 0,
            'features'            => json_encode(['agents'=>1,'channels'=>1,'messages_monthly'=>500,'inbox'=>true,'api_access'=>false]),
        ],
        [
            'tier_code'           => 'starter',
            'code'                => 'newton-starter',
            'name'                => 'Starter',
            'price_cents'         => 19700,
            'annual_price_cents'  => 197000,
            'limit_cnpj_monthly'  => 3,
            'limit_contacts'      => 2000,
            'limit_dispatch_daily'=> 2,
            'users_limit'         => 2,
            'mail_self_limit'     => 0,
            'popular'             => 0,
            'visible_public'      => 1,
            'support_level'       => 'email',
            'trial_days'          => 0,
            'active'              => 1,
            'display_order'       => 1,
            'features'            => json_encode(['agents'=>3,'channels'=>2,'messages_monthly'=>2000,'inbox'=>true,'api_access'=>false]),
        ],
        [
            'tier_code'           => 'pro',
            'code'                => 'newton-pro',
            'name'                => 'Pro',
            'price_cents'         => 49700,
            'annual_price_cents'  => 497000,
            'limit_cnpj_monthly'  => 10,
            'limit_contacts'      => 8000,
            'limit_dispatch_daily'=> 5,
            'users_limit'         => 5,
            'mail_self_limit'     => 0,
            'popular'             => 1,
            'visible_public'      => 1,
            'support_level'       => 'whatsapp',
            'trial_days'          => 0,
            'active'              => 1,
            'display_order'       => 2,
            'features'            => json_encode(['agents'=>10,'channels'=>5,'messages_monthly'=>8000,'inbox'=>true,'api_access'=>true]),
        ],
        [
            'tier_code'           => 'business',
            'code'                => 'newton-business',
            'name'                => 'Business',
            'price_cents'         => 149700,
            'annual_price_cents'  => 1497000,
            'limit_cnpj_monthly'  => 999,
            'limit_contacts'      => 30000,
            'limit_dispatch_daily'=> 15,
            'users_limit'         => 15,
            'mail_self_limit'     => 0,
            'popular'             => 0,
            'visible_public'      => 1,
            'support_level'       => 'sla',
            'trial_days'          => 0,
            'active'              => 1,
            'display_order'       => 3,
            'features'            => json_encode(['agents'=>-1,'channels'=>15,'messages_monthly'=>30000,'inbox'=>true,'api_access'=>true]),
        ],
    ];
}

function newton_plans_list(bool $only_public = false): array
{
    newton_plans_init();
    $where = $only_public ? "WHERE visible_public = 1 AND active = 1 AND tier_code <> ''" : "WHERE tier_code <> ''";
    return db_all("SELECT * FROM plans $where ORDER BY display_order, id");
}

function newton_plan_by_code(string $tierCode): ?array
{
    newton_plans_init();
    return db_one("SELECT * FROM plans WHERE tier_code = ?", [$tierCode]);
}

function newton_plan_features(array $plan): array
{
    return json_decode($plan['features'] ?? '{}', true) ?: [];
}

function newton_plan_limit_agents(array $plan): int  { return (int)($plan['limit_cnpj_monthly'] ?? 1); }
function newton_plan_limit_channels(array $plan): int { return (int)($plan['limit_dispatch_daily'] ?? 1); }
function newton_plan_limit_messages(array $plan): int { return (int)($plan['limit_contacts'] ?? 500); }

function brl_cents_fmt(int $cents): string
{
    if ($cents === 0) return 'Grátis';
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

function support_label(string $level): string
{
    return [
        'community' => 'Comunidade',
        'email'     => 'E-mail (48h)',
        'whatsapp'  => 'WhatsApp prioritário',
        'sla'       => 'SLA + gerente de conta',
        'dedicated' => 'SLA dedicado',
    ][$level] ?? $level;
}
