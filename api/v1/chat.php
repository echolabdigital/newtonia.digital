<?php
/**
 * Newton IA — POST /api/v1/chat
 *
 * Envia uma mensagem para um agente e retorna a resposta da IA.
 * Mantem conversa se conversation_id ou contact for fornecido.
 *
 * Body JSON:
 *   {
 *     "agent_id":         123,         // obrigatorio
 *     "message":          "ola",       // obrigatorio
 *     "conversation_id":  456,         // opcional (continua conversa existente)
 *     "contact": {                     // opcional (cria/recupera conversa por contato)
 *       "phone": "+5547999998888",
 *       "name":  "Maria"
 *     },
 *     "metadata": { ... }              // opcional, anexado a conversa
 *   }
 *
 * Retorna:
 *   { ok, reply, conversation_id, message_id, model, provider, latency_ms }
 */
require_once __DIR__ . '/_bootstrap.php';

$ctx = api_boot('chat:write', ['POST']);
api_track($ctx);

$body    = $ctx['body'];
$tid     = (int)$ctx['tenant']['id'];
$agentId = (int)($body['agent_id'] ?? 0);
$message = trim((string)($body['message'] ?? ''));
$convId  = isset($body['conversation_id']) ? (int)$body['conversation_id'] : 0;
$contact = $body['contact'] ?? null;

if (!$agentId)          api_fail(400, 'missing_field', 'agent_id obrigatorio');
if ($message === '')    api_fail(400, 'missing_field', 'message obrigatorio');
if (mb_strlen($message) > 8000) api_fail(400, 'message_too_long', 'message excede 8000 chars');

$agent = agent_get($agentId, $tid);
if (!$agent) api_fail(404, 'agent_not_found', "Agente $agentId nao encontrado neste workspace");
if (($agent['status'] ?? 'active') !== 'active') api_fail(400, 'agent_inactive', 'Agente esta inativo');

// Resolve conversa
$conv = null;
if ($convId) {
    $conv = db_one('SELECT * FROM conversations WHERE id = ? AND tenant_id = ? AND agent_id = ? LIMIT 1', [$convId, $tid, $agentId]);
    if (!$conv) api_fail(404, 'conversation_not_found', "Conversa $convId nao encontrada");
} else {
    $phone = 'api:' . substr(bin2hex(random_bytes(6)), 0, 12);
    $name  = 'API Caller';
    if (is_array($contact)) {
        if (!empty($contact['phone'])) $phone = preg_replace('/[^a-z0-9+_:-]/i', '', (string)$contact['phone']);
        if (!empty($contact['name']))  $name  = substr((string)$contact['name'], 0, 80);
    }
    $conv = synapse_get_or_create_conversation($agentId, $tid, null, $phone, $name);
}

// Salva mensagem inbound
$msgInId = synapse_save_message((int)$conv['id'], 'in', $message);
webhook_event_message_received($tid, $conv, $msgInId, $message);

// Monta historico + system
$history  = synapse_get_history((int)$conv['id'], (int)($agent['context_window'] ?? 20));
$messages = [['role' => 'system', 'content' => synapse_build_system($agent, $conv)]];
foreach ($history as $row) {
    $messages[] = ['role' => $row['direction'] === 'in' ? 'user' : 'assistant', 'content' => $row['content']];
}

$provider = $agent['provider'] ?: llm_provider_from_model($agent['model'] ?: '');
$t0       = microtime(true);
$reply    = llm_chat($provider, $agent['model'] ?: 'llama-3.3-70b-versatile', $messages);
$latency  = (int) ((microtime(true) - $t0) * 1000);

if (!$reply) api_fail(502, 'llm_error', "LLM ($provider) nao respondeu. Verifique a API key em Integracoes.");

$msgOutId = synapse_save_message((int)$conv['id'], 'out', $reply);
webhook_event_message_sent($tid, $conv, $msgOutId, $reply, $provider, (string)($agent['model'] ?? ''));

api_ok([
    'reply'           => $reply,
    'conversation_id' => (int)$conv['id'],
    'message_id'      => $msgOutId,
    'inbound_id'      => $msgInId,
    'provider'        => $provider,
    'model'           => $agent['model'],
    'latency_ms'      => $latency,
]);
