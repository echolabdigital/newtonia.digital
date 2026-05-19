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

/** Envia mensagem de texto */
function zapi_send_text(string $instance, string $token, string $clientToken, string $phone, string $text): bool {
    // Remove tudo que não é número e garante código do país
    $phone = preg_replace('/\D/', '', $phone);
    $r = zapi_request('POST', $instance, $token, $clientToken, 'send-text', [
        'phone'   => $phone,
        'message' => $text,
    ]);
    return $r['ok'];
}

/** Pega status da conexão (connected/disconnected) */
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
