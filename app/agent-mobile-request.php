<?php
/**
 * Newton IA — Solicita codigo SMS/voz para conectar instancia mobile Z-API
 * POST: channel_id, phone, method (sms|voice|wa_old)
 */
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/../core/zapi.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}
if (!csrf_check()) {
    echo json_encode(['ok' => false, 'error' => 'Sessao expirada. Recarregue.']);
    exit;
}

$tid       = (int) $tenant['id'];
$channelId = (int) ($_POST['channel_id'] ?? 0);
$phone     = preg_replace('/\D/', '', $_POST['phone'] ?? '');
$method    = in_array($_POST['method'] ?? '', ['sms', 'voice', 'wa_old']) ? $_POST['method'] : 'sms';

if (!$channelId || strlen($phone) < 10) {
    echo json_encode(['ok' => false, 'error' => 'channel/phone invalidos']);
    exit;
}

$channel = db_one('SELECT * FROM agent_channels WHERE id = ? AND tenant_id = ?', [$channelId, $tid]);
if (!$channel) {
    echo json_encode(['ok' => false, 'error' => 'canal nao encontrado']);
    exit;
}

$cfg = json_decode($channel['config_json'] ?? '{}', true);
if (empty($cfg['instance']) || empty($cfg['token']) || empty($cfg['client_token'])) {
    echo json_encode(['ok' => false, 'error' => 'credenciais Z-API incompletas — salve o canal primeiro']);
    exit;
}

// Remove DDI 55 se ja vier no input (Z-API quer ddi separado)
if (str_starts_with($phone, '55') && strlen($phone) === 13) {
    $phone = substr($phone, 2);
}

$result = zapi_mobile_request_code($cfg['instance'], $cfg['token'], $cfg['client_token'], $phone, $method);

if (!empty($result['success'])) {
    audit_log('agent.mobile_request_code', 'agent_channel', $channelId, ['phone' => $phone, 'method' => $method]);
    echo json_encode([
        'ok'         => true,
        'retryAfter' => $result['retryAfter'] ?? 60,
        'captcha'    => $result['captcha'] ?? null,
    ]);
} else {
    echo json_encode([
        'ok'    => false,
        'error' => $result['error'] ?? 'Z-API rejeitou a solicitacao. Verifique se a instancia e do tipo mobile.',
        'raw'   => $result,
    ]);
}
