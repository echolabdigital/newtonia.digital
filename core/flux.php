<?php
/**
 * Newton IA — FLUX
 * Lead extraction + Campaigns orquestradas com IA personalizada por contato.
 *
 * Diferencial vs Lailla/Connect: cada lead recebe uma mensagem unica gerada
 * pela IA baseada em (template + dados do lead + persona do agente).
 */

// ── Lead lists ────────────────────────────────────────────────────────────────

function flux_list_create(int $tenantId, string $name, string $source = 'manual', ?int $userId = null): int {
    return db_insert('lead_lists', [
        'tenant_id'  => $tenantId,
        'name'       => mb_substr($name, 0, 160),
        'source'     => in_array($source, ['manual','csv','google_maps','api','hermes'], true) ? $source : 'manual',
        'created_by' => $userId,
    ]);
}

function flux_list_get(int $id, int $tenantId): ?array {
    return db_one('SELECT * FROM lead_lists WHERE id = ? AND tenant_id = ?', [$id, $tenantId]) ?: null;
}

function flux_lists(int $tenantId): array {
    return db_all('SELECT * FROM lead_lists WHERE tenant_id = ? ORDER BY id DESC', [$tenantId]);
}

function flux_list_delete(int $id, int $tenantId): void {
    db_q('DELETE FROM leads WHERE list_id = ? AND tenant_id = ?', [$id, $tenantId]);
    db_q('DELETE FROM lead_lists WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
}

function flux_list_refresh_count(int $listId): void {
    db_q('UPDATE lead_lists SET lead_count = (SELECT COUNT(*) FROM leads WHERE list_id = ?) WHERE id = ?', [$listId, $listId]);
}

// ── Leads ─────────────────────────────────────────────────────────────────────

function flux_lead_normalize_phone(?string $raw): ?string {
    if (!$raw) return null;
    $d = preg_replace('/\D/', '', $raw);
    if (strlen($d) < 8) return null;
    // Brasil: garante DDI 55
    if (strlen($d) === 10 || strlen($d) === 11) $d = '55' . $d;
    return $d;
}

function flux_lead_add(int $tenantId, int $listId, array $data): ?int {
    $phone = flux_lead_normalize_phone($data['phone'] ?? null);
    $payload = [
        'list_id'   => $listId,
        'tenant_id' => $tenantId,
        'name'      => mb_substr((string)($data['name']     ?? ''), 0, 200) ?: null,
        'phone'     => $phone,
        'email'     => mb_substr((string)($data['email']    ?? ''), 0, 200) ?: null,
        'business'  => mb_substr((string)($data['business'] ?? ''), 0, 200) ?: null,
        'address'   => mb_substr((string)($data['address']  ?? ''), 0, 400) ?: null,
        'city'      => mb_substr((string)($data['city']     ?? ''), 0, 100) ?: null,
        'state'     => mb_substr((string)($data['state']    ?? ''), 0, 40)  ?: null,
        'rating'    => isset($data['rating']) ? (float)$data['rating'] : null,
        'raw_json'  => isset($data['raw']) ? json_encode($data['raw'], JSON_UNESCAPED_UNICODE) : null,
    ];
    // dedup por (list_id, phone)
    if ($phone) {
        $exists = db_val('SELECT id FROM leads WHERE list_id = ? AND phone = ? LIMIT 1', [$listId, $phone]);
        if ($exists) return null;
    }
    return db_insert('leads', $payload);
}

function flux_leads(int $listId, int $tenantId, int $limit = 200, int $offset = 0): array {
    return db_all(
        'SELECT * FROM leads WHERE list_id = ? AND tenant_id = ? ORDER BY id DESC LIMIT ' . max(1,min(2000,$limit)) . ' OFFSET ' . max(0,$offset),
        [$listId, $tenantId]
    );
}

/**
 * Importa CSV. Espera colunas (case-insensitive, qualquer ordem):
 *   name, phone, email, business, address, city, state, rating
 * Retorna [imported, skipped, errors].
 */
function flux_import_csv(int $tenantId, int $listId, string $csvPath): array {
    $h = fopen($csvPath, 'r');
    if (!$h) return ['imported'=>0,'skipped'=>0,'errors'=>['cannot_open_file']];

    $header = fgetcsv($h, 0, ',', '"', '\\');
    if (!$header) { fclose($h); return ['imported'=>0,'skipped'=>0,'errors'=>['empty_csv']]; }
    $cols = array_map(fn($c) => strtolower(trim($c)), $header);
    $idx  = array_flip($cols);

    $imported = 0; $skipped = 0; $errors = [];
    while (($row = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
        $get = fn($k) => isset($idx[$k]) && isset($row[$idx[$k]]) ? trim($row[$idx[$k]]) : null;
        $data = [
            'name'     => $get('name')     ?? $get('nome'),
            'phone'    => $get('phone')    ?? ($get('telefone') ?? $get('whatsapp')),
            'email'    => $get('email'),
            'business' => $get('business') ?? ($get('negocio') ?? $get('empresa')),
            'address'  => $get('address')  ?? $get('endereco'),
            'city'     => $get('city')     ?? $get('cidade'),
            'state'    => $get('state')    ?? ($get('estado') ?? $get('uf')),
            'rating'   => $get('rating')   ?? $get('avaliacao'),
            'raw'      => $row,
        ];
        if (empty($data['phone']) && empty($data['email'])) { $skipped++; continue; }
        $id = flux_lead_add($tenantId, $listId, $data);
        if ($id) $imported++; else $skipped++;
    }
    fclose($h);
    flux_list_refresh_count($listId);
    return ['imported'=>$imported,'skipped'=>$skipped,'errors'=>$errors];
}

// ── Google Maps scraper (Places API Text Search) ──────────────────────────────

/**
 * Busca leads no Google Maps via Places API.
 * Requer: setting_set('google.places_api_key', 'AIza...')
 *
 * $query = "veterinarios em florianopolis"
 * Retorna numero de leads adicionados.
 */
function flux_scrape_google_maps(int $tenantId, int $listId, string $query, int $maxResults = 60): array {
    $key = setting_get('google.places_api_key');
    if (!$key) return ['ok'=>false, 'error'=>'google.places_api_key nao configurada em /admin/integrations.php'];

    $added = 0; $pageToken = null; $pages = 0;
    do {
        $url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?query=' . urlencode($query) . '&language=pt-BR&key=' . $key;
        if ($pageToken) $url .= '&pagetoken=' . $pageToken;

        $resp = @file_get_contents($url);
        if (!$resp) break;
        $data = json_decode($resp, true);
        if (!isset($data['results']) || !is_array($data['results'])) break;

        foreach ($data['results'] as $r) {
            if ($added >= $maxResults) break 2;

            // Busca detalhes (telefone) — chamada extra
            $detailUrl = 'https://maps.googleapis.com/maps/api/place/details/json?place_id=' . urlencode($r['place_id']) . '&fields=name,formatted_phone_number,international_phone_number,formatted_address,website,rating&language=pt-BR&key=' . $key;
            $det = @json_decode(@file_get_contents($detailUrl), true);
            $detail = $det['result'] ?? [];

            $phone = $detail['international_phone_number'] ?? ($detail['formatted_phone_number'] ?? null);
            $addr  = $detail['formatted_address'] ?? ($r['formatted_address'] ?? '');
            // Parse cidade/estado do endereco (heuristico)
            $city = null; $state = null;
            if (preg_match('/,\s*([^,-]+)\s*-\s*([A-Z]{2})\b/', $addr, $m)) {
                $city = trim($m[1]); $state = $m[2];
            }

            $id = flux_lead_add($tenantId, $listId, [
                'name'     => $r['name']      ?? null,
                'business' => $r['name']      ?? null,
                'phone'    => $phone,
                'address'  => $addr,
                'city'     => $city,
                'state'    => $state,
                'rating'   => $r['rating']    ?? null,
                'raw'      => ['place_id' => $r['place_id'], 'types' => $r['types'] ?? [], 'website' => $detail['website'] ?? null],
            ]);
            if ($id) $added++;
        }

        $pageToken = $data['next_page_token'] ?? null;
        $pages++;
        if ($pageToken) sleep(2); // Places API exige delay entre paginas
    } while ($pageToken && $pages < 3 && $added < $maxResults);

    flux_list_refresh_count($listId);
    return ['ok'=>true, 'added'=>$added, 'pages'=>$pages];
}

// ── Campanhas ─────────────────────────────────────────────────────────────────

function flux_campaign_create(int $tenantId, array $data, ?int $userId = null): int {
    $payload = [
        'tenant_id'        => $tenantId,
        'name'             => mb_substr($data['name'] ?? 'Campanha', 0, 160),
        'agent_id'         => (int)($data['agent_id'] ?? 0),
        'list_id'          => (int)($data['list_id']  ?? 0),
        'channel_id'       => !empty($data['channel_id']) ? (int)$data['channel_id'] : null,
        'template'         => trim((string)($data['template'] ?? '')),
        'personalize'      => !empty($data['personalize']) ? 1 : 0,
        'throttle_per_min' => max(1, min(40, (int)($data['throttle_per_min'] ?? 8))),
        'daily_cap'        => max(10, min(5000, (int)($data['daily_cap'] ?? 500))),
        'status'           => 'draft',
        'created_by'       => $userId,
    ];
    return db_insert('campaigns', $payload);
}

function flux_campaign_get(int $id, int $tenantId): ?array {
    return db_one('SELECT * FROM campaigns WHERE id = ? AND tenant_id = ?', [$id, $tenantId]) ?: null;
}

function flux_campaigns(int $tenantId): array {
    return db_all(
        'SELECT c.*, a.name AS agent_name, l.name AS list_name
         FROM campaigns c
         LEFT JOIN agents a ON a.id = c.agent_id
         LEFT JOIN lead_lists l ON l.id = c.list_id
         WHERE c.tenant_id = ? ORDER BY c.id DESC',
        [$tenantId]
    );
}

/**
 * Prepara a fila de campaign_messages para uma campanha (1 por lead).
 * Esvazia a fila anterior. Retorna total de mensagens enfileiradas.
 */
function flux_campaign_enqueue(int $campaignId, int $tenantId): int {
    $c = flux_campaign_get($campaignId, $tenantId);
    if (!$c) return 0;

    db_q('DELETE FROM campaign_messages WHERE campaign_id = ?', [$campaignId]);
    $leads = db_all('SELECT id FROM leads WHERE list_id = ? AND tenant_id = ? AND phone IS NOT NULL', [(int)$c['list_id'], $tenantId]);

    $count = 0;
    foreach ($leads as $l) {
        db_q('INSERT INTO campaign_messages (campaign_id, lead_id, tenant_id, status) VALUES (?, ?, ?, "pending")',
            [$campaignId, (int)$l['id'], $tenantId]);
        $count++;
    }
    db_q('UPDATE campaigns SET total = ?, sent = 0, failed = 0, replied = 0 WHERE id = ?', [$count, $campaignId]);
    return $count;
}

function flux_campaign_start(int $campaignId, int $tenantId): bool {
    $c = flux_campaign_get($campaignId, $tenantId);
    if (!$c) return false;
    if ($c['total'] === 0 || $c['total'] === '0') flux_campaign_enqueue($campaignId, $tenantId);
    db_q('UPDATE campaigns SET status = "running", started_at = COALESCE(started_at, NOW()) WHERE id = ?', [$campaignId]);
    return true;
}

function flux_campaign_pause(int $campaignId, int $tenantId): void {
    db_q('UPDATE campaigns SET status = "paused" WHERE id = ? AND tenant_id = ?', [$campaignId, $tenantId]);
}

function flux_campaign_delete(int $id, int $tenantId): void {
    db_q('DELETE FROM campaign_messages WHERE campaign_id = ?', [$id]);
    db_q('DELETE FROM campaigns WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
}

/**
 * Personaliza a mensagem para 1 lead usando o LLM do agente.
 * Se personalize=0 ou LLM falhar, faz substituicao basica de placeholders.
 *
 * Placeholders suportados no template: {name}, {first_name}, {business}, {city}
 */
function flux_personalize_message(array $campaign, array $lead, array $agent): string {
    $first = explode(' ', trim($lead['name'] ?? ''))[0] ?: 'Ola';
    $tpl   = (string)$campaign['template'];

    // Substituicao basica de placeholders
    $basic = str_replace(
        ['{name}','{first_name}','{business}','{city}','{state}'],
        [$lead['name'] ?? 'amigo', $first, $lead['business'] ?? '', $lead['city'] ?? '', $lead['state'] ?? ''],
        $tpl
    );

    if (empty($campaign['personalize'])) return $basic;

    // IA personaliza usando dados do lead
    $sys = "Voce e um especialista em mensagens de abordagem comercial via WhatsApp. " .
           "Reescreva a mensagem template adaptando ao lead especifico (use o nome, contexto do negocio e cidade quando relevante). " .
           "Mantenha o tom natural, em portugues do Brasil, 2-4 frases, NUNCA cite que e automatica. " .
           "Responda APENAS com a mensagem final, sem aspas, sem explicacoes.";
    $usr = "Persona do agente:\n" . trim((string)($agent['prompt'] ?? '')) . "\n\n" .
           "Template base:\n" . $tpl . "\n\n" .
           "Dados do lead:\n" .
           "- Nome: " . ($lead['name'] ?? '?') . "\n" .
           "- Negocio: " . ($lead['business'] ?? '?') . "\n" .
           "- Cidade: " . ($lead['city'] ?? '?') . " - " . ($lead['state'] ?? '?') . "\n" .
           ($lead['rating'] ? "- Avaliacao Google: " . $lead['rating'] . "\n" : '') .
           "\nReescreva a mensagem para esse lead especifico:";

    $provider = $agent['provider'] ?: llm_provider_from_model($agent['model'] ?? '');
    $reply    = llm_chat($provider, $agent['model'] ?: 'llama-3.3-70b-versatile',
        [['role'=>'system','content'=>$sys],['role'=>'user','content'=>$usr]],
        ['max_tokens' => 300, 'temperature' => 0.7]
    );
    return $reply ? trim($reply) : $basic;
}

/**
 * Processa N mensagens pendentes de uma campanha (chamado pelo cron).
 * Respeita throttle e daily_cap.
 * Retorna [processed, sent, failed].
 */
function flux_campaign_dispatch(int $campaignId, int $batchSize = 5): array {
    $c = db_one('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
    if (!$c || $c['status'] !== 'running') return ['processed'=>0,'sent'=>0,'failed'=>0,'skip'=>'not_running'];

    // Daily cap
    $sentToday = (int) db_val(
        'SELECT COUNT(*) FROM campaign_messages WHERE campaign_id = ? AND status = "sent" AND sent_at > NOW() - INTERVAL 1 DAY',
        [$campaignId]
    );
    if ($sentToday >= (int)$c['daily_cap']) return ['processed'=>0,'sent'=>0,'failed'=>0,'skip'=>'daily_cap'];

    $agent   = db_one('SELECT * FROM agents WHERE id = ?', [(int)$c['agent_id']]);
    $channel = $c['channel_id'] ? db_one('SELECT * FROM agent_channels WHERE id = ?', [(int)$c['channel_id']]) : null;
    if (!$agent || !$channel) return ['processed'=>0,'sent'=>0,'failed'=>0,'skip'=>'missing_agent_or_channel'];

    $cfg = json_decode($channel['config_json'] ?? '{}', true);
    if (empty($cfg['instance']) || empty($cfg['token']) || empty($cfg['client_token'])) {
        return ['processed'=>0,'sent'=>0,'failed'=>0,'skip'=>'channel_credentials_missing'];
    }

    $pending = db_all(
        'SELECT cm.*, l.name AS lead_name, l.phone AS lead_phone, l.business AS lead_business,
                l.city AS lead_city, l.state AS lead_state, l.rating AS lead_rating
         FROM campaign_messages cm JOIN leads l ON l.id = cm.lead_id
         WHERE cm.campaign_id = ? AND cm.status = "pending" ORDER BY cm.id ASC LIMIT ?',
        [$campaignId, $batchSize]
    );

    $sent = 0; $failed = 0;
    $intervalSec = max(2, (int)(60 / max(1,(int)$c['throttle_per_min'])));

    foreach ($pending as $m) {
        db_q('UPDATE campaign_messages SET status = "sending" WHERE id = ?', [(int)$m['id']]);
        $lead = [
            'name' => $m['lead_name'], 'phone' => $m['lead_phone'], 'business' => $m['lead_business'],
            'city' => $m['lead_city'], 'state' => $m['lead_state'], 'rating' => $m['lead_rating'],
        ];

        $content = flux_personalize_message($c, $lead, $agent);

        // Cria conversa
        $conv = synapse_get_or_create_conversation((int)$c['agent_id'], (int)$c['tenant_id'], (int)$channel['id'], $lead['phone'], (string)($lead['name'] ?? ''));
        synapse_save_message((int)$conv['id'], 'out', $content);

        $ok = zapi_send_text($cfg['instance'], $cfg['token'], $cfg['client_token'], $lead['phone'], $content);
        if ($ok) {
            db_q('UPDATE campaign_messages SET status = "sent", content = ?, conversation_id = ?, sent_at = NOW() WHERE id = ?',
                [$content, (int)$conv['id'], (int)$m['id']]);
            db_q('UPDATE campaigns SET sent = sent + 1 WHERE id = ?', [$campaignId]);
            db_q('UPDATE leads SET status = "contacted", last_status_at = NOW() WHERE id = ?', [(int)$m['lead_id']]);
            $sent++;
        } else {
            db_q('UPDATE campaign_messages SET status = "failed", error = "zapi_send_failed", content = ? WHERE id = ?',
                [$content, (int)$m['id']]);
            db_q('UPDATE campaigns SET failed = failed + 1 WHERE id = ?', [$campaignId]);
            $failed++;
        }

        // Anti-ban: throttle
        if (count($pending) > 1) sleep($intervalSec);
    }

    // Concluida?
    $remaining = (int) db_val('SELECT COUNT(*) FROM campaign_messages WHERE campaign_id = ? AND status = "pending"', [$campaignId]);
    if ($remaining === 0) {
        db_q('UPDATE campaigns SET status = "completed", completed_at = NOW() WHERE id = ?', [$campaignId]);
    }

    return ['processed' => count($pending), 'sent' => $sent, 'failed' => $failed];
}
