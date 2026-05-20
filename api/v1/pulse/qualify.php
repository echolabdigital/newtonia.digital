<?php
/**
 * Newton IA — POST /api/v1/pulse/qualify
 *
 * Analisa uma conversa via SPIN Selling e retorna qualificacao do lead.
 *
 * Body JSON — DUAS formas de uso:
 *
 *   (A) Conversa Newton existente:
 *       { "agent_id": 12, "conversation_id": 456 }
 *
 *   (B) Conversa externa (HERMES envia o transcript):
 *       {
 *         "agent_id": 12,
 *         "messages": [
 *           { "role": "user",      "content": "ola, vi seu produto..." },
 *           { "role": "assistant", "content": "oi! como posso ajudar?" }
 *         ]
 *       }
 *
 * Retorna: { ok, spin: { situation, problem, implication, need_payoff, score, temperature, next_step } }
 */
require_once __DIR__ . '/../_bootstrap.php';

$ctx = api_boot('pulse:read', ['POST']);
api_track($ctx);

$body    = $ctx['body'];
$tid     = (int)$ctx['tenant']['id'];
$agentId = (int)($body['agent_id'] ?? 0);
$convId  = isset($body['conversation_id']) ? (int)$body['conversation_id'] : 0;
$msgs    = is_array($body['messages'] ?? null) ? $body['messages'] : [];

if (!$agentId) api_fail(400, 'missing_field', 'agent_id obrigatorio');
$agent = agent_get($agentId, $tid);
if (!$agent) api_fail(404, 'agent_not_found', "Agente $agentId nao encontrado");

// Modo A — conversa Newton
if ($convId) {
    $conv = db_one('SELECT * FROM conversations WHERE id = ? AND tenant_id = ?', [$convId, $tid]);
    if (!$conv) api_fail(404, 'conversation_not_found', "Conversa $convId nao encontrada neste workspace");

    $spin = pulse_qualify_spin($convId);
    if (!$spin) api_fail(502, 'qualify_failed', 'Nao foi possivel qualificar (LLM ou mensagens insuficientes)');
    api_ok(['spin' => $spin, 'source' => 'conversation']);
}

// Modo B — transcript bruto enviado pelo consumidor (HERMES)
if (!$msgs) api_fail(400, 'missing_field', 'Envie conversation_id OU messages[]');

$transcript = [];
foreach ($msgs as $m) {
    $role = ($m['role'] ?? '') === 'assistant' ? 'Agente' : 'Cliente';
    $content = trim((string)($m['content'] ?? ''));
    if ($content !== '') $transcript[] = "$role: $content";
}
if (!$transcript) api_fail(400, 'invalid_messages', 'messages[] vazio ou sem content');

$text = implode("\n", $transcript);
if (mb_strlen($text) > 8000) $text = mb_substr($text, -8000);

$sys = "Voce e um especialista em SPIN Selling (Rackham). Analise a conversa e extraia, em portugues do Brasil, " .
       "APENAS em JSON valido sem texto fora:\n" .
       "{\"situation\":\"...\",\"problem\":\"...\",\"implication\":\"...\",\"need_payoff\":\"...\",\"score\":0-100,\"temperature\":\"cold|warm|hot\",\"next_step\":\"...\"}";

$raw = llm_chat('groq', 'llama-3.3-70b-versatile',
    [['role'=>'system','content'=>$sys], ['role'=>'user','content'=>"Conversa:\n\n$text"]],
    ['max_tokens' => 700, 'temperature' => 0.2]);

if (!$raw || !preg_match('/\{.*\}/s', $raw, $m)) api_fail(502, 'llm_error', 'LLM nao retornou JSON valido');
$data = json_decode($m[0], true);
if (!is_array($data)) api_fail(502, 'invalid_json', 'JSON do LLM invalido');

$score = max(0, min(100, (int)($data['score'] ?? 0)));
$temp  = in_array($data['temperature'] ?? '', ['cold','warm','hot'], true) ? $data['temperature']
       : ($score >= 70 ? 'hot' : ($score >= 40 ? 'warm' : 'cold'));

api_ok([
    'spin' => [
        'situation'   => (string)($data['situation']   ?? ''),
        'problem'     => (string)($data['problem']     ?? ''),
        'implication' => (string)($data['implication'] ?? ''),
        'need_payoff' => (string)($data['need_payoff'] ?? ''),
        'score'       => $score,
        'temperature' => $temp,
        'next_step'   => (string)($data['next_step']   ?? ''),
    ],
    'source' => 'messages',
]);
