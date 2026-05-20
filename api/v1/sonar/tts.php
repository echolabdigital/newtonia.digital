<?php
/**
 * Newton IA — POST /api/v1/sonar/tts
 *
 * Gera audio MP3 a partir de texto via PlayAI (Groq) ou ElevenLabs.
 *
 * Body JSON:
 *   {
 *     "text":     "Ola, tudo bem?",          // obrigatorio (max 2000 chars)
 *     "voice_id": "Celeste-PlayAI",          // opcional (default: Celeste-PlayAI)
 *     "provider": "groq",                    // opcional: "groq" (default) | "elevenlabs"
 *     "agent_id": 12                         // opcional (apenas para tracking de uso)
 *   }
 *
 * Retorna: { ok, audio_url, provider, char_count, latency_ms }
 */
require_once __DIR__ . '/../_bootstrap.php';

$ctx = api_boot('sonar:write', ['POST']);
api_track($ctx);

$body     = $ctx['body'];
$tid      = (int)$ctx['tenant']['id'];
$text     = trim((string)($body['text'] ?? ''));
$voiceId  = trim((string)($body['voice_id'] ?? '')) ?: null;
$provider = in_array($body['provider'] ?? 'groq', ['groq','elevenlabs'], true) ? $body['provider'] : 'groq';
$agentId  = (int)($body['agent_id'] ?? 0);

if ($text === '') api_fail(400, 'missing_field', 'text obrigatorio');
if (mb_strlen($text) > 2000) api_fail(400, 'text_too_long', 'text excede 2000 chars');

if ($agentId) {
    $agent = agent_get($agentId, $tid);
    if (!$agent) api_fail(404, 'agent_not_found', "Agente $agentId nao encontrado");
}

$t0       = microtime(true);
$audioUrl = sonar_tts($tid, $agentId, $text, $voiceId, $provider);
$latency  = (int) ((microtime(true) - $t0) * 1000);

if (!$audioUrl) api_fail(502, 'tts_failed', "TTS via $provider falhou. Verifique a chave em Integracoes.");

api_ok([
    'audio_url'  => $audioUrl,
    'provider'   => $provider,
    'voice_id'   => $voiceId ?: ($provider === 'groq' ? 'Celeste-PlayAI' : 'EXAVITQu4vr4xnSDxMaL'),
    'char_count' => mb_strlen($text),
    'latency_ms' => $latency,
]);
