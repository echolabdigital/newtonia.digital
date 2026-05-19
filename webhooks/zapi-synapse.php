<?php
/**
 * SYNAPSE — Webhook Z-API
 * URL: /webhooks/zapi-synapse.php?channel=<id>&token=<webhook_token>
 * Configurar esta URL no painel Z-API > Webhooks > On Message Received
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Validação básica
$channelId = (int)($_GET['channel'] ?? 0);
$token     = trim($_GET['token'] ?? '');
if (!$channelId || !$token) { http_response_code(400); echo json_encode(['error'=>'invalid params']); exit; }

// Lê payload
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) { echo json_encode(['ok'=>true,'skip'=>'no json']); exit; }

// Busca canal
$channel = db_one('SELECT * FROM agent_channels WHERE id = ? AND webhook_token = ?', [$channelId, $token]);
if (!$channel) { http_response_code(403); echo json_encode(['error'=>'channel not found']); exit; }

// Ignora mensagens enviadas pelo próprio bot (direction out) e mensagens de grupo
$isFromMe  = $data['fromMe'] ?? false;
$isGroup   = isset($data['phone']) && str_contains((string)$data['phone'], '@g.us');
if ($isFromMe || $isGroup) { echo json_encode(['ok'=>true,'skip'=>'fromMe or group']); exit; }

// Só processa tipo text por ora
$type    = $data['type'] ?? '';
$content = '';
if ($type === 'ReceivedCallback') {
    $content = $data['text']['message'] ?? ($data['body'] ?? '');
} else {
    $content = $data['text']['message'] ?? ($data['body'] ?? ($data['message'] ?? ''));
}
$content = trim($content);
if (!$content) { echo json_encode(['ok'=>true,'skip'=>'empty content']); exit; }

// Extrai telefone e nome
$phone = preg_replace('/\D/', '', $data['phone'] ?? '');
$name  = $data['senderName'] ?? ($data['pushname'] ?? '');
if (!$phone) { echo json_encode(['ok'=>true,'skip'=>'no phone']); exit; }

// Busca agente
$agent = db_one('SELECT * FROM agents WHERE id = ? AND status = "active"', [(int)$channel['agent_id']]);
if (!$agent) { echo json_encode(['ok'=>true,'skip'=>'agent inactive or not found']); exit; }

$tenantId = (int)$channel['tenant_id'];

// Busca ou cria conversa
$conv = synapse_get_or_create_conversation((int)$agent['id'], $tenantId, $channelId, $phone, $name);

// Bot pausado — humano está no controle
if (($conv['status'] ?? '') === 'paused') {
    synapse_save_message((int)$conv['id'], 'in', $content);
    db_q('UPDATE conversations SET last_message_at = NOW(), message_count = message_count + 1 WHERE id = ?', [(int)$conv['id']]);
    echo json_encode(['ok'=>true,'skip'=>'paused_human_takeover']); exit;
}

// Processa e responde
$reply = synapse_process($agent, $conv, $content, $channel);

echo json_encode(['ok' => true, 'replied' => !empty($reply), 'length' => strlen($reply ?? '')]);
