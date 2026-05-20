<?php
/**
 * Newton IA — Confirma codigo SMS/voz da instancia mobile Z-API
 * POST: channel_id, code
 */
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/../core/zapi.php';
require_once __DIR__ . '/../core/agent.php';

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
$code      = preg_replace('/\D/', '', $_POST['code'] ?? '');

if (!$channelId || strlen($code) !== 6) {
    echo json_encode(['ok' => false, 'error' => 'channel/code invalidos']);
    exit;
}

$channel = db_one('SELECT * FROM agent_channels WHERE id = ? AND tenant_id = ?', [$channelId, $tid]);
if (!$channel) {
    echo json_encode(['ok' => false, 'error' => 'canal nao encontrado']);
    exit;
}

$cfg = json_decode($channel['config_json'] ?? '{}', true);
if (empty($cfg['instance']) || empty($cfg['token']) || empty($cfg['client_token'])) {
    echo json_encode(['ok' => false, 'error' => 'credenciais Z-API incompletas']);
    exit;
}

$result = zapi_mobile_confirm_code($cfg['instance'], $cfg['token'], $cfg['client_token'], $code);

if (!empty($result['success'])) {
    // Atualiza status (o webhook 'connect' tambem disparara quando a Z-API confirmar)
    $status = zapi_get_status($cfg['instance'], $cfg['token'], $cfg['client_token']);
    agent_channel_set_status($channelId, $status['connected'] ? 'connected' : 'disconnected', $status['phone'] ?? null);

    audit_log('agent.mobile_confirm_code', 'agent_channel', $channelId, ['phone' => $status['phone'] ?? null]);

    echo json_encode([
        'ok'                  => true,
        'connected'           => $status['connected'] ?? false,
        'phone'               => $status['phone']     ?? null,
        'confirmSecurityCode' => $result['confirmSecurityCode'] ?? false,
        'deviceConfirm'       => $result['deviceConfirm']       ?? false,
    ]);
} else {
    echo json_encode([
        'ok'                  => false,
        'error'               => $result['error'] ?? 'Codigo invalido ou expirado.',
        'confirmSecurityCode' => $result['confirmSecurityCode'] ?? false,
        'deviceConfirm'       => $result['deviceConfirm']       ?? false,
        'raw'                 => $result,
    ]);
}
