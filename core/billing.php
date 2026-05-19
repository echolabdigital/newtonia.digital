<?php
/**
 * HERMES.b2b — Billing core
 * Camada que conecta tenants ↔ Asaas (customer, subscription, payment, lead packs).
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/plans.php';

function billing_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    try {
        // Mapeamento tenant ↔ customer no Asaas
        db_q("CREATE TABLE IF NOT EXISTS asaas_customers (
            tenant_id INT PRIMARY KEY,
            asaas_customer_id VARCHAR(40) NOT NULL,
            name VARCHAR(160),
            email VARCHAR(160),
            cpf_cnpj VARCHAR(20),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_asaas (asaas_customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Subscriptions ativas (1 por tenant)
        db_q("CREATE TABLE IF NOT EXISTS asaas_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            asaas_subscription_id VARCHAR(40) NOT NULL,
            plan_id INT NOT NULL,
            tier_code VARCHAR(20),
            billing_type VARCHAR(20),
            cycle VARCHAR(20),
            value_cents INT,
            next_due_date DATE NULL,
            status VARCHAR(30) DEFAULT 'ACTIVE',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_asaas_sub (asaas_subscription_id),
            INDEX idx_tenant (tenant_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Pagamentos individuais (recorrente + lead packs)
        db_q("CREATE TABLE IF NOT EXISTS asaas_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            asaas_payment_id VARCHAR(40) NOT NULL,
            asaas_subscription_id VARCHAR(40) NULL,
            kind VARCHAR(30) DEFAULT 'subscription',
            value_cents INT,
            billing_type VARCHAR(20),
            status VARCHAR(30),
            due_date DATE NULL,
            paid_date DATE NULL,
            invoice_url TEXT,
            bank_slip_url TEXT,
            pix_qr_code TEXT,
            payload JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_asaas_pay (asaas_payment_id),
            INDEX idx_tenant_status (tenant_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Lead packs (créditos comprados)
        db_q("CREATE TABLE IF NOT EXISTS lead_pack_credits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            asaas_payment_id VARCHAR(40) NULL,
            leads INT NOT NULL,
            value_cents INT,
            status VARCHAR(20) DEFAULT 'pending',
            granted_at TIMESTAMP NULL,
            expires_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant_status (tenant_id, status),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $done = true;
    } catch (\Throwable $e) {
        error_log('[billing schema] ' . $e->getMessage());
    }
}

// Atualiza CPF/CNPJ do customer no Asaas + local (necessário pra emitir cobrança)
function billing_update_customer_doc(int $tenantId, string $cpfCnpj): array
{
    billing_ensure_schema();
    $cpfCnpj = preg_replace('/\D/', '', $cpfCnpj);
    $len = strlen($cpfCnpj);
    if ($len !== 11 && $len !== 14) {
        return ['ok' => false, 'error' => 'CPF deve ter 11 dígitos ou CNPJ 14 dígitos'];
    }
    $customer = db_one('SELECT * FROM asaas_customers WHERE tenant_id = ?', [$tenantId]);
    if (!$customer) {
        // Cria customer agora (ainda não existia)
        $customer = billing_get_or_create_customer($tenantId);
        if (!$customer) return ['ok' => false, 'error' => 'falha ao criar customer no Asaas'];
    }
    // Atualiza no Asaas
    [$code, $body] = asaas_request('POST', '/customers/' . $customer['asaas_customer_id'], ['cpfCnpj' => $cpfCnpj]);
    if ($code < 200 || $code >= 300) {
        $err = $body['errors'][0]['description'] ?? 'falha ao atualizar customer no Asaas';
        return ['ok' => false, 'error' => $err, 'http' => $code];
    }
    // Atualiza local
    db_q('UPDATE asaas_customers SET cpf_cnpj = ? WHERE tenant_id = ?', [$cpfCnpj, $tenantId]);
    // Tenta atualizar a coluna cnpj do tenant (pode não existir — ignora)
    try { db_q('UPDATE tenants SET cnpj = ? WHERE id = ?', [$cpfCnpj, $tenantId]); } catch (\Throwable $e) {}
    return ['ok' => true];
}

// ─── Customer ────────────────────────────────────────────────────────────────
function billing_get_or_create_customer(int $tenantId): ?array
{
    billing_ensure_schema();
    $row = db_one('SELECT * FROM asaas_customers WHERE tenant_id = ?', [$tenantId]);
    if ($row) return $row;

    $tenant = db_one('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
    if (!$tenant) return null;

    // Cria customer no Asaas
    $payload = [
        'name'  => $tenant['name'] ?? $tenant['brand_name'] ?? 'Tenant ' . $tenantId,
        'email' => $tenant['email'] ?? null,
        'externalReference' => 'tenant_' . $tenantId,
    ];
    if (!empty($tenant['cnpj'])) {
        $doc = preg_replace('/\D/', '', $tenant['cnpj']);
        if (strlen($doc) === 11 || strlen($doc) === 14) {
            $payload['cpfCnpj'] = $doc;
        }
    }
    [$code, $body] = asaas_request('POST', '/customers', $payload);
    if ($code < 200 || $code >= 300) {
        error_log('[billing] asaas customer create failed: ' . json_encode($body));
        return null;
    }

    db_q(
        'INSERT INTO asaas_customers (tenant_id, asaas_customer_id, name, email)
         VALUES (?, ?, ?, ?)',
        [$tenantId, $body['id'], $body['name'], $body['email']]
    );
    return db_one('SELECT * FROM asaas_customers WHERE tenant_id = ?', [$tenantId]);
}

// ─── Subscription ────────────────────────────────────────────────────────────
function billing_active_subscription(int $tenantId): ?array
{
    billing_ensure_schema();
    return db_one(
        "SELECT * FROM asaas_subscriptions WHERE tenant_id = ? AND status IN ('ACTIVE','PENDING') ORDER BY id DESC LIMIT 1",
        [$tenantId]
    );
}

// Cria subscription no Asaas pra um tenant + plano.
// $billingType: CREDIT_CARD | BOLETO | PIX | UNDEFINED (cliente escolhe no checkout)
// $cycle: MONTHLY | YEARLY
function billing_create_subscription(int $tenantId, int $planId, string $billingType = 'UNDEFINED', string $cycle = 'MONTHLY'): array
{
    billing_ensure_schema();

    $plan = db_one('SELECT * FROM plans WHERE id = ?', [$planId]);
    if (!$plan) return ['ok' => false, 'error' => 'plano não encontrado'];
    if ((int)$plan['price_cents'] === 0) return ['ok' => false, 'error' => 'plano sem preço (trial?)'];

    $customer = billing_get_or_create_customer($tenantId);
    if (!$customer) return ['ok' => false, 'error' => 'falha ao criar customer no Asaas'];

    $value = $cycle === 'YEARLY' ? ($plan['annual_price_cents'] / 100) : ($plan['price_cents'] / 100);
    $nextDue = date('Y-m-d', strtotime('+3 days'));

    $payload = [
        'customer'    => $customer['asaas_customer_id'],
        'billingType' => $billingType,
        'value'       => $value,
        'nextDueDate' => $nextDue,
        'cycle'       => $cycle,
        'description' => 'HERMES.b2b — ' . $plan['name'] . ' (' . ($cycle === 'YEARLY' ? 'anual' : 'mensal') . ')',
        'externalReference' => 'tenant_' . $tenantId . '_plan_' . $planId,
    ];

    [$code, $body] = asaas_request('POST', '/subscriptions', $payload);
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => $body['errors'][0]['description'] ?? 'falha na API Asaas', 'http' => $code];
    }

    db_q(
        'INSERT INTO asaas_subscriptions (tenant_id, asaas_subscription_id, plan_id, tier_code, billing_type, cycle, value_cents, next_due_date, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $tenantId, $body['id'], $planId, $plan['tier_code'],
            $body['billingType'] ?? $billingType, $cycle,
            (int) round((float)$body['value'] * 100),
            $body['nextDueDate'] ?? $nextDue,
            $body['status'] ?? 'ACTIVE',
        ]
    );

    // Atualiza o tenant pra refletir o plano
    db_q('UPDATE tenants SET plan_id = ? WHERE id = ?', [$planId, $tenantId]);

    // Busca a 1ª cobrança gerada e armazena (pra mostrar invoice URL pro cliente)
    $first_payment = null;
    try {
        [$pcode, $pbody] = asaas_request('GET', '/subscriptions/' . $body['id'] . '/payments?limit=1');
        if ($pcode === 200 && !empty($pbody['data'][0])) {
            $first_payment = $pbody['data'][0];
            db_q(
                'INSERT INTO asaas_payments
                    (tenant_id, asaas_payment_id, asaas_subscription_id, kind, value_cents, billing_type, status, due_date, invoice_url, bank_slip_url, payload)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $tenantId,
                    $first_payment['id'],
                    $body['id'],
                    'subscription',
                    (int) round(((float)$first_payment['value']) * 100),
                    $first_payment['billingType'] ?? $billingType,
                    $first_payment['status'] ?? 'PENDING',
                    $first_payment['dueDate'] ?? null,
                    $first_payment['invoiceUrl'] ?? null,
                    $first_payment['bankSlipUrl'] ?? null,
                    json_encode($first_payment),
                ]
            );
        }
    } catch (\Throwable $e) {
        error_log('[billing] failed to fetch first payment: ' . $e->getMessage());
    }

    return ['ok' => true, 'subscription' => $body, 'first_payment' => $first_payment];
}

// Cobrança avulsa de lead pack
function billing_create_lead_pack_charge(int $tenantId, string $packCode, string $billingType = 'PIX'): array
{
    billing_ensure_schema();

    $packs = hermes_lead_packs();
    $pack = null;
    foreach ($packs as $p) if ($p['code'] === $packCode) { $pack = $p; break; }
    if (!$pack) return ['ok' => false, 'error' => 'pack inválido'];

    $customer = billing_get_or_create_customer($tenantId);
    if (!$customer) return ['ok' => false, 'error' => 'falha ao criar customer no Asaas'];

    $payload = [
        'customer'    => $customer['asaas_customer_id'],
        'billingType' => $billingType,
        'value'       => $pack['price_cents'] / 100,
        'dueDate'     => date('Y-m-d', strtotime('+1 day')),
        'description' => 'HERMES.b2b — Lead Pack ' . number_format($pack['leads'], 0, '.', '.') . ' leads',
        'externalReference' => 'tenant_' . $tenantId . '_pack_' . $packCode,
    ];

    [$code, $body] = asaas_request('POST', '/payments', $payload);
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => $body['errors'][0]['description'] ?? 'falha na API Asaas', 'http' => $code];
    }

    db_q(
        'INSERT INTO asaas_payments (tenant_id, asaas_payment_id, kind, value_cents, billing_type, status, due_date, invoice_url, bank_slip_url, payload)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $tenantId, $body['id'], 'lead_pack',
            (int) round((float)$body['value'] * 100),
            $body['billingType'] ?? $billingType,
            $body['status'] ?? 'PENDING',
            $body['dueDate'] ?? null,
            $body['invoiceUrl'] ?? null,
            $body['bankSlipUrl'] ?? null,
            json_encode($body),
        ]
    );

    // Cria registro do pack pending
    db_q(
        'INSERT INTO lead_pack_credits (tenant_id, asaas_payment_id, leads, value_cents, status, expires_at)
         VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 12 MONTH))',
        [$tenantId, $body['id'], $pack['leads'], $pack['price_cents'], 'pending']
    );

    return ['ok' => true, 'payment' => $body, 'pack' => $pack];
}

// Cancelar subscription
function billing_cancel_subscription(int $tenantId): array
{
    $sub = billing_active_subscription($tenantId);
    if (!$sub) return ['ok' => false, 'error' => 'sem subscription ativa'];

    [$code, $body] = asaas_request('DELETE', '/subscriptions/' . $sub['asaas_subscription_id']);
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => 'falha ao cancelar no Asaas'];
    }
    db_q("UPDATE asaas_subscriptions SET status = 'CANCELLED' WHERE id = ?", [$sub['id']]);
    return ['ok' => true];
}

// ─── Lead pack credits — saldo total ────────────────────────────────────────
function billing_lead_pack_balance(int $tenantId): int
{
    billing_ensure_schema();
    return (int) db_val(
        "SELECT COALESCE(SUM(leads), 0) FROM lead_pack_credits
         WHERE tenant_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())",
        [$tenantId]
    );
}

// Ativa pack após confirmação de pagamento
function billing_activate_lead_pack(string $asaasPaymentId): void
{
    billing_ensure_schema();
    db_q(
        "UPDATE lead_pack_credits SET status = 'active', granted_at = NOW()
         WHERE asaas_payment_id = ? AND status = 'pending'",
        [$asaasPaymentId]
    );
}
