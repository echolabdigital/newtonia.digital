<?php
/**
 * SYNAPSE — CRUD de agentes e canais.
 */

// ── Agentes ──────────────────────────────────────────────────────────────────

function agent_list(int $tenantId): array {
    return db_all(
        'SELECT a.*, 
                (SELECT COUNT(*) FROM agent_channels WHERE agent_id = a.id) AS channels,
                (SELECT COUNT(*) FROM conversations WHERE agent_id = a.id AND tenant_id = ?) AS conv_total,
                (SELECT COUNT(*) FROM conversations WHERE agent_id = a.id AND tenant_id = ? AND status = "open") AS conv_open
         FROM agents a WHERE a.tenant_id = ? ORDER BY a.created_at DESC',
        [$tenantId, $tenantId, $tenantId]
    );
}

function agent_get(int $id, int $tenantId): ?array {
    return db_one('SELECT * FROM agents WHERE id = ? AND tenant_id = ?', [$id, $tenantId]) ?: null;
}

function agent_create(int $tenantId, string $name, string $prompt, string $model = 'llama-3.3-70b-versatile'): int {
    db_q('INSERT INTO agents (tenant_id, name, prompt, model) VALUES (?, ?, ?, ?)',
        [$tenantId, $name, $prompt, $model]);
    return (int) db()->lastInsertId();
}

function agent_update(int $id, int $tenantId, array $data): void {
    $allowed = ['name', 'prompt', 'model', 'status', 'context_window'];
    $set = []; $params = [];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $data)) {
            $set[]    = "$k = ?";
            $params[] = $data[$k];
        }
    }
    if (!$set) return;
    $params[] = $id;
    $params[] = $tenantId;
    db_q('UPDATE agents SET ' . implode(', ', $set) . ' WHERE id = ? AND tenant_id = ?', $params);
}

function agent_delete(int $id, int $tenantId): void {
    db_q('DELETE FROM agent_channels WHERE agent_id = ?', [$id]);
    db_q('DELETE FROM agents WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
}

// ── Canais ───────────────────────────────────────────────────────────────────

function agent_channel_get(int $agentId): ?array {
    return db_one('SELECT * FROM agent_channels WHERE agent_id = ? LIMIT 1', [$agentId]) ?: null;
}

function agent_channel_save(int $agentId, int $tenantId, array $cfg): int {
    $existing = agent_channel_get($agentId);
    $token    = $existing['webhook_token'] ?? bin2hex(random_bytes(20));
    $cfgJson  = json_encode($cfg, JSON_UNESCAPED_UNICODE);

    if ($existing) {
        db_q('UPDATE agent_channels SET config_json = ?, webhook_token = ?, status = "disconnected", updated_at = NOW() WHERE id = ?',
            [$cfgJson, $token, (int)$existing['id']]);
        return (int)$existing['id'];
    }
    db_q('INSERT INTO agent_channels (agent_id, tenant_id, channel_type, config_json, webhook_token) VALUES (?, ?, "whatsapp_zapi", ?, ?)',
        [$agentId, $tenantId, $cfgJson, $token]);
    return (int) db()->lastInsertId();
}

function agent_channel_set_status(int $channelId, string $status, ?string $phone = null): void {
    $connectedAt = $status === 'connected' ? date('Y-m-d H:i:s') : null;
    db_q('UPDATE agent_channels SET status = ?, connected_phone = ?, connected_at = ? WHERE id = ?',
        [$status, $phone, $connectedAt, $channelId]);
}

// ── Conversas ────────────────────────────────────────────────────────────────

function synapse_get_or_create_conversation(int $agentId, int $tenantId, ?int $channelId, string $phone, string $name = ''): array {
    $conv = db_one(
        'SELECT * FROM conversations WHERE agent_id = ? AND tenant_id = ? AND contact_phone = ? AND status = "open" LIMIT 1',
        [$agentId, $tenantId, $phone]
    );
    if ($conv) return $conv;

    db_q('INSERT INTO conversations (agent_id, channel_id, tenant_id, contact_phone, contact_name, status) VALUES (?, ?, ?, ?, ?, "open")',
        [$agentId, $channelId, $tenantId, $phone, $name ?: $phone]);

    return db_one('SELECT * FROM conversations WHERE id = ?', [(int)db()->lastInsertId()]);
}

function synapse_save_message(int $convId, string $direction, string $content, string $type = 'text', ?string $zapiMsgId = null): int {
    db_q('INSERT INTO messages (conversation_id, direction, content, type, zapi_msg_id) VALUES (?, ?, ?, ?, ?)',
        [$convId, $direction, $content, $type, $zapiMsgId]);
    db_q('UPDATE conversations SET message_count = message_count + 1, last_message_at = NOW() WHERE id = ?', [$convId]);
    return (int) db()->lastInsertId();
}

function synapse_get_history(int $convId, int $limit = 20): array {
    $rows = db_all(
        'SELECT direction, content FROM messages WHERE conversation_id = ? ORDER BY sent_at DESC LIMIT ?',
        [$convId, $limit]
    );
    return array_reverse($rows);
}
