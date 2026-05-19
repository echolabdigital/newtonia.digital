<?php
/**
 * HERMES.b2b — Tenant Feature Flags
 * Controla quais módulos cada tenant pode ver/usar.
 * Super-admin (Echo_Lab) tem todas as flags ativas no tenant dele;
 * Outros tenants têm flags conforme plano + overrides manuais.
 */

function tenant_features_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    try {
        db_q("CREATE TABLE IF NOT EXISTS tenant_features (
            tenant_id INT NOT NULL,
            feature VARCHAR(40) NOT NULL,
            enabled TINYINT DEFAULT 1,
            override TINYINT DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT NULL,
            PRIMARY KEY (tenant_id, feature),
            INDEX idx_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    } catch (\Throwable $e) {}
}

// Lista de todas as features rastreáveis do HERMES.b2b
function hermes_all_features(): array
{
    return [
        'pipeline'    => ['label' => 'Pipeline (CRM Kanban)',       'default_plans' => ['trial','starter','pro','business']],
        'mail_lab'    => ['label' => 'Mail Lab (compose)',          'default_plans' => ['trial','starter','pro','business']],
        'radar'       => ['label' => 'Radar Leads (prospecção)',    'default_plans' => ['trial','starter','pro','business']],
        'signal'      => ['label' => 'Signal (WhatsApp dispatch)',  'default_plans' => ['business']],          // só Business beta
        'whats_lab'   => ['label' => 'Whats Lab (Newton IA)',       'default_plans' => ['business']],
        'pitch'       => ['label' => 'Pitch (SPIN scripts)',        'default_plans' => ['business']],
        'api_rest'    => ['label' => 'API REST pública',            'default_plans' => ['business']],
        'mail_lab_managed' => ['label' => 'Mail Lab Managed (Echo_Lab cuida)', 'default_plans' => []], // add-on
    ];
}

// Detecta o tier_code do tenant. Super-admin sempre tem TUDO.
function tenant_get_tier_code(int $tenantId): ?string
{
    $row = db_one(
        "SELECT p.tier_code FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id WHERE t.id = ?",
        [$tenantId]
    );
    return $row['tier_code'] ?? null;
}

// Verifica se um tenant tem uma feature liberada
function tenant_has_feature(int $tenantId, string $feature): bool
{
    tenant_features_ensure_schema();

    // Super-admin acessando o seu próprio tenant: tudo liberado
    if (function_exists('auth_is_super') && auth_is_super()) {
        // Se está logado como super-admin e está no tenant Echo_Lab (ou qualquer),
        // libera tudo no seu acesso pra testar features em WIP
        return true;
    }

    // Override manual (admin marcou em tenant-detail)
    $override = db_one(
        "SELECT enabled FROM tenant_features WHERE tenant_id = ? AND feature = ? AND override = 1",
        [$tenantId, $feature]
    );
    if ($override) return (int)$override['enabled'] === 1;

    // Default: olha o plano do tenant
    $tier = tenant_get_tier_code($tenantId);
    if (!$tier) return false;

    $features = hermes_all_features();
    if (!isset($features[$feature])) return false;

    return in_array($tier, $features[$feature]['default_plans'], true);
}

// Lista todas as features liberadas pra um tenant (cache friendly)
function tenant_features_for(int $tenantId): array
{
    tenant_features_ensure_schema();
    static $cache = [];
    if (isset($cache[$tenantId])) return $cache[$tenantId];

    $out = [];
    foreach (array_keys(hermes_all_features()) as $f) {
        $out[$f] = tenant_has_feature($tenantId, $f);
    }
    $cache[$tenantId] = $out;
    return $out;
}

// Setter manual (override)
function tenant_set_feature(int $tenantId, string $feature, bool $enabled, ?int $userId = null): void
{
    tenant_features_ensure_schema();
    db_q(
        "INSERT INTO tenant_features (tenant_id, feature, enabled, override, updated_by)
         VALUES (?, ?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), override = 1, updated_by = VALUES(updated_by)",
        [$tenantId, $feature, $enabled ? 1 : 0, $userId]
    );
}

// Remove override (volta a usar default do plano)
function tenant_clear_feature_override(int $tenantId, string $feature): void
{
    tenant_features_ensure_schema();
    db_q("DELETE FROM tenant_features WHERE tenant_id = ? AND feature = ?", [$tenantId, $feature]);
}
