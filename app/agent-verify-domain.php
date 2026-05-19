<?php
/**
 * Verifica se um dominio esta servindo o widget deste agente.
 * GET /app/agent-verify-domain.php?id=AGENT_ID&domain=meusite.com
 */
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();

header('Content-Type: application/json');

$agentId = (int)($_GET['id'] ?? 0);
$domain  = trim($_GET['domain'] ?? '');

if (!$agentId || !$domain) {
    echo json_encode(['found' => false, 'error' => 'parametros invalidos']); exit;
}

$agent = agent_get($agentId, (int)$tenant['id']);
if (!$agent) {
    echo json_encode(['found' => false, 'error' => 'agente nao encontrado']); exit;
}

$token = $agent['embed_token'] ?? '';
if (!$token) {
    echo json_encode(['found' => false, 'error' => 'token de embed nao gerado']); exit;
}

// Normalize domain
$domain = preg_replace('#^https?://#', '', $domain);
$domain = rtrim($domain, '/');
if (!str_starts_with($domain, 'http')) {
    $targetUrl = 'https://' . $domain;
} else {
    $targetUrl = $domain;
}

$ch = curl_init($targetUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT      => 'NewtonIA-WidgetVerifier/1.0',
    CURLOPT_HTTPHEADER     => ['Accept: text/html'],
]);
$html = curl_exec($ch);
$err  = curl_error($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err || $code < 200 || $code >= 400) {
    echo json_encode(['found' => false, 'error' => 'nao foi possivel acessar o dominio (HTTP ' . $code . ')']); exit;
}

// Check for widget.js script tag with this agent's token
$found = $html && (
    str_contains($html, 'widget.js?agent=' . $token) ||
    str_contains($html, 'widget.js?agent=' . urlencode($token))
);

echo json_encode(['found' => $found, 'http' => $code]);
