<?php
/**
 * Newton IA — Bootstrap dos endpoints REST /api/v1/*
 * Carrega: config, CORS, parse JSON, autentica via Bearer token.
 *
 * Uso (no inicio de cada endpoint):
 *   require_once __DIR__ . '/_bootstrap.php';
 *   $ctx = api_boot('chat:write');   // exige scope
 *   // $ctx['tenant'], $ctx['key'], $ctx['body']
 */
require_once __DIR__ . '/../../config.php';

function api_boot(string $requiredScope = '', array $allowedMethods = ['GET','POST']): array {
    global $_api_t0;
    $_api_t0 = microtime(true);

    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Newton-Idempotency-Key');
    header('Cache-Control: no-store');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $allowedMethods, true)) api_fail(405, 'method_not_allowed', "Use: " . implode(', ', $allowedMethods));

    // Auth
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
    $ctx = api_key_validate($authHeader);
    if (!$ctx) api_fail(401, 'unauthorized', 'API key invalida ou ausente. Envie: Authorization: Bearer nai_xxx');

    if ($requiredScope && !api_key_has_scope($ctx['key'], $requiredScope)) {
        api_fail(403, 'insufficient_scope', "Esta chave nao possui o scope '$requiredScope'");
    }

    // Rate-limit
    $rate = api_rate_check((int)$ctx['key']['id']);
    header('X-RateLimit-Limit: ' . $rate['limit']);
    header('X-RateLimit-Remaining: ' . $rate['remaining']);
    if (!$rate['allowed']) api_fail(429, 'rate_limited', 'Rate limit atingido (60 req/min). Aguarde 1 minuto.');

    // Body JSON (opcional)
    $body = [];
    if (in_array($method, ['POST','PUT'], true)) {
        $raw  = file_get_contents('php://input');
        $body = $raw ? (json_decode($raw, true) ?? []) : [];
        if ($raw && !is_array($body)) api_fail(400, 'invalid_json', 'Body precisa ser JSON valido');
    }

    return ['tenant' => $ctx['tenant'], 'key' => $ctx['key'], 'body' => $body, 'method' => $method];
}

function api_ok(array $data, int $code = 200): void {
    api_finish($code, null);
    http_response_code($code);
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function api_fail(int $code, string $errorCode, string $message, array $extra = []): void {
    api_finish($code, $errorCode);
    http_response_code($code);
    echo json_encode(array_merge([
        'ok'    => false,
        'error' => $errorCode,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function api_finish(int $code, ?string $errorCode): void {
    global $_api_t0, $_api_ctx_logged;
    if ($_api_ctx_logged) return;
    $_api_ctx_logged = true;

    // Tenta logar (se ja temos contexto de tenant)
    $tenantId = $GLOBALS['_api_tenant_id'] ?? 0;
    $keyId    = $GLOBALS['_api_key_id'] ?? null;
    $endpoint = $_SERVER['REQUEST_URI'] ?? '';
    $endpoint = strtok($endpoint, '?');
    $endpoint = substr($endpoint, 0, 80);
    $latency  = (int) ((microtime(true) - ($_api_t0 ?: microtime(true))) * 1000);

    if ($tenantId) {
        @api_log($tenantId, $keyId, $endpoint, $_SERVER['REQUEST_METHOD'] ?? 'GET', $code, $latency, $errorCode);
    }
}

/**
 * Helper para encerrar fluxo registrando o tenant no log antes do exit.
 * Chame logo apos api_boot() para que erros tambem sejam logados.
 */
function api_track(array $ctx): void {
    $GLOBALS['_api_tenant_id'] = (int)$ctx['tenant']['id'];
    $GLOBALS['_api_key_id']    = (int)$ctx['key']['id'];
}
