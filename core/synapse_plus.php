<?php
/**
 * Newton IA — SYNAPSE Plus
 * Knowledge base injection + keyword triggers + conversation summarization.
 *
 * Filosofia: complementa core/synapse.php sem reescreve-lo.
 */

// ── Knowledge Base ────────────────────────────────────────────────────────────

function kb_list(int $agentId, int $tenantId): array {
    return db_all(
        'SELECT id, title, source_type, source_url, enabled, char_count, updated_at
         FROM agent_knowledge WHERE agent_id = ? AND tenant_id = ? ORDER BY id DESC',
        [$agentId, $tenantId]
    );
}

function kb_get(int $id, int $tenantId): ?array {
    return db_one('SELECT * FROM agent_knowledge WHERE id = ? AND tenant_id = ?', [$id, $tenantId]) ?: null;
}

function kb_save(int $tenantId, int $agentId, array $data, ?int $id = null): int {
    $payload = [
        'title'       => mb_substr(trim((string)($data['title'] ?? '')), 0, 200),
        'content'     => trim((string)($data['content'] ?? '')),
        'source_type' => in_array($data['source_type'] ?? 'text', ['text','url','file'], true) ? $data['source_type'] : 'text',
        'source_url'  => $data['source_url'] ?? null,
        'enabled'     => !empty($data['enabled']) ? 1 : 0,
        'char_count'  => mb_strlen((string)($data['content'] ?? '')),
    ];
    if ($id) {
        $sets = []; $params = [];
        foreach ($payload as $k => $v) { $sets[] = "$k = ?"; $params[] = $v; }
        $params[] = $id; $params[] = $tenantId;
        db_q('UPDATE agent_knowledge SET ' . implode(', ', $sets) . ' WHERE id = ? AND tenant_id = ?', $params);
        return $id;
    }
    $payload['agent_id']  = $agentId;
    $payload['tenant_id'] = $tenantId;
    return db_insert('agent_knowledge', $payload);
}

function kb_delete(int $id, int $tenantId): void {
    db_q('DELETE FROM agent_knowledge WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
}

/**
 * Retorna trechos relevantes para injetar no system prompt.
 * V1: retorna todos os snippets enabled (limite total de chars).
 * V2 (futuro): embeddings + similaridade.
 */
function kb_fetch_for_prompt(int $agentId, int $maxChars = 6000): string {
    $rows = db_all(
        'SELECT title, content FROM agent_knowledge WHERE agent_id = ? AND enabled = 1 ORDER BY id ASC',
        [$agentId]
    );
    if (!$rows) return '';
    $out = []; $total = 0;
    foreach ($rows as $r) {
        $block = "### " . $r['title'] . "\n" . trim($r['content']);
        if ($total + mb_strlen($block) > $maxChars) {
            $remaining = $maxChars - $total;
            if ($remaining > 200) $out[] = mb_substr($block, 0, $remaining) . "\n[...]";
            break;
        }
        $out[] = $block;
        $total += mb_strlen($block);
    }
    return implode("\n\n", $out);
}

// ── Keyword Triggers ──────────────────────────────────────────────────────────

function kw_list(int $agentId, int $tenantId): array {
    return db_all(
        'SELECT * FROM agent_keywords WHERE agent_id = ? AND tenant_id = ? ORDER BY id DESC',
        [$agentId, $tenantId]
    );
}

function kw_save(int $tenantId, int $agentId, array $data, ?int $id = null): int {
    $payload = [
        'keyword'     => mb_substr(trim((string)($data['keyword'] ?? '')), 0, 120),
        'match_type'  => in_array($data['match_type'] ?? 'contains', ['contains','exact','starts_with','regex'], true) ? $data['match_type'] : 'contains',
        'action'      => in_array($data['action'] ?? 'handoff', ['handoff','tag','webhook','pause'], true) ? $data['action'] : 'handoff',
        'action_data' => mb_substr((string)($data['action_data'] ?? ''), 0, 255),
        'direction'   => in_array($data['direction'] ?? 'in', ['in','out','any'], true) ? $data['direction'] : 'in',
        'active'      => !empty($data['active']) ? 1 : 0,
    ];
    if ($id) {
        $sets = []; $params = [];
        foreach ($payload as $k => $v) { $sets[] = "$k = ?"; $params[] = $v; }
        $params[] = $id; $params[] = $tenantId;
        db_q('UPDATE agent_keywords SET ' . implode(', ', $sets) . ' WHERE id = ? AND tenant_id = ?', $params);
        return $id;
    }
    $payload['agent_id']  = $agentId;
    $payload['tenant_id'] = $tenantId;
    return db_insert('agent_keywords', $payload);
}

function kw_delete(int $id, int $tenantId): void {
    db_q('DELETE FROM agent_keywords WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
}

function kw_matches(string $text, string $keyword, string $matchType): bool {
    $t = mb_strtolower($text);
    $k = mb_strtolower($keyword);
    return match($matchType) {
        'exact'       => $t === $k,
        'starts_with' => str_starts_with($t, $k),
        'regex'       => @preg_match('/' . $keyword . '/iu', $text) === 1,
        default       => str_contains($t, $k),
    };
}

/**
 * Avalia keywords para uma mensagem. Executa ações e retorna lista de acoes disparadas.
 * Direction: 'in' (inbound) ou 'out' (outbound).
 *
 * Retorna ['handoff' => bool, 'pause' => bool, 'tags' => [...], 'webhooks' => [...]]
 */
function kw_evaluate(int $agentId, int $tenantId, int $convId, string $text, string $direction): array {
    $rules = db_all(
        'SELECT * FROM agent_keywords WHERE agent_id = ? AND tenant_id = ? AND active = 1 AND direction IN (?, "any")',
        [$agentId, $tenantId, $direction]
    );

    $result = ['handoff' => false, 'pause' => false, 'tags' => [], 'webhooks' => []];
    foreach ($rules as $r) {
        if (!kw_matches($text, $r['keyword'], $r['match_type'])) continue;
        db_q('UPDATE agent_keywords SET hit_count = hit_count + 1 WHERE id = ?', [(int)$r['id']]);

        switch ($r['action']) {
            case 'handoff':
                $result['handoff'] = true;
                db_q('UPDATE conversations SET status = "human" WHERE id = ?', [$convId]);
                break;
            case 'pause':
                $result['pause'] = true;
                db_q('UPDATE conversations SET status = "paused" WHERE id = ?', [$convId]);
                break;
            case 'tag':
                $tag = trim($r['action_data']) ?: trim($r['keyword']);
                @db_q('INSERT IGNORE INTO conversation_tags (conversation_id, tag, source) VALUES (?, ?, "keyword")', [$convId, $tag]);
                $result['tags'][] = $tag;
                break;
            case 'webhook':
                $url = trim($r['action_data']);
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $result['webhooks'][] = $url;
                    @kw_fire_webhook($url, [
                        'event' => 'keyword.matched',
                        'agent_id' => $agentId,
                        'conversation_id' => $convId,
                        'keyword' => $r['keyword'],
                        'text' => $text,
                        'direction' => $direction,
                    ]);
                }
                break;
        }
    }
    return $result;
}

function kw_fire_webhook(string $url, array $payload): void {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Newton-Source: keyword-trigger'],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── Conversation Summaries ────────────────────────────────────────────────────

/**
 * Gera resumo de conversa usando o LLM do agente.
 * Retorna o resumo ou null em erro.
 */
function conv_summarize(int $convId): ?array {
    $conv = db_one('SELECT * FROM conversations WHERE id = ?', [$convId]);
    if (!$conv) return null;
    $agent = db_one('SELECT * FROM agents WHERE id = ?', [(int)$conv['agent_id']]);
    if (!$agent) return null;

    $msgs = db_all(
        'SELECT direction, content FROM messages WHERE conversation_id = ? ORDER BY id ASC LIMIT 50',
        [$convId]
    );
    if (!$msgs) return null;

    $transcript = [];
    foreach ($msgs as $m) {
        $who = $m['direction'] === 'in' ? 'Cliente' : 'Agente';
        $transcript[] = "$who: " . $m['content'];
    }
    $text = implode("\n", $transcript);
    if (mb_strlen($text) > 8000) $text = mb_substr($text, -8000);

    $sys = "Voce e um assistente que resume conversas de atendimento. Responda APENAS em JSON valido, sem texto fora.\n" .
           "Formato exato: {\"summary\":\"...\",\"sentiment\":\"positive|neutral|negative|urgent\",\"intent\":\"...\",\"next_step\":\"...\"}\n" .
           "summary: 2-3 frases em portugues do Brasil descrevendo o que aconteceu.\n" .
           "sentiment: tom do cliente.\n" .
           "intent: o que o cliente quer (5-10 palavras).\n" .
           "next_step: a melhor proxima acao para o humano (1 frase pratica).";

    $messages = [
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => "Resuma esta conversa:\n\n$text"],
    ];

    // Usa modelo rapido pra resumir (Groq llama 8b por padrao, custo zero)
    $provider = 'groq';
    $model    = 'llama-3.1-8b-instant';

    $raw = llm_chat($provider, $model, $messages, ['max_tokens' => 400, 'temperature' => 0.3]);
    if (!$raw) return null;

    // Extrai JSON (tolerante a texto extra)
    if (!preg_match('/\{.*\}/s', $raw, $m)) return null;
    $data = json_decode($m[0], true);
    if (!is_array($data) || empty($data['summary'])) return null;

    $payload = [
        'conversation_id' => $convId,
        'tenant_id'       => (int)$conv['tenant_id'],
        'summary'         => mb_substr($data['summary'], 0, 2000),
        'sentiment'       => in_array($data['sentiment'] ?? '', ['positive','neutral','negative','urgent'], true) ? $data['sentiment'] : 'neutral',
        'intent'          => mb_substr($data['intent'] ?? '', 0, 120),
        'next_step'       => mb_substr($data['next_step'] ?? '', 0, 255),
        'generated_by'    => 'auto',
        'model_used'      => "$provider/$model",
    ];

    // upsert
    $exists = db_one('SELECT id FROM conversation_summaries WHERE conversation_id = ?', [$convId]);
    if ($exists) {
        db_q('UPDATE conversation_summaries SET summary = ?, sentiment = ?, intent = ?, next_step = ?, model_used = ?, created_at = NOW() WHERE conversation_id = ?',
            [$payload['summary'], $payload['sentiment'], $payload['intent'], $payload['next_step'], $payload['model_used'], $convId]);
    } else {
        db_insert('conversation_summaries', $payload);
    }
    return $payload;
}

function conv_summary_get(int $convId): ?array {
    return db_one('SELECT * FROM conversation_summaries WHERE conversation_id = ?', [$convId]) ?: null;
}

function conv_tags_get(int $convId): array {
    $rows = db_all('SELECT tag FROM conversation_tags WHERE conversation_id = ?', [$convId]);
    return array_column($rows, 'tag');
}

// ── Trigger handoff: gera resumo + dispara webhook ────────────────────────────

function synapse_trigger_handoff(int $tenantId, array $conv, string $reason = 'manual'): array {
    db_q('UPDATE conversations SET status = "human" WHERE id = ?', [(int)$conv['id']]);
    $summary = conv_summarize((int)$conv['id']);
    if (function_exists('webhook_fire')) {
        webhook_fire($tenantId, 'handoff.requested', [
            'conversation_id' => (int)$conv['id'],
            'agent_id'        => (int)$conv['agent_id'],
            'reason'          => $reason,
            'contact'         => ['phone' => $conv['contact_phone'] ?? null, 'name' => $conv['contact_name'] ?? null],
            'summary'         => $summary,
        ]);
    }
    return $summary ?? [];
}
