<?php
/**
 * Newton IA — API pública do Widget
 * GET  /api/chat.php?agent=<embed_token>  → info do agente (name, color, greeting)
 * POST /api/chat.php?agent=<embed_token>  → processa mensagem, retorna resposta
 */
require_once __DIR__ . '/../config.php';

// CORS — domínio validado abaixo
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . $origin);
    http_response_code(204); exit;
}

$embedToken = trim($_GET['agent'] ?? '');
if (!$embedToken) {
    header('Access-Control-Allow-Origin: *');
    http_response_code(400);
    echo json_encode(['error' => 'agent token required']); exit;
}

// Busca agente pelo embed_token
$agent = db_one('SELECT * FROM agents WHERE embed_token = ? AND status = "active" AND widget_enabled = 1 LIMIT 1', [$embedToken]);
if (!$agent) {
    header('Access-Control-Allow-Origin: *');
    http_response_code(404);
    echo json_encode(['error' => 'agent not found or widget disabled']); exit;
}

// Valida domínio de origem
$allowedDomains = json_decode($agent['allowed_domains'] ?? '[]', true) ?: [];
$originAllowed  = empty($allowedDomains); // se lista vazia = aceita qualquer domínio

if (!$originAllowed && $origin !== '*') {
    $originHost = parse_url($origin, PHP_URL_HOST) ?: $origin;
    foreach ($allowedDomains as $domain) {
        $domain = trim($domain);
        if ($domain === $originHost || str_ends_with($originHost, '.'.$domain) || $domain === '*') {
            $originAllowed = true; break;
        }
    }
}

if (!$originAllowed) {
    http_response_code(403);
    echo json_encode(['error' => 'domain not allowed']); exit;
}

header('Access-Control-Allow-Origin: ' . $origin);
header('Vary: Origin');

$tid = (int)$agent['tenant_id'];

// GET — retorna info pública do agente (para inicializar o widget)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'name'     => $agent['name'],
        'color'    => $agent['widget_color'] ?: '#0ea5e9',
        'greeting' => $agent['widget_greeting'] ?: 'Olá! Como posso ajudar?',
        'position' => $agent['widget_position'] ?: 'bottom-right',
    ]);
    exit;
}

// POST — processa mensagem
$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$message = trim($body['message'] ?? '');
$history = $body['history'] ?? [];
$sessId  = preg_replace('/[^a-z0-9_-]/i', '', $body['session'] ?? '');

if (!$message) { echo json_encode(['error' => 'empty message']); exit; }

// Identifica conversa pela sessão (widget usa session_id aleatório do browser)
$convId = null;
if ($sessId) {
    $existing = db_one(
        'SELECT id FROM conversations WHERE agent_id = ? AND tenant_id = ? AND contact_phone = ? AND status = "open" LIMIT 1',
        [(int)$agent['id'], $tid, 'web:' . $sessId]
    );
    $convId = $existing ? (int)$existing['id'] : null;
}

if (!$convId) {
    $conv = synapse_get_or_create_conversation(
        (int)$agent['id'], $tid, null, 'web:'.($sessId ?: uniqid()), 'Visitante Web'
    );
    $convId = (int)$conv['id'];
} else {
    $conv = db_one('SELECT * FROM conversations WHERE id = ?', [$convId]);
}

// Processa com synapse (sem envio Z-API, é web widget)
synapse_save_message($convId, 'in', $message);

// Monta histórico
$dbHistory = synapse_get_history($convId, (int)($agent['context_window'] ?? 20));
$messages  = [['role' => 'system', 'content' => synapse_build_system($agent, $conv)]];
foreach ($dbHistory as $row) {
    $messages[] = ['role' => $row['direction']==='in'?'user':'assistant', 'content' => $row['content']];
}

// Chama LLM correto
$provider = $agent['provider'] ?: llm_provider_from_model($agent['model'] ?: '');
$reply    = llm_chat($provider, $agent['model'], $messages);

if (!$reply) { echo json_encode(['error' => 'llm_error', 'reply' => 'Erro ao processar. Tente novamente.']); exit; }

synapse_save_message($convId, 'out', $reply);

echo json_encode(['reply' => $reply, 'session' => $sessId ?: $convId]);
