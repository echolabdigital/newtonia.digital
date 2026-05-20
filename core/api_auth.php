<?php
/**
 * Newton IA — Autenticacao da API REST publica
 *
 * Formato da chave: nai_<32-hex>
 *   - prefixo "nai_" identifica plataforma
 *   - 32 hex chars = 128 bits de entropia
 *   - guardamos SHA-256 no banco (key nunca persiste em claro)
 *   - key_prefix = primeiros 12 chars (para exibir "nai_a1b2c3d4..." na UI)
 */

const API_KEY_PREFIX = 'nai_';

function api_key_generate(int $tenantId, string $name, array $scopes = [], ?int $userId = null): array {
    $raw       = API_KEY_PREFIX . bin2hex(random_bytes(16));
    $hash      = hash('sha256', $raw);
    $prefix    = substr($raw, 0, 12);
    $scopesCsv = $scopes ? implode(',', $scopes) : 'chat:read,chat:write,agents:read,conversations:read,flux:write,pulse:read,sonar:write';

    db_insert('api_keys', [
        'tenant_id'  => $tenantId,
        'name'       => $name,
        'key_hash'   => $hash,
        'key_prefix' => $prefix,
        'scopes'     => $scopesCsv,
        'created_by' => $userId,
    ]);

    return ['id' => (int)db()->lastInsertId(), 'key' => $raw, 'prefix' => $prefix];
}

function api_key_revoke(int $keyId, int $tenantId): bool {
    $st = db_q('UPDATE api_keys SET revoked_at = NOW() WHERE id = ? AND tenant_id = ? AND revoked_at IS NULL', [$keyId, $tenantId]);
    return $st->rowCount() > 0;
}

function api_key_list(int $tenantId): array {
    return db_all('SELECT id, name, key_prefix, scopes, last_used_at, last_used_ip, expires_at, revoked_at, created_at FROM api_keys WHERE tenant_id = ? ORDER BY id DESC', [$tenantId]);
}

/**
 * Valida o header Authorization e retorna [tenant, key_row] ou null.
 * Aceita: "Authorization: Bearer nai_xxxx"
 */
function api_key_validate(?string $rawHeader): ?array {
    if (!$rawHeader) return null;
    if (!preg_match('/Bearer\s+(' . preg_quote(API_KEY_PREFIX) . '[a-f0-9]{32})/i', $rawHeader, $m)) return null;

    $hash = hash('sha256', $m[1]);
    $key  = db_one('SELECT * FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL LIMIT 1', [$hash]);
    if (!$key) return null;
    if ($key['expires_at'] && strtotime($key['expires_at']) < time()) return null;

    $tenant = db_one('SELECT * FROM tenants WHERE id = ? AND status = "active" LIMIT 1', [(int)$key['tenant_id']]);
    if (!$tenant) return null;

    // Update "last used" (best-effort, sem bloquear)
    db_q('UPDATE api_keys SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?', [
        $_SERVER['REMOTE_ADDR'] ?? null, (int)$key['id']
    ]);

    return ['tenant' => $tenant, 'key' => $key];
}

function api_key_has_scope(array $key, string $scope): bool {
    $scopes = array_map('trim', explode(',', $key['scopes'] ?? ''));
    return in_array($scope, $scopes, true);
}

/**
 * Loga toda requisicao da API publica (audit + futuro rate-limit).
 */
function api_log(int $tenantId, ?int $keyId, string $endpoint, string $method, int $statusCode, int $latencyMs, ?string $error = null): void {
    db_insert('api_request_logs', [
        'tenant_id'   => $tenantId,
        'api_key_id'  => $keyId,
        'endpoint'    => $endpoint,
        'method'      => $method,
        'status_code' => $statusCode,
        'latency_ms'  => $latencyMs,
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        'error'       => $error ? substr($error, 0, 255) : null,
    ]);
}

/**
 * Rate-limit simples: max N requests/min por API key.
 * Retorna [allowed, remaining, reset_at].
 */
function api_rate_check(int $keyId, int $maxPerMin = 60): array {
    $count = (int) db_val(
        'SELECT COUNT(*) FROM api_request_logs WHERE api_key_id = ? AND created_at > NOW() - INTERVAL 1 MINUTE',
        [$keyId]
    );
    return ['allowed' => $count < $maxPerMin, 'remaining' => max(0, $maxPerMin - $count), 'limit' => $maxPerMin];
}
