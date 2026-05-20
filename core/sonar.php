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

// ── Text-to-Speech dispatcher ─────────────────────────────────────────────────

/**
 * Gera audio MP3 a partir de texto.
 * Provider: 'groq' (PlayAI/Orpheus, grátis com chave Groq) ou 'elevenlabs'.
 * Retorna URL publica do mp3 ou null.
 */
function sonar_tts(int $tenantId, int $agentId, string $text, ?string $voiceId = null, string $provider = 'groq'): ?string {
    $text = trim($text);
    if ($text === '') return null;
    return $provider === 'elevenlabs'
        ? _sonar_tts_elevenlabs($tenantId, $agentId, $text, $voiceId)
        : _sonar_tts_groq($tenantId, $agentId, $text, $voiceId);
}

// ── TTS via Groq (PlayAI — mesmo key Groq, grátis) ───────────────────────────

/**
 * Vozes PlayAI no Groq (EN/PT multilingual):
 *   Femininas: Aaliyah-PlayAI, Adelaide-PlayAI, Celeste-PlayAI, Cheyenne-PlayAI,
 *              Deedee-PlayAI, Eleanor-PlayAI, Gail-PlayAI, Nyx-PlayAI,
 *              Paige-PlayAI, Quinn-PlayAI, Seren-PlayAI
 *   Masculinas: Atlas-PlayAI, Basil-PlayAI, Briggs-PlayAI, Calum-PlayAI,
 *               Chip-PlayAI, Cillian-PlayAI, Finn-PlayAI, Fritz-PlayAI,
 *               Mason-PlayAI, Orion-PlayAI, Ranger-PlayAI, Silas-PlayAI,
 *               Thunder-PlayAI, Tobias-PlayAI, Valentino-PlayAI
 */
function _sonar_tts_groq(int $tenantId, int $agentId, string $text, ?string $voiceId = null): ?string {
    $key = setting_get('groq.api_key') ?: (defined('GROQ_API_KEY') ? GROQ_API_KEY : '');
    if (!$key) return null;

    if (mb_strlen($text) > 2000) $text = mb_substr($text, 0, 2000);
    $voiceId = $voiceId ?: 'Celeste-PlayAI';

    $body = json_encode([
        'model'           => 'playai-tts',
        'input'           => $text,
        'voice'           => $voiceId,
        'response_format' => 'mp3',
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.groq.com/openai/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $audio = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300 || !$audio || strlen($audio) < 500) return null;

    $dir  = sonar_ensure_dir($tenantId);
    $name = bin2hex(random_bytes(8)) . '.mp3';
    file_put_contents("$dir/$name", $audio);

    db_insert('voice_usage', [
        'tenant_id' => $tenantId, 'agent_id' => $agentId, 'direction' => 'out',
        'provider'  => 'groq-tts', 'char_count' => mb_strlen($text),
    ]);

    return sonar_public_url($tenantId, $name);
}

// ── TTS via ElevenLabs ────────────────────────────────────────────────────────

/**
 * Voice IDs ElevenLabs comuns:
 *   - "EXAVITQu4vr4xnSDxMaL" — Bella (feminina, conversacional)
 *   - "21m00Tcm4TlvDq8ikWAM" — Rachel (feminina, natural)
 *   - "AZnzlk1XvdvUeBnXmlld" — Domi (feminina, jovem)
 *   - "TxGEqnHWrfWFTfGW9XjX" — Josh (masculino)
 */
function _sonar_tts_elevenlabs(int $tenantId, int $agentId, string $text, ?string $voiceId = null): ?string {
    $key = setting_get('elevenlabs.api_key');
    if (!$key) return null;

    if (mb_strlen($text) > 1500) $text = mb_substr($text, 0, 1500);
    $voiceId = $voiceId ?: (setting_get('elevenlabs.default_voice') ?: 'EXAVITQu4vr4xnSDxMaL');

    $url = 'https://api.elevenlabs.io/v1/text-to-speech/' . $voiceId . '?output_format=mp3_44100_128';
    $body = json_encode([
        'text'           => $text,
        'model_id'       => 'eleven_multilingual_v2',
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

// ── Catálogo de vozes ─────────────────────────────────────────────────────────

function sonar_groq_voices(): array {
    return [
        // femininas
        ['voice_id' => 'Aaliyah-PlayAI',   'name' => 'Aaliyah',   'gender' => 'F'],
        ['voice_id' => 'Adelaide-PlayAI',   'name' => 'Adelaide',  'gender' => 'F'],
        ['voice_id' => 'Celeste-PlayAI',    'name' => 'Celeste',   'gender' => 'F'],
        ['voice_id' => 'Cheyenne-PlayAI',   'name' => 'Cheyenne',  'gender' => 'F'],
        ['voice_id' => 'Deedee-PlayAI',     'name' => 'Deedee',    'gender' => 'F'],
        ['voice_id' => 'Eleanor-PlayAI',    'name' => 'Eleanor',   'gender' => 'F'],
        ['voice_id' => 'Gail-PlayAI',       'name' => 'Gail',      'gender' => 'F'],
        ['voice_id' => 'Nyx-PlayAI',        'name' => 'Nyx',       'gender' => 'F'],
        ['voice_id' => 'Paige-PlayAI',      'name' => 'Paige',     'gender' => 'F'],
        ['voice_id' => 'Quinn-PlayAI',      'name' => 'Quinn',     'gender' => 'F'],
        ['voice_id' => 'Seren-PlayAI',      'name' => 'Seren',     'gender' => 'F'],
        // masculinas
        ['voice_id' => 'Atlas-PlayAI',      'name' => 'Atlas',     'gender' => 'M'],
        ['voice_id' => 'Basil-PlayAI',      'name' => 'Basil',     'gender' => 'M'],
        ['voice_id' => 'Briggs-PlayAI',     'name' => 'Briggs',    'gender' => 'M'],
        ['voice_id' => 'Chip-PlayAI',       'name' => 'Chip',      'gender' => 'M'],
        ['voice_id' => 'Cillian-PlayAI',    'name' => 'Cillian',   'gender' => 'M'],
        ['voice_id' => 'Finn-PlayAI',       'name' => 'Finn',      'gender' => 'M'],
        ['voice_id' => 'Fritz-PlayAI',      'name' => 'Fritz',     'gender' => 'M'],
        ['voice_id' => 'Mason-PlayAI',      'name' => 'Mason',     'gender' => 'M'],
        ['voice_id' => 'Orion-PlayAI',      'name' => 'Orion',     'gender' => 'M'],
        ['voice_id' => 'Ranger-PlayAI',     'name' => 'Ranger',    'gender' => 'M'],
        ['voice_id' => 'Silas-PlayAI',      'name' => 'Silas',     'gender' => 'M'],
        ['voice_id' => 'Thunder-PlayAI',    'name' => 'Thunder',   'gender' => 'M'],
        ['voice_id' => 'Tobias-PlayAI',     'name' => 'Tobias',    'gender' => 'M'],
        ['voice_id' => 'Valentino-PlayAI',  'name' => 'Valentino', 'gender' => 'M'],
    ];
}

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
