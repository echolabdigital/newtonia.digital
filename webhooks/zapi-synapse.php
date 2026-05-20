<?php
/**
 * Newton IA — Z-API webhook receiver
 * URL: /webhooks/zapi-synapse.php?channel={id}&token={webhook_token}
 *
 * Configurar no painel Z-API (ou via zapi_set_webhooks()):
 *   receive   → mensagem recebida
 *   status    → delivery/read
 *   connect   → instância conectou
 *   disconnect → instância desconectou
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/agent.php';
require_once __DIR__ . '/../core/synapse.php';
require_once __DIR__ . '/../core/zapi.php';
require_once __DIR__ . '/../core/llm.php';

header('Content-Type: application/json');

// ── Lê payload ───────────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

// Evento via query string (configurado no zapi_set_webhooks) ou via payload type
$event = $_GET['event'] ?? '';
$type  = $data['type'] ?? '';

// ── Valida canal ──────────────────────────────────────────────────────────────
$channelId = (int) ($_GET['channel'] ?? 0);
$urlToken  = trim($_GET['token'] ?? '');

if (!$channelId || !$urlToken) {
    http_response_code(400);
    echo json_encode(['error' => 'missing channel/token']);
    exit;
}

$channel = db_one('SELECT * FROM agent_channels WHERE id = ? AND webhook_token = ?', [$channelId, $urlToken]);
if (!$channel) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid token']);
    exit;
}

$agentId  = (int) $channel['agent_id'];
$tenantId = (int) $channel['tenant_id'];

// ── Conectou ─────────────────────────────────────────────────────────────────
if ($event === 'connect' || $type === 'ConnectedCallback') {
    $phone = $data['phone'] ?? null;
    agent_channel_set_status($channelId, 'connected', $phone);
    error_log("[newton/zapi] channel={$channelId} CONNECTED phone={$phone}");
    echo json_encode(['ok' => true, 'event' => 'connect']);
    exit;
}

// ── Desconectou ───────────────────────────────────────────────────────────────
if ($event === 'disconnect' || $type === 'DisconnectedCallback') {
    agent_channel_set_status($channelId, 'disconnected');
    error_log("[newton/zapi] channel={$channelId} DISCONNECTED");
    echo json_encode(['ok' => true, 'event' => 'disconnect']);
    exit;
}

// ── Status de entrega/leitura ─────────────────────────────────────────────────
if ($event === 'status' || $type === 'MessageStatusCallback') {
    $msgId  = $data['messageId'] ?? '';
    $status = strtolower($data['status'] ?? '');
    if ($msgId && $status) {
        db_q('UPDATE messages SET status = ? WHERE zapi_msg_id = ?', [$status, $msgId]);
    }
    echo json_encode(['ok' => true, 'event' => 'status']);
    exit;
}

// ── Mensagem recebida ─────────────────────────────────────────────────────────
if ($event !== 'receive' && $type !== 'ReceivedCallback') {
    echo json_encode(['ok' => true, 'skip' => 'unknown_event', 'type' => $type]);
    exit;
}

// Filtra mensagens enviadas pelo próprio agente
if (!empty($data['fromMe'])) {
    echo json_encode(['ok' => true, 'skip' => 'fromMe']);
    exit;
}

// Filtra grupos
$rawPhone = (string) ($data['phone'] ?? '');
if (str_contains($rawPhone, '@g.us') || str_contains($rawPhone, '-')) {
    echo json_encode(['ok' => true, 'skip' => 'group']);
    exit;
}

// Extrai texto (texto puro, ou caption de mídia)
$inbound = trim(
    $data['text']['message']
    ?? $data['image']['caption']
    ?? $data['video']['caption']
    ?? $data['document']['caption']
    ?? $data['body']
    ?? ''
);

if ($inbound === '') {
    echo json_encode(['ok' => true, 'skip' => 'empty_text']);
    exit;
}

$senderName = trim($data['senderName'] ?? $data['pushname'] ?? '');
$zapiMsgId  = $data['messageId'] ?? null;
$phone      = _zapi_normalize_phone(preg_replace('/\D/', '', $rawPhone));

if (!$phone) {
    echo json_encode(['ok' => true, 'skip' => 'no_phone']);
    exit;
}

// ── Busca agente ativo ────────────────────────────────────────────────────────
$agent = db_one(
    'SELECT * FROM agents WHERE id = ? AND tenant_id = ? AND status = "active" LIMIT 1',
    [$agentId, $tenantId]
);
if (!$agent) {
    error_log("[newton/zapi] agent={$agentId} not found or inactive");
    echo json_encode(['ok' => true, 'skip' => 'agent_inactive']);
    exit;
}

// ── Conversa ──────────────────────────────────────────────────────────────────
$conv = synapse_get_or_create_conversation($agentId, $tenantId, $channelId, $phone, $senderName);

// Handoff humano — salva mensagem mas não responde
if (in_array($conv['status'] ?? '', ['paused', 'human'])) {
    $mid = synapse_save_message((int)$conv['id'], 'in', $inbound, 'text', $zapiMsgId);
    webhook_event_message_received($tenantId, $conv, $mid, $inbound);
    webhook_fire($tenantId, 'handoff.requested', [
        'conversation_id' => (int)$conv['id'], 'agent_id' => $agentId, 'reason' => 'status_' . $conv['status'],
    ]);
    echo json_encode(['ok' => true, 'skip' => 'human_handoff']);
    exit;
}

// Salva mensagem recebida
$inboundId = synapse_save_message((int)$conv['id'], 'in', $inbound, 'text', $zapiMsgId);
webhook_event_message_received($tenantId, $conv, $inboundId, $inbound);

// ── Anti-ban: delay de digitação proporcional ─────────────────────────────────
$typingDelay = min(4, max(1, (int)(strlen($inbound) / 50)));
sleep($typingDelay);

// ── LLM ───────────────────────────────────────────────────────────────────────
$limit    = max(6, (int)($agent['context_window'] ?? 20));
$history  = synapse_get_history((int)$conv['id'], $limit);
$messages = [['role' => 'system', 'content' => synapse_build_system(
    $agent,
    array_merge($conv, ['contact_name' => $senderName ?: $phone])
)]];
foreach ($history as $row) {
    $messages[] = ['role' => $row['direction'] === 'in' ? 'user' : 'assistant', 'content' => $row['content']];
}

$provider = $agent['provider'] ?: llm_provider_from_model($agent['model'] ?: '');
$response = llm_chat($provider, $agent['model'] ?: 'llama-3.3-70b-versatile', $messages);

if (!$response) {
    error_log("[newton/zapi] llm null — agent={$agentId} conv={$conv['id']}");
    echo json_encode(['ok' => false, 'error' => 'llm_null']);
    exit;
}

$response = trim($response);

// ── Salva e envia ─────────────────────────────────────────────────────────────
$outId = synapse_save_message((int)$conv['id'], 'out', $response, 'text');
webhook_event_message_sent($tenantId, $conv, $outId, $response, $provider, (string)($agent['model'] ?? ''));

$cfg  = json_decode($channel['config_json'] ?? '{}', true);
$sent = zapi_send_text(
    $cfg['instance']     ?? '',
    $cfg['token']        ?? '',
    $cfg['client_token'] ?? '',
    $phone,
    $response
);

if (!$sent) {
    error_log("[newton/zapi] send_text FAILED — channel={$channelId} phone={$phone}");
}

echo json_encode(['ok' => true, 'sent' => $sent, 'conv' => $conv['id'], 'chars' => strlen($response)]);
