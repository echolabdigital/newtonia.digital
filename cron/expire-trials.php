<?php
/**
 * Newton IA — Cron: gerenciamento de trials
 *
 * 1. Avisa 1 dia antes de expirar   → email_trial_expirando()
 * 2. Expira e suspende ao vencer     → email_trial_expirado()
 *
 * Crontab:
 *   0 8 * * * php /home/newtonia.digital/public_html/cron/expire-trials.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/emails.php';

$now = date('Y-m-d H:i:s');
$ok = 0; $warned = 0;

// ── 1) Aviso 1 dia antes de expirar ──────────────────────────────────────────
$expiring_soon = db_all(
    "SELECT t.id, t.name, t.email, t.trial_started_at, p.trial_days
     FROM tenants t
     JOIN plans p ON p.id = t.plan_id
     WHERE t.status = 'trial'
       AND p.trial_days > 0
       AND t.trial_started_at IS NOT NULL
       AND DATE(TIMESTAMPADD(DAY, p.trial_days, t.trial_started_at)) = DATE(NOW() + INTERVAL 1 DAY)
       AND NOT EXISTS (
           SELECT 1 FROM user_preferences up
           JOIN tenant_users tu ON tu.user_id = up.user_id
           WHERE tu.tenant_id = t.id AND up.pref_key = 'trial_warning_sent' AND up.pref_value = '1'
       )"
);

foreach ($expiring_soon as $tenant) {
    if (!$tenant['email']) continue;
    try {
        [$subj, $body] = email_trial_expirando($tenant['name'], 1);
        hermes_mail($tenant['email'], $subj, $body);

        // Marca que o aviso foi enviado (via owner)
        $owner_id = (int) db_val(
            'SELECT user_id FROM tenant_users WHERE tenant_id = ? AND role = "owner" LIMIT 1',
            [$tenant['id']]
        );
        if ($owner_id) {
            db_q(
                'INSERT INTO user_preferences (user_id, pref_key, pref_value) VALUES (?, "trial_warning_sent", "1")
                 ON DUPLICATE KEY UPDATE pref_value = "1"',
                [$owner_id]
            );
        }

        audit_log(0, (int)$tenant['id'], 'trial_warning_sent', 'tenant', (string)$tenant['id'], null);
        echo "[{$now}] Aviso enviado: tenant #{$tenant['id']} — {$tenant['name']}\n";
        $warned++;
    } catch (\Throwable $e) {
        error_log('[cron] aviso trial tenant #' . $tenant['id'] . ': ' . $e->getMessage());
    }
}

// ── 2) Expirar trials vencidos ────────────────────────────────────────────────
$expired = db_all(
    "SELECT t.id, t.name, t.email, t.trial_started_at, p.trial_days
     FROM tenants t
     JOIN plans p ON p.id = t.plan_id
     WHERE t.status = 'trial'
       AND p.trial_days > 0
       AND t.trial_started_at IS NOT NULL
       AND TIMESTAMPADD(DAY, p.trial_days, t.trial_started_at) < NOW()"
);

foreach ($expired as $tenant) {
    db_q("UPDATE tenants SET status = 'expired', suspended_at = NOW() WHERE id = ?", [$tenant['id']]);
    audit_log(0, (int)$tenant['id'], 'trial_expired', 'tenant', (string)$tenant['id'],
        json_encode(['trial_days' => $tenant['trial_days'], 'started' => $tenant['trial_started_at']]));

    if ($tenant['email']) {
        try {
            [$subj, $body] = email_trial_expirado($tenant['name']);
            hermes_mail($tenant['email'], $subj, $body);
        } catch (\Throwable $e) {
            error_log('[cron] email trial expirado tenant #' . $tenant['id'] . ': ' . $e->getMessage());
        }
    }

    echo "[{$now}] Trial expirado: tenant #{$tenant['id']} — {$tenant['name']}\n";
    $ok++;
}

echo "[{$now}] Cron finalizado: {$ok} expirado(s), {$warned} aviso(s) enviado(s).\n";
