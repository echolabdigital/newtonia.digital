<?php
/**
 * Newton IA — Outbound webhooks (Make / n8n / HERMES escutam eventos)
 *
 * Eventos suportados:
 *   - message.received     mensagem inbound chegou
 *   - message.sent         agente respondeu
 *   - conversation.started conversa criada
 *   - conversation.ended   conversa encerrada
 *   - handoff.requested    cliente pediu humano / agente travou
 *
 * Disparo: webhook_fire($tenantId, $event, $payload).
 * Faz POST best-effort (1 retry curto), nao bloqueia o fluxo principal.
 */

function webhook_fire(int $tenantId, string $event, array $payload): void {
    $hooks = db_all('SELECT * FROM outbound_webhooks WHERE tenant_id = ? AND active = 1', [$tenantId]);
    if (!$hooks) return;

    $body = json_encode([
        'event'     => $event,
        'tenant_id' => $tenantId,
        'data'      => $payload,
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE);

    foreach ($hooks as $h) {
        $events = array_map('trim', explode(',', $h['events'] ?? ''));
        if (!in_array($event, $events, true)) continue;

        $sig = hash_hmac('sha256', $body, $h['secret']);
        $t0  = microtime(true);

        $ch = curl_init($h['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Newton-Event: ' . $event,
                'X-Newton-Signature: sha256=' . $sig,
                'X-Newton-Delivery: ' . bin2hex(random_bytes(8)),
                'User-Agent: Newton-IA-Webhook/1.0',
            ],
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $latency = (int) ((microtime(true) - $t0) * 1000);

        db_insert('outbound_webhook_deliveries', [
            'webhook_id' => (int)$h['id'],
            'event'      => $event,
            'payload'    => $body,
            'status_code'=> $code,
            'response'   => $resp ? substr((string)$resp, 0, 4000) : null,
            'latency_ms' => $latency,
            'attempt'    => 1,
        ]);

        $ok = $code >= 200 && $code < 300;
        db_q(
            'UPDATE outbound_webhooks SET last_fired_at = NOW(), last_status = ?, fail_count = ' .
            ($ok ? '0' : 'fail_count + 1') . ' WHERE id = ?',
            [$code, (int)$h['id']]
        );
    }
}

function webhook_event_message_received(int $tenantId, array $conv, int $messageId, string $content): void {
    webhook_fire($tenantId, 'message.received', [
        'conversation_id' => (int)$conv['id'],
        'agent_id'        => (int)$conv['agent_id'],
        'message_id'      => $messageId,
        'contact'         => ['phone' => $conv['contact_phone'] ?? null, 'name' => $conv['contact_name'] ?? null],
        'content'         => $content,
    ]);
}

function webhook_event_message_sent(int $tenantId, array $conv, int $messageId, string $content, string $provider = '', string $model = ''): void {
    webhook_fire($tenantId, 'message.sent', [
        'conversation_id' => (int)$conv['id'],
        'agent_id'        => (int)$conv['agent_id'],
        'message_id'      => $messageId,
        'contact'         => ['phone' => $conv['contact_phone'] ?? null, 'name' => $conv['contact_name'] ?? null],
        'content'         => $content,
        'provider'        => $provider,
        'model'           => $model,
    ]);
}
