<?php
/**
 * Newton IA — Cliente HTTP para a Hermes Public API v1
 *
 * Configuração: system_settings
 *   hermes.base_url  = https://app.hermesb2b.co/api/v1
 *   hermes.api_key   = nhk_<32hex>
 */

function _hermes_creds(int $tenantId): ?array
{
    $key  = setting_get('hermes.api_key');
    $base = setting_get('hermes.base_url') ?: 'https://app.hermesb2b.co/api/v1';
    if (!$key) {
        error_log("[hermes_api] api_key nao configurada (tenant={$tenantId})");
        return null;
    }
    return ['key' => $key, 'base' => rtrim($base, '/')];
}

function _hermes_req(int $tenantId, string $method, string $path, ?array $body = null): ?array
{
    $creds = _hermes_creds($tenantId);
    if (!$creds) return null;

    $url = $creds['base'] . '/' . ltrim($path, '/');
    $ch  = curl_init($url);

    $headers = [
        'Authorization: Bearer ' . $creds['key'],
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: NewtonIA/1.0',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $method = strtoupper($method);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? new stdClass()));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? new stdClass()));
    } elseif ($method === 'GET' && $body) {
        $url .= '?' . http_build_query($body);
        curl_setopt($ch, CURLOPT_URL, $url);
    }

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log("[hermes_api] curl error: {$err} — {$method} {$path}");
        return null;
    }
    if ($code >= 400) {
        error_log("[hermes_api] HTTP {$code} — {$method} {$path} — " . ($raw ?: '(empty)'));
        return null;
    }

    return json_decode($raw, true) ?? [];
}

// ── API publica ────────────────────────────────────────────────────────────────

function hermes_ctx(int $tenantId, int $cardId): ?array
{
    return _hermes_req($tenantId, 'GET', "crm/cards/{$cardId}/context");
}

function hermes_card_create(int $tenantId, array $data): ?array
{
    return _hermes_req($tenantId, 'POST', 'crm/cards', $data);
}

function hermes_card_move(int $tenantId, int $cardId, int $columnId, string $reason = ''): bool
{
    $r = _hermes_req($tenantId, 'PATCH', "crm/cards/{$cardId}/move", [
        'column_id' => $columnId,
        'reason'    => $reason,
    ]);
    return is_array($r);
}

function hermes_card_note(int $tenantId, int $cardId, string $text): bool
{
    $r = _hermes_req($tenantId, 'POST', "crm/cards/{$cardId}/note", ['text' => $text]);
    return is_array($r);
}

function hermes_signal_send(int $tenantId, string $phone, string $message, array $opts = []): bool
{
    $r = _hermes_req($tenantId, 'POST', 'signal/send', array_merge([
        'phone'   => $phone,
        'message' => $message,
    ], $opts));
    return is_array($r);
}

function hermes_chat_pause(int $tenantId, int $chatId, int $minutes = 120): bool
{
    $r = _hermes_req($tenantId, 'POST', "whatslab/chats/{$chatId}/pause", ['minutes' => $minutes]);
    return is_array($r);
}

function hermes_chat_resume(int $tenantId, int $chatId): bool
{
    $r = _hermes_req($tenantId, 'POST', "whatslab/chats/{$chatId}/resume", []);
    return is_array($r);
}

function hermes_radar_leads(int $tenantId, array $params = []): array
{
    $r = _hermes_req($tenantId, 'GET', 'radar/leads', $params ?: null);
    return is_array($r) ? $r : [];
}

function hermes_ping(int $tenantId): ?int
{
    $r = _hermes_req($tenantId, 'GET', 'ping');
    return isset($r['tenant_id']) ? (int)$r['tenant_id'] : null;
}

// ── Helpers de contexto ────────────────────────────────────────────────────────

function hermes_is_human_controlled(int $tenantId, int $cardId): bool
{
    $ctx = hermes_ctx($tenantId, $cardId);
    if (!$ctx) return false;
    return ($ctx['chat']['handled_by'] ?? '') === 'human';
}

function hermes_build_context_block(array $ctx): string
{
    $lead     = $ctx['lead']     ?? [];
    $pipeline = $ctx['pipeline'] ?? [];
    $chat     = $ctx['chat']     ?? [];
    $notes    = trim($ctx['notes'] ?? '');
    $history  = $ctx['history']  ?? [];

    $lines = ['--- CONTEXTO DO LEAD (Hermes CRM) ---'];

    if ($lead) {
        $fantasia = !empty($lead['nome_fantasia']) ? ' ("' . $lead['nome_fantasia'] . '")' : '';
        $lines[] = 'Empresa: '   . ($lead['razao_social'] ?? '') . $fantasia;
        $lines[] = 'CNPJ: '      . ($lead['cnpj'] ?? 'nao informado');
        $lines[] = 'Telefone: '  . ($lead['telefone'] ?? 'nao informado');
        $lines[] = 'Cidade/UF: ' . ($lead['cidade_uf'] ?? 'nao informado');
        $lines[] = 'Score: '     . (isset($lead['score']) ? $lead['score'] . '/100' : 'nao calculado');
        if (!empty($lead['product_name'])) {
            $lines[] = 'Produto de interesse: ' . $lead['product_name'];
        }
    }

    if (!empty($pipeline['current'])) {
        $lines[] = 'Etapa no Pipeline: ' . $pipeline['current']['name'];
        $cols = array_column($pipeline['available'] ?? [], 'name');
        if ($cols) $lines[] = 'Etapas disponiveis: ' . implode(' -> ', $cols);
    }

    if (!empty($chat['tags'])) {
        $lines[] = 'Tags: ' . implode(', ', $chat['tags']);
    }
    if (!empty($chat['opted_out'])) {
        $lines[] = 'ATENCAO: Lead solicitou nao receber mais mensagens.';
    }

    if ($notes) {
        $lines[] = '';
        $lines[] = 'Anotacao interna do vendedor:';
        $lines[] = $notes;
    }

    if ($history) {
        $lines[] = '';
        $lines[] = 'Historico recente desta conversa (use para manter continuidade):';
        foreach (array_slice($history, -8) as $h) {
            $who     = $h['role'] === 'user' ? 'Lead' : 'IA';
            $lines[] = "[{$who}] " . $h['content'];
        }
    }

    $lines[] = '--- FIM DO CONTEXTO ---';
    return implode("\n", $lines);
}
