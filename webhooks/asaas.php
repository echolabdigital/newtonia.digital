<?php
/**
 * Newton IA — Webhook receiver Asaas
 * URL pública: https://newtonia.digital/webhooks/asaas.php
 *
 * Fluxo:
 *  1. Asaas POST com JSON do evento
 *  2. Valida token via header `asaas-access-token`
 *  3. Dedup por event id (asaas_events table)
 *  4. Processa: ativa pack/subscription, marca pago, suspende tenant em overdue, etc.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/settings.php';
require_once __DIR__ . '/../core/billing.php';
require_once __DIR__ . '/../core/emails.php';

header('Content-Type: application/json');

// ── 1) Valida token ─────────────────────────────────────────────────────────
$expected_token = asaas_webhook_token();
$received_token = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '';

if (!$expected_token) {
    http_response_code(503);
    echo json_encode(['error' => 'webhook token not configured']);
    exit;
}
if (!hash_equals($expected_token, $received_token)) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid token']);
    error_log('[asaas webhook] invalid token from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    exit;
}

// ── 2) Lê payload ───────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid payload']);
    exit;
}

billing_ensure_schema();

// ── 3) Schema de eventos + dedup ────────────────────────────────────────────
try {
    db_q("CREATE TABLE IF NOT EXISTS asaas_events (
        id VARCHAR(40) PRIMARY KEY,
        event VARCHAR(80) NOT NULL,
        payment_id VARCHAR(40),
        subscription_id VARCHAR(40),
        customer_id VARCHAR(40),
        payload JSON,
        processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) DEFAULT 'received',
        INDEX idx_event (event),
        INDEX idx_payment (payment_id),
        INDEX idx_subscription (subscription_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

$event_id = $payload['id'] ?? null;
$event    = $payload['event'] ?? '';
$payment  = $payload['payment'] ?? [];
$subscription_id = $payment['subscription'] ?? null;
$customer_id     = $payment['customer'] ?? null;
$payment_id      = $payment['id'] ?? null;

if (!$event_id) {
    http_response_code(400);
    echo json_encode(['error' => 'missing event id']);
    exit;
}

// Já processado? Retorna 200 OK (Asaas considera entregue)
$already = (int) db_val('SELECT 1 FROM asaas_events WHERE id = ?', [$event_id]);
if ($already) {
    echo json_encode(['ok' => true, 'duplicate' => true]);
    exit;
}

// ── 4) Registra evento ──────────────────────────────────────────────────────
db_q(
    'INSERT INTO asaas_events (id, event, payment_id, subscription_id, customer_id, payload, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)',
    [$event_id, $event, $payment_id, $subscription_id, $customer_id, json_encode($payload), 'received']
);

// ── 5) Helper: descobre o tenant_id + dados do owner ─────────────────────────
$tenant_id   = null;
$tenant_name = '';
$owner_email = '';
$owner_name  = '';

if ($customer_id) {
    $tenant_id = (int) db_val('SELECT tenant_id FROM asaas_customers WHERE asaas_customer_id = ?', [$customer_id]);
}
if ($tenant_id) {
    $tenant_row = db_one('SELECT name FROM tenants WHERE id = ?', [$tenant_id]);
    $tenant_name = $tenant_row['name'] ?? '';
    $owner = db_one(
        'SELECT u.name, u.email FROM users u
         JOIN tenant_users tu ON tu.user_id = u.id
         WHERE tu.tenant_id = ? AND tu.role = "owner" LIMIT 1',
        [$tenant_id]
    );
    $owner_name  = $owner['name']  ?? '';
    $owner_email = $owner['email'] ?? '';
}

// ── 6) Atualiza/insere payment record (espelho da fatura) ───────────────────
if ($payment_id && $tenant_id) {
    $exists = (int) db_val('SELECT 1 FROM asaas_payments WHERE asaas_payment_id = ?', [$payment_id]);
    if ($exists) {
        db_q(
            "UPDATE asaas_payments SET
                status = ?, paid_date = ?, invoice_url = ?, bank_slip_url = ?, payload = ?
             WHERE asaas_payment_id = ?",
            [
                $payment['status'] ?? null,
                $payment['paymentDate'] ?? null,
                $payment['invoiceUrl'] ?? null,
                $payment['bankSlipUrl'] ?? null,
                json_encode($payment),
                $payment_id
            ]
        );
    } else {
        db_q(
            'INSERT INTO asaas_payments
                (tenant_id, asaas_payment_id, asaas_subscription_id, kind, value_cents, billing_type, status, due_date, paid_date, invoice_url, bank_slip_url, payload)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $tenant_id,
                $payment_id,
                $subscription_id,
                $subscription_id ? 'subscription' : 'lead_pack',
                (int) round(((float)($payment['value'] ?? 0)) * 100),
                $payment['billingType'] ?? null,
                $payment['status'] ?? null,
                $payment['dueDate'] ?? null,
                $payment['paymentDate'] ?? null,
                $payment['invoiceUrl'] ?? null,
                $payment['bankSlipUrl'] ?? null,
                json_encode($payment),
            ]
        );
    }
}

// ── 7) Processa o evento ────────────────────────────────────────────────────
$result_status = 'processed';
try {
    switch ($event) {
        case 'PAYMENT_CONFIRMED':
        case 'PAYMENT_RECEIVED':
            if ($tenant_id) {
                if ($subscription_id) {
                    $prev_status = db_val('SELECT status FROM tenants WHERE id = ?', [$tenant_id]);
                    db_q("UPDATE tenants SET status = 'active' WHERE id = ?", [$tenant_id]);
                    db_q("UPDATE asaas_subscriptions SET status = 'ACTIVE' WHERE asaas_subscription_id = ? AND tenant_id = ?",
                        [$subscription_id, $tenant_id]);

                    if ($owner_email) {
                        $plan_row  = db_one('SELECT p.name FROM plans p JOIN tenants t ON t.plan_id = p.id WHERE t.id = ?', [$tenant_id]);
                        $plan_name = $plan_row['name'] ?? 'seu plano';
                        $value_fmt = 'R$ ' . number_format(($payment['value'] ?? 0), 2, ',', '.');
                        $period    = ($payment['billingType'] ?? '') === 'YEARLY' ? 'anual' : 'mensal';
                        $inv_url   = $payment['invoiceUrl'] ?? '';
                        try {
                            if ($prev_status === 'suspended') {
                                // Reativação após suspensão — e-mail específico
                                [$subj, $body] = email_conta_reativada($owner_name, $plan_name);
                            } else {
                                [$subj, $body] = email_pagamento_confirmado($owner_name, $tenant_name, $plan_name, $value_fmt, $period, $inv_url);
                            }
                            hermes_mail($owner_email, $subj, $body);
                        } catch (\Throwable $e) { error_log('[webhook] email pagamento: ' . $e->getMessage()); }
                    }
                }
            }
            break;

        case 'PAYMENT_OVERDUE':
            if ($tenant_id && $subscription_id) {
                $days_overdue = (int) db_val(
                    "SELECT DATEDIFF(NOW(), due_date) FROM asaas_payments WHERE asaas_payment_id = ?",
                    [$payment_id]
                );

                // E-mail de fatura vencida (envia sempre que chegar o evento)
                if ($owner_email) {
                    $value_fmt = 'R$ ' . number_format(($payment['value'] ?? 0), 2, ',', '.');
                    $due_fmt   = isset($payment['dueDate'])
                        ? date('d/m/Y', strtotime($payment['dueDate']))
                        : 'data não informada';
                    $inv_url   = $payment['invoiceUrl'] ?? '';
                    try {
                        [$subj, $body] = email_fatura_vencida($owner_name, $tenant_name, $value_fmt, $due_fmt, $inv_url);
                        hermes_mail($owner_email, $subj, $body);
                    } catch (\Throwable $e) { error_log('[webhook] email overdue: ' . $e->getMessage()); }
                }

                if ($days_overdue >= 7) {
                    db_q("UPDATE tenants SET status = 'suspended' WHERE id = ?", [$tenant_id]);
                    db_q("UPDATE asaas_subscriptions SET status = 'OVERDUE' WHERE asaas_subscription_id = ?", [$subscription_id]);
                }
            }
            break;

        case 'PAYMENT_REFUNDED':
            if ($tenant_id) {
                if ($subscription_id) {
                    db_q("UPDATE tenants SET status = 'suspended' WHERE id = ?", [$tenant_id]);
                } else {
                    db_q("UPDATE lead_pack_credits SET status = 'refunded' WHERE asaas_payment_id = ?", [$payment_id]);
                }

                // E-mail de reembolso
                if ($owner_email) {
                    $value_fmt = 'R$ ' . number_format(($payment['value'] ?? 0), 2, ',', '.');
                    try {
                        [$subj, $body] = email_reembolso($owner_name, $value_fmt);
                        hermes_mail($owner_email, $subj, $body);
                    } catch (\Throwable $e) { error_log('[webhook] email refund: ' . $e->getMessage()); }
                }
            }
            break;

        case 'SUBSCRIPTION_INACTIVATED':
            if ($tenant_id && $subscription_id) {
                db_q("UPDATE asaas_subscriptions SET status = 'INACTIVE' WHERE asaas_subscription_id = ?", [$subscription_id]);
                db_q("UPDATE tenants SET status = 'cancelled' WHERE id = ?", [$tenant_id]);

                // E-mail de cancelamento
                if ($owner_email) {
                    $plan_row  = db_one('SELECT p.name FROM plans p JOIN tenants t ON t.plan_id = p.id WHERE t.id = ?', [$tenant_id]);
                    $plan_name = $plan_row['name'] ?? 'seu plano';
                    try {
                        [$subj, $body] = email_assinatura_cancelada($owner_name, $tenant_name, $plan_name);
                        hermes_mail($owner_email, $subj, $body);
                    } catch (\Throwable $e) { error_log('[webhook] email cancelled: ' . $e->getMessage()); }
                }
            }
            break;

        default:
            $result_status = 'ignored';
    }
} catch (\Throwable $e) {
    error_log('[asaas webhook] error processing ' . $event . ': ' . $e->getMessage());
    $result_status = 'error';
}

db_q("UPDATE asaas_events SET status = ? WHERE id = ?", [$result_status, $event_id]);
echo json_encode(['ok' => true, 'event' => $event, 'tenant_id' => $tenant_id, 'status' => $result_status]);
