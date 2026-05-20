<?php
/**
 * Newton IA — SONAR
 * Voz no WhatsApp: TTS (ElevenLabs) + transcribe (Groq Whisper).
 *
 * Storage: uploads/audio/<tenant_id>/<hash>.mp3, servido publicamente
 * para que o Z-API possa baixar e enviar ao WhatsApp.
 */

const SONAR_AUDIO_DIR = __DIR__ . '/../uploads/audio';

function sonar_ensure_dir(int $tenantId): string {
    $dir = SONAR_AUDIO_DIR . '/' . $tenantId;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function sonar_public_url(int $tenantId, string $filename): string {
    return rtrim(APP_URL, '/') . '/uploads/audio/' . $tenantId . '/' . $filename;
}

// ── Text-to-Speech (ElevenLabs) ───────────────────────────────────────────────

/**
 * Gera audio MP3 a partir de texto usando ElevenLabs.
 * Retorna URL publica do mp3 ou null.
 *
 * Voice IDs comuns:
 *   - "EXAVITQu4vr4xnSDxMaL" — Bella (feminina, conversacional)
 *   - "21m00Tcm4TlvDq8ikWAM" — Rachel (feminina, natural)
 *   - "AZnzlk1XvdvUeBnXmlld" — Domi (feminina, jovem)
 *   - "TxGEqnHWrfWFTfGW9XjX" — Josh (masculina)
 */
function sonar_tts(int $tenantId, int $agentId, string $text, ?string $voiceId = null): ?string {
    $key = setting_get('elevenlabs.api_key');
    if (!$key) return null;

    $voiceId = $voiceId ?: (setting_get('elevenlabs.default_voice') ?: 'EXAVITQu4vr4xnSDxMaL');
    $text    = trim($text);
    if ($text === '') return null;
    if (mb_strlen($text) > 1500) $text = mb_substr($text, 0, 1500); // hard cap

    $url = 'https://api.elevenlabs.io/v1/text-to-speech/' . $voiceId . '?output_format=mp3_44100_128';
    $body = json_encode([
        'text'     => $text,
        'model_id' => 'eleven_multilingual_v2',
        'voice_settings' => ['stability' => 0.5, 'similarity_boost' => 0.75, 'style' => 0.2],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'xi-api-key: ' . $key, 'Accept: audio/mpeg'],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $audio = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300 || !$audio || strlen($audio) < 1000) return null;

    $dir  = sonar_ensure_dir($tenantId);
    $name = bin2hex(random_bytes(8)) . '.mp3';
    file_put_contents("$dir/$name", $audio);

    db_insert('voice_usage', [
        'tenant_id' => $tenantId, 'agent_id' => $agentId, 'direction' => 'out',
        'provider'  => 'elevenlabs', 'char_count' => mb_strlen($text),
    ]);

    return sonar_public_url($tenantId, $name);
}

// ── Speech-to-Text (Groq Whisper) ─────────────────────────────────────────────

/**
 * Transcreve audio (URL ou caminho local) usando Groq Whisper.
 * Retorna texto ou null.
 */
function sonar_transcribe(int $tenantId, int $agentId, string $audioUrlOrPath): ?string {
    $key = setting_get('groq.api_key') ?: (defined('GROQ_API_KEY') ? GROQ_API_KEY : '');
    if (!$key) return null;

    // Baixa para arquivo temporario se for URL
    $tmpPath = null;
    if (preg_match('#^https?://#i', $audioUrlOrPath)) {
        $data = @file_get_contents($audioUrlOrPath);
        if (!$data) return null;
        $tmpPath = tempnam(sys_get_temp_dir(), 'newton_audio_');
        file_put_contents($tmpPath, $data);
        $audioPath = $tmpPath;
    } else {
        if (!is_file($audioUrlOrPath)) return null;
        $audioPath = $audioUrlOrPath;
    }

    $cfile = curl_file_create($audioPath, 'audio/ogg', 'audio.ogg');
    $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['file' => $cfile, 'model' => 'whisper-large-v3-turbo', 'language' => 'pt', 'response_format' => 'json'],
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($tmpPath && is_file($tmpPath)) @unlink($tmpPath);
    if ($code < 200 || $code >= 300) return null;

    $data = json_decode($resp, true);
    $text = trim($data['text'] ?? '');
    if ($text === '') return null;

    db_insert('voice_usage', [
        'tenant_id' => $tenantId, 'agent_id' => $agentId, 'direction' => 'in',
        'provider'  => 'groq-whisper', 'char_count' => mb_strlen($text),
    ]);
    return $text;
}

// ── Catalogo de vozes (cache 24h) ─────────────────────────────────────────────

function sonar_list_voices(): array {
    $key = setting_get('elevenlabs.api_key');
    if (!$key) return [];

    $ch = curl_init('https://api.elevenlabs.io/v1/voices');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['xi-api-key: ' . $key],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['voices'] ?? [];
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function sonar_usage_for_tenant(int $tenantId, int $days = 30): array {
    $rows = db_all(
        'SELECT direction, SUM(char_count) AS chars, COUNT(*) AS calls
         FROM voice_usage WHERE tenant_id = ? AND created_at > NOW() - INTERVAL ? DAY GROUP BY direction',
        [$tenantId, $days]
    );
    $out = ['in' => ['chars'=>0,'calls'=>0], 'out' => ['chars'=>0,'calls'=>0]];
    foreach ($rows as $r) $out[$r['direction']] = ['chars'=>(int)$r['chars'],'calls'=>(int)$r['calls']];
    return $out;
}
