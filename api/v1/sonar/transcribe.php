<?php
/**
 * Newton IA — POST /api/v1/sonar/transcribe
 *
 * Transcreve audio (URL publica ou base64) via Groq Whisper.
 *
 * Body JSON:
 *   {
 *     "audio_url": "https://...arquivo.ogg",     // obrigatorio
 *     "agent_id":  12                            // opcional (tracking)
 *   }
 *
 * Retorna: { ok, transcript, provider, char_count, latency_ms }
 */
require_once __DIR__ . '/../_bootstrap.php';

$ctx = api_boot('sonar:write', ['POST']);
api_track($ctx);

$body     = $ctx['body'];
$tid      = (int)$ctx['tenant']['id'];
$audioUrl = trim((string)($body['audio_url'] ?? ''));
$agentId  = (int)($body['agent_id'] ?? 0);

if ($audioUrl === '') api_fail(400, 'missing_field', 'audio_url obrigatorio');
if (!preg_match('#^https?://#i', $audioUrl)) api_fail(400, 'invalid_url', 'audio_url precisa ser http(s)://');

if ($agentId) {
    $agent = agent_get($agentId, $tid);
    if (!$agent) api_fail(404, 'agent_not_found', "Agente $agentId nao encontrado");
}

$t0         = microtime(true);
$transcript = sonar_transcribe($tid, $agentId, $audioUrl);
$latency    = (int) ((microtime(true) - $t0) * 1000);

if ($transcript === null) api_fail(502, 'transcribe_failed', 'Whisper falhou. Verifique a chave Groq em Integracoes.');

api_ok([
    'transcript' => $transcript,
    'provider'   => 'groq-whisper-large-v3-turbo',
    'char_count' => mb_strlen($transcript),
    'latency_ms' => $latency,
]);
