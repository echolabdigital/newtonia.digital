<?php
/**
 * Newton IA — Cron: expirar trials vencidos
 * Executar via crontab:
 *   0 3 * * * php /home/newtonia.digital/public_html/cron/expire-trials.php
 */
require_once __DIR__ . '/../config.php';

$now = date('Y-m-d H:i:s');

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
        [$subj, $body] = email_conta_suspensa($tenant['name']);
        hermes_mail($tenant['email'], $subj, $body);
    }

    echo "[{$now}] Trial expirado: tenant #{$tenant['id']} — {$tenant['name']}\n";
}

echo "[{$now}] Cron finalizado. " . count($expired) . " trial(s) expirado(s).\n";
