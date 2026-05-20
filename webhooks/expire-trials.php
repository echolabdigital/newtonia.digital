<?php
/**
 * Newton IA — Cron: gerenciar ciclo de vida dos trials
 *
 * Executa 1× ao dia e faz duas passagens:
 *   1. Aviso: tenants que expiram em ~24h → e-mail de alerta
 *   2. Expirar: tenants com trial vencido sem pagamento → suspende + e-mail
 *
 * Crontab (06:00 UTC = 03:00 BRT):
 *   0 6 * * * php /home/newtonia.digital/app.newtonia.digital/public_html/cron/expire-trials.php >> /var/log/hermes-cron.log 2>&1
 */

if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}

define('TRIAL_GRACE_DAYS', 3);

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/emails.php';

$now   = date('Y-m-d H:i:s');
$label = "[{$now}] expire-trials";

echo "{$label} — iniciando\n";

// ─────────────────────────────────────────────────────────────────────────────
// PASSAGEM 1 — Aviso de expiry (24h antes)
// Tenants criados entre (GRACE-1) e GRACE dias atrás = expiram nas próximas 24h
// ─────────────────────────────────────────────────────────────────────────────
try {
    $expiring = db_all(
        "SELECT t.id, t.name,
                u.email, u.name AS user_name
         FROM tenants t
         JOIN tenant_users tu ON tu.tenant_id = t.id AND tu.role = 'owner'
         JOIN users u         ON u.id = tu.user_id
         WHERE t.status = 'pending'
           AND t.trial_warning_sent = 0
           AND COALESCE(t.trial_started_at, t.created_at)
               BETWEEN DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND DATE_SUB(NOW(), INTERVAL ? DAY)",
        [TRIAL_GRACE_DAYS, TRIAL_GRACE_DAYS - 1]   // entre 3 e 2 dias atrás → expira em <24h
    );

    foreach ($expiring as $row) {
        $tid = (int) $row['id'];
        try {
            [$subj, $body] = email_trial_expirando($row['user_name'], $row['name']);
            $sent = hermes_mail($row['email'], $subj, $body);
            db_q("UPDATE tenants SET trial_warning_sent = 1 WHERE id = ?", [$tid]);
            echo "  [AVISO] tenant #{$tid} ({$row['name']}) — warning enviado para {$row['email']}" . ($sent ? '' : ' (mail() falhou)') . "\n";
        } catch (\Throwable $e) {
            echo "  [AVISO-ERRO] tenant #{$tid} — {$e->getMessage()}\n";
        }
    }

    if (empty($expiring)) {
        echo "  [AVISO] nenhum trial expirando nas próximas 24h\n";
    }
} catch (\Throwable $e) {
    echo "{$label} — ERRO passagem 1: {$e->getMessage()}\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// PASSAGEM 2 — Suspender trials vencidos
// ─────────────────────────────────────────────────────────────────────────────
try {
    $candidates = db_all(
        "SELECT t.id, t.name,
                COALESCE(t.trial_started_at, t.created_at) AS trial_started,
                u.email, u.name AS user_name
         FROM tenants t
         LEFT JOIN tenant_users tu ON tu.tenant_id = t.id AND tu.role = 'owner'
         LEFT JOIN users u         ON u.id = tu.user_id
         WHERE t.status = 'pending'
           AND COALESCE(t.trial_started_at, t.created_at) < DATE_SUB(NOW(), INTERVAL ? DAY)",
        [TRIAL_GRACE_DAYS]
    );
} catch (\Throwable $e) {
    echo "{$label} — ERRO ao buscar candidatos: {$e->getMessage()}\n";
    exit(1);
}

if (empty($candidates)) {
    echo "  [EXPIRAR] nenhum trial vencido\n";
    echo "{$label} — concluído\n";
    exit(0);
}

echo "  [EXPIRAR] " . count($candidates) . " candidato(s) encontrado(s)\n";

$suspended = 0;
$skipped   = 0;

foreach ($candidates as $tenant) {
    $tid      = (int) $tenant['id'];
    $name     = $tenant['name'];
    $email    = $tenant['email'] ?? '';
    $username = $tenant['user_name'] ?? $name;

    // Pula se já tem pagamento confirmado
    $paid = (int) db_val(
        "SELECT COUNT(*) FROM asaas_payments WHERE tenant_id = ? AND status IN ('RECEIVED','CONFIRMED')",
        [$tid]
    );
    if ($paid > 0) {
        echo "  [SKIP] tenant #{$tid} ({$name}) — pagamento confirmado\n";
        $skipped++;
        continue;
    }

    // Pula se tem subscription ativa
    $active_sub = (int) db_val(
        "SELECT COUNT(*) FROM asaas_subscriptions WHERE tenant_id = ? AND status IN ('ACTIVE','PENDING')",
        [$tid]
    );
    if ($active_sub > 0) {
        echo "  [SKIP] tenant #{$tid} ({$name}) — subscription ativa\n";
        $skipped++;
        continue;
    }

    // Suspende
    try {
        db_q("UPDATE tenants SET status = 'suspended', suspended_at = NOW() WHERE id = ?", [$tid]);
        echo "  [SUSPENDED] tenant #{$tid} ({$name})\n";
        $suspended++;

        if ($email) {
            try {
                [$subj, $body] = email_trial_expirado($username, $name);
                hermes_mail($email, $subj, $body);
                echo "  [EMAIL] trial expirado → {$email}\n";
            } catch (\Throwable $e) {
                echo "  [EMAIL-ERRO] {$e->getMessage()}\n";
            }
        }
    } catch (\Throwable $e) {
        echo "  [ERRO] tenant #{$tid} — {$e->getMessage()}\n";
    }
}

echo "{$label} — concluído. Suspensos: {$suspended} | Ignorados: {$skipped}\n";
