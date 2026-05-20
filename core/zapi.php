<?php
/**
 * Z-API — cliente HTTP para WhatsApp via QR Code.
 * Documentação: https://developer.z-api.io
 */

function zapi_request(string $method, string $instance, string $token, string $clientToken, string $path, array $body = null): array {
    $base = defined('ZAPI_BASE') ? ZAPI_BASE : 'https://api.z-api.io';
    $url  = "{$base}/instances/{$instance}/token/{$token}/{$path}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Client-Token: ' . $clientToken,
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $resp  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno) return ['ok' => false, 'code' => 0, 'error' => 'curl error'];
    $decoded = json_decode($resp, true) ?? ['raw' => $resp];
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => $decoded];
}

function zapi_from_channel(array $channel): array {
    $cfg = is_string($channel['config_json'])
        ? json_decode($channel['config_json'], true)
        : ($channel['config_json'] ?? []);
    return [
        'instance'     => $cfg['instance'] ?? '',
        'token'        => $cfg['token'] ?? '',
        'client_token' => $cfg['client_token'] ?? '',
    ];
}

/**
 * Envia mensagem de texto com delays anti-ban.
 * delayTyping: segundos simulando digitação (proporcional ao texto)
 * delayMessage: delay antes de enviar
 */
function zapi_send_text(string $instance, string $token, string $clientToken, string $phone, string $text, int $delayTyping = 0, int $delayMessage = 1): bool {
    $phone = preg_replace('/\D/', '', $phone);
    // Delay proporcional ao tamanho se não especificado
    if ($delayTyping === 0) {
        $delayTyping = min(5, max(1, (int)(strlen($text) / 40)));
    }
    $r = zapi_request('POST', $instance, $token, $clientToken, 'send-text', [
        'phone'        => $phone,
        'message'      => $text,
        'delayTyping'  => $delayTyping,
        'delayMessage' => $delayMessage,
    ]);
    return $r['ok'];
}

/** Pega status da conexão (connected/disconnected) */
/**
 * Envia audio (mp3/ogg) via URL publica.
 * Z-API endpoint: send-audio. Body: { phone, audio: <url>, waveform?, viewOnce? }
 */
function zapi_send_audio(string $instance, string $token, string $clientToken, string $phone, string $audioUrl): bool {
    $phone = preg_replace('/\D/', '', $phone);
    $r = zapi_request('POST', $instance, $token, $clientToken, 'send-audio', [
        'phone'    => $phone,
        'audio'    => $audioUrl,
        'waveform' => true,  // mostra waveform = parece mais humano
    ]);
    return $r['ok'];
}

function zapi_get_status(string $instance, string $token, string $clientToken): array {
    $r = zapi_request('GET', $instance, $token, $clientToken, 'status');
    if (!$r['ok']) return ['connected' => false, 'phone' => null];
    $d = $r['data'] ?? [];
    return [
        'connected' => ($d['connected'] ?? false) === true,
        'phone'     => $d['phone'] ?? null,
        'smartphoneConnected' => $d['smartphoneConnected'] ?? false,
    ];
}

/** Pega QR Code em base64 para exibir na tela */
function zapi_get_qrcode(string $instance, string $token, string $clientToken): ?string {
    $r = zapi_request('GET', $instance, $token, $clientToken, 'qr-code/image');
    if (!$r['ok']) return null;
    $d = $r['data'] ?? [];
    return $d['value'] ?? null; // base64 da imagem PNG
}

/** Desconecta a instância (logout) */
function zapi_disconnect(string $instance, string $token, string $clientToken): bool {
    $r = zapi_request('DELETE', $instance, $token, $clientToken, 'disconnect');
    return $r['ok'];
}

// ── Mobile (instâncias autônomas Newton IA) ────────────────────────────────

/** Solicita código SMS/voz pra conectar instância mobile */
function zapi_mobile_request_code(string $instance, string $token, string $clientToken, string $phone, string $method = 'sms'): array {
    $r = zapi_request('POST', $instance, $token, $clientToken, 'mobile/request-code', [
        'ddi'    => '55',
        'phone'  => preg_replace('/\D/', '', $phone),
        'method' => $method, // sms | voice | wa_old
    ]);
    return $r['data'] ?? ['success' => false, 'error' => $r['error'] ?? 'failed'];
}

/** Confirma código recebido por SMS/voz */
function zapi_mobile_confirm_code(string $instance, string $token, string $clientToken, string $code): array {
    $r = zapi_request('POST', $instance, $token, $clientToken, 'mobile/confirm-code', [
        'code' => trim($code),
    ]);
    return $r['data'] ?? ['success' => false, 'error' => $r['error'] ?? 'failed'];
}

// ── Webhooks ───────────────────────────────────────────────────────────────

/**
 * Configura todos os webhooks da instância de uma vez.
 * $baseUrl ex: https://app.newtonia.digital/webhooks/zapi-synapse.php?channel=5&token=abc
 */
function zapi_set_webhooks(string $instance, string $token, string $clientToken, string $baseUrl): array {
    $endpoints = [
        'update-webhook-received'       => '&event=receive',
        'update-webhook-message-status' => '&event=status',
        'update-webhook-delivery'       => '&event=status',
        'update-webhook-disconnected'   => '&event=disconnect',
        'update-webhook-connected'      => '&event=connect',
    ];
    $results = [];
    foreach ($endpoints as $ep => $qs) {
        $r = zapi_request('PUT', $instance, $token, $clientToken, $ep, [
            'value' => $baseUrl . $qs,
        ]);
        $results[$ep] = $r['ok'];
    }
    return $results;
}

// ── Partner API ────────────────────────────────────────────────────────────

/** Lista instâncias da conta Partner */
function zapi_partner_list(string $partnerToken): array {
    $ch = curl_init('https://api.z-api.io/instances?page=1&pageSize=50');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $partnerToken],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw  = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($raw, true);
    return $data['content'] ?? [];
}

/** Cria nova instância via Partner API (cria sempre WEB — mobile requer painel Z-API) */
function zapi_partner_create(string $partnerToken, string $clientToken, string $name): array {
    $ch = curl_init('https://api.z-api.io/instances/integrator/on-demand');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['name' => $name]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $partnerToken,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($raw, true) ?? [];
    if (empty($data['id'])) return ['ok' => false, 'error' => 'sem id/token', 'raw' => $data];
    return [
        'ok'          => true,
        'instance_id' => $data['id'],
        'token'       => $data['token'],
        'client_token'=> $clientToken,
        'due'         => $data['due'] ?? null,
    ];
}

/** Normaliza telefone para formato Z-API (E.164 BR sem +) */
function _zapi_normalize_phone(string $raw): string {
    $p = preg_replace('/\D/', '', $raw);
    if (strlen($p) === 13 && str_starts_with($p, '55')) $p = substr($p, 2);
    if (strlen($p) === 10) $p = substr($p, 0, 2) . '9' . substr($p, 2);
    if (strlen($p) === 11) $p = '55' . $p;
    return $p;
}
