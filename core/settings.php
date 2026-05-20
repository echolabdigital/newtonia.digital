<?php
/**
 * Newton IA — System settings (chave-valor para configurações globais)
 * Usado pra armazenar API keys, webhooks tokens e outras integrações.
 *
 * Acesso: SOMENTE super-admin via UI (admin/integrations.php).
 * NUNCA expor settings com `is_secret` no front-end via fetch público.
 */

function setting_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    try {
        db_q("CREATE TABLE IF NOT EXISTS system_settings (
            skey VARCHAR(80) PRIMARY KEY,
            svalue TEXT,
            description VARCHAR(255),
            is_secret TINYINT DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    } catch (\Throwable $e) {}
}

// Pega um setting. Retorna $default se não existir.
function setting_get(string $key, $default = null) {
    setting_ensure_schema();
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $v = db_val('SELECT svalue FROM system_settings WHERE skey = ?', [$key]);
        $cache[$key] = $v ?? $default;
        return $cache[$key];
    } catch (\Throwable $e) {
        return $default;
    }
}

// Set um setting. $userId é opcional (pra trilha de auditoria).
function setting_set(string $key, ?string $value, ?int $userId = null, ?bool $isSecret = null): void
{
    setting_ensure_schema();
    $exists = (int) db_val('SELECT COUNT(*) FROM system_settings WHERE skey = ?', [$key]);
    if ($exists) {
        $sql = 'UPDATE system_settings SET svalue = ?, updated_by = ?' . ($isSecret !== null ? ', is_secret = ?' : '') . ' WHERE skey = ?';
        $params = $isSecret !== null ? [$value, $userId, (int)$isSecret, $key] : [$value, $userId, $key];
        db_q($sql, $params);
    } else {
        db_q(
            'INSERT INTO system_settings (skey, svalue, is_secret, updated_by) VALUES (?, ?, ?, ?)',
            [$key, $value, (int)($isSecret ?? 0), $userId]
        );
    }
}

// Lista todos os settings (opcional: filtrar por prefixo)
function setting_all(?string $prefix = null): array
{
    setting_ensure_schema();
    if ($prefix) {
        $rows = db_all(
            'SELECT skey, svalue, is_secret, description, updated_at FROM system_settings WHERE skey LIKE ? ORDER BY skey',
            [$prefix . '%']
        );
    } else {
        $rows = db_all('SELECT skey, svalue, is_secret, description, updated_at FROM system_settings ORDER BY skey');
    }
    return $rows;
}

// Mascara valor (mostra primeiros 8 caracteres + ...)
function setting_mask(?string $value): string
{
    if (!$value) return '';
    $len = strlen($value);
    if ($len <= 12) return str_repeat('•', $len);
    return substr($value, 0, 8) . str_repeat('•', max(0, $len - 12)) . substr($value, -4);
}

// Gera um token aleatório (pra webhook secrets)
function setting_gen_token(int $length = 40): string
{
    return bin2hex(random_bytes($length / 2));
}

// ─── Asaas helpers ──────────────────────────────────────────────────────────
function asaas_api_key(): ?string {
    return setting_get('asaas.api_key');
}
function asaas_env(): string {
    return setting_get('asaas.env', 'sandbox');
}
function asaas_base_url(): string {
    return asaas_env() === 'prod'
        ? 'https://api.asaas.com/v3'
        : 'https://sandbox.asaas.com/api/v3';
}
function asaas_webhook_token(): ?string {
    return setting_get('asaas.webhook_token');
}

// Faz uma request HTTP para Asaas. Retorna [http_code, body_decoded].
function asaas_request(string $method, string $path, array $body = null): array
{
    $key = asaas_api_key();
    if (!$key) return [0, ['error' => 'asaas.api_key not configured']];

    $url = asaas_base_url() . '/' . ltrim($path, '/');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'access_token: ' . $key,
            'User-Agent: NewtonIA/1.0',
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $resp  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $errstr = curl_error($ch);
    curl_close($ch);
    if ($errno) return [0, ['error' => 'curl: ' . $errstr]];
    $decoded = json_decode($resp, true);
    return [$code, $decoded ?: ['raw' => $resp]];
}
