<?php
/**
 * Newton IA — PULSE
 * Agendamento 24/7 + qualificacao SPIN Selling.
 */

// ── Appointments CRUD ─────────────────────────────────────────────────────────

function pulse_create(int $tenantId, array $data): int {
    $starts = strtotime($data['starts_at'] ?? '');
    if (!$starts) throw new InvalidArgumentException('starts_at invalido');
    $agentId = (int)($data['agent_id'] ?? 0);
    $agent   = $agentId ? db_one('SELECT pulse_slot_min, pulse_meeting_kind, pulse_meeting_link FROM agents WHERE id = ? AND tenant_id = ?', [$agentId, $tenantId]) : null;
    $slotMin = (int)($agent['pulse_slot_min'] ?? 30);
    $ends    = $data['ends_at'] ? strtotime($data['ends_at']) : ($starts + $slotMin * 60);

    return db_insert('appointments', [
        'tenant_id'       => $tenantId,
        'agent_id'        => $agentId,
        'conversation_id' => !empty($data['conversation_id']) ? (int)$data['conversation_id'] : null,
        'contact_name'    => mb_substr((string)($data['contact_name'] ?? ''), 0, 200) ?: null,
        'contact_phone'   => preg_replace('/[^0-9+]/', '', (string)($data['contact_phone'] ?? '')) ?: null,
        'contact_email'   => mb_substr((string)($data['contact_email'] ?? ''), 0, 200) ?: null,
        'title'           => mb_substr((string)($data['title'] ?? 'Reuniao'), 0, 200),
        'notes'           => $data['notes'] ?? null,
        'starts_at'       => date('Y-m-d H:i:s', $starts),
        'ends_at'         => date('Y-m-d H:i:s', $ends),
        'meeting_kind'    => $data['meeting_kind'] ?? ($agent['pulse_meeting_kind'] ?? 'video'),
        'meeting_link'    => $data['meeting_link'] ?? ($agent['pulse_meeting_link'] ?? null),
        'status'          => $data['status'] ?? 'scheduled',
    ]);
}

function pulse_get(int $id, int $tenantId): ?array {
    return db_one('SELECT * FROM appointments WHERE id = ? AND tenant_id = ?', [$id, $tenantId]) ?: null;
}

function pulse_list(int $tenantId, ?string $fromDate = null, ?string $toDate = null, ?int $agentId = null): array {
    $sql    = 'SELECT a.*, ag.name AS agent_name FROM appointments a LEFT JOIN agents ag ON ag.id = a.agent_id WHERE a.tenant_id = ?';
    $params = [$tenantId];
    if ($fromDate) { $sql .= ' AND a.starts_at >= ?'; $params[] = $fromDate; }
    if ($toDate)   { $sql .= ' AND a.starts_at <= ?'; $params[] = $toDate; }
    if ($agentId)  { $sql .= ' AND a.agent_id = ?';   $params[] = $agentId; }
    $sql .= ' ORDER BY a.starts_at ASC';
    return db_all($sql, $params);
}

function pulse_update_status(int $id, int $tenantId, string $status): void {
    $allowed = ['scheduled','confirmed','rescheduled','cancelled','no_show','completed'];
    if (!in_array($status, $allowed, true)) return;
    $extra = '';
    if ($status === 'confirmed') $extra = ', confirmed_at = NOW()';
    if ($status === 'cancelled') $extra = ', cancelled_at = NOW()';
    db_q("UPDATE appointments SET status = ?$extra WHERE id = ? AND tenant_id = ?", [$status, $id, $tenantId]);
}

function pulse_delete(int $id, int $tenantId): void {
    db_q('DELETE FROM appointments WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
}

// ── Slot availability ─────────────────────────────────────────────────────────

/**
 * Gera slots disponiveis para um agente em uma data.
 * Considera business hours, slot_min, buffer_min e max_per_day.
 */
function pulse_slots_for_day(int $agentId, int $tenantId, string $date): array {
    $agent = db_one('SELECT pulse_hours, pulse_slot_min, pulse_buffer_min, pulse_max_per_day FROM agents WHERE id = ? AND tenant_id = ?', [$agentId, $tenantId]);
    if (!$agent) return [];

    $hours    = json_decode($agent['pulse_hours'] ?? '{}', true) ?: [];
    $slotMin  = max(15, (int)($agent['pulse_slot_min'] ?? 30));
    $buffer   = max(0,  (int)($agent['pulse_buffer_min'] ?? 0));
    $maxDay   = max(1,  (int)($agent['pulse_max_per_day'] ?? 10));

    $dow      = strtolower(date('D', strtotime($date))); // mon,tue,...
    $range    = $hours[$dow] ?? null;
    if (!$range || !preg_match('/(\d{2}:\d{2})-(\d{2}:\d{2})/', $range, $m)) return [];

    $start    = strtotime("$date $m[1]:00");
    $end      = strtotime("$date $m[2]:00");
    if ($end <= $start) return [];

    // Slots ja ocupados nessa data
    $busy = db_all(
        'SELECT starts_at, ends_at FROM appointments
         WHERE agent_id = ? AND tenant_id = ? AND status NOT IN ("cancelled","no_show")
           AND DATE(starts_at) = ?',
        [$agentId, $tenantId, $date]
    );
    if (count($busy) >= $maxDay) return [];

    $slots = [];
    $t     = $start;
    while ($t + $slotMin * 60 <= $end) {
        $st = $t;
        $et = $t + $slotMin * 60;
        $overlap = false;
        foreach ($busy as $b) {
            $bs = strtotime($b['starts_at']);
            $be = strtotime($b['ends_at']);
            if ($st < $be && $bs < $et) { $overlap = true; break; }
        }
        if (!$overlap) $slots[] = [
            'start' => date('H:i', $st),
            'end'   => date('H:i', $et),
            'iso'   => date('c', $st),
        ];
        $t = $et + $buffer * 60;
    }
    return $slots;
}

// ── Reminders (chamado pelo cron) ─────────────────────────────────────────────

/**
 * Envia lembretes 24h e 1h antes via WhatsApp Z-API.
 * Marca reminded_24h / reminded_1h.
 */
function pulse_send_reminders(): array {
    $sent24 = 0; $sent1 = 0;

    // 24h: appointments entre 23-25h no futuro, ainda nao lembrados
    $rows24 = db_all(
        "SELECT a.*, ag.name AS agent_name
         FROM appointments a JOIN agents ag ON ag.id = a.agent_id
         WHERE a.status IN ('scheduled','confirmed')
           AND a.reminded_24h = 0
           AND a.starts_at BETWEEN NOW() + INTERVAL 23 HOUR AND NOW() + INTERVAL 25 HOUR
         LIMIT 100"
    );
    foreach ($rows24 as $a) {
        if (pulse_fire_reminder($a, '24h')) {
            db_q('UPDATE appointments SET reminded_24h = 1 WHERE id = ?', [(int)$a['id']]);
            $sent24++;
        }
    }

    // 1h: entre 30-90min no futuro
    $rows1 = db_all(
        "SELECT a.*, ag.name AS agent_name
         FROM appointments a JOIN agents ag ON ag.id = a.agent_id
         WHERE a.status IN ('scheduled','confirmed')
           AND a.reminded_1h = 0
           AND a.starts_at BETWEEN NOW() + INTERVAL 30 MINUTE AND NOW() + INTERVAL 90 MINUTE
         LIMIT 100"
    );
    foreach ($rows1 as $a) {
        if (pulse_fire_reminder($a, '1h')) {
            db_q('UPDATE appointments SET reminded_1h = 1 WHERE id = ?', [(int)$a['id']]);
            $sent1++;
        }
    }
    return ['sent_24h' => $sent24, 'sent_1h' => $sent1];
}

function pulse_fire_reminder(array $appt, string $kind): bool {
    if (empty($appt['contact_phone'])) return false;
    $channel = db_one('SELECT * FROM agent_channels WHERE agent_id = ? AND status = "connected" LIMIT 1', [(int)$appt['agent_id']]);
    if (!$channel) return false;
    $cfg = json_decode($channel['config_json'] ?? '{}', true);
    if (empty($cfg['instance']) || empty($cfg['token']) || empty($cfg['client_token'])) return false;

    $when = date('d/m \à\s H:i', strtotime($appt['starts_at']));
    $name = explode(' ', trim($appt['contact_name'] ?? ''))[0] ?: 'ola';
    $linkLine = $appt['meeting_link'] ? "\nLink: " . $appt['meeting_link'] : '';

    $msg = match($kind) {
        '24h' => "Oi $name! Passando pra lembrar do nosso compromisso: *{$appt['title']}* amanha ($when).$linkLine\n\nVoce confirma? Responda *SIM* para confirmar ou *REAGENDAR* se precisar mudar.",
        '1h'  => "Oi $name! Nosso compromisso e em 1 hora ($when): *{$appt['title']}*.$linkLine\n\nTe espero ;)",
        default => '',
    };
    if ($msg === '') return false;

    return zapi_send_text($cfg['instance'], $cfg['token'], $cfg['client_token'], $appt['contact_phone'], $msg);
}

// ── SPIN Selling qualifier ────────────────────────────────────────────────────

/**
 * Analisa uma conversa via LLM e extrai SPIN (Situation, Problem, Implication, Need-payoff)
 * + temperatura do lead.
 */
function pulse_qualify_spin(int $convId): ?array {
    $conv = db_one('SELECT * FROM conversations WHERE id = ?', [$convId]);
    if (!$conv) return null;

    $msgs = db_all('SELECT direction, content FROM messages WHERE conversation_id = ? ORDER BY id ASC LIMIT 80', [$convId]);
    if (!$msgs) return null;

    $transcript = [];
    foreach ($msgs as $m) $transcript[] = ($m['direction'] === 'in' ? 'Cliente' : 'Agente') . ': ' . $m['content'];
    $text = implode("\n", $transcript);
    if (mb_strlen($text) > 8000) $text = mb_substr($text, -8000);

    $sys = "Voce e um especialista em SPIN Selling (Rackham). Analise a conversa e extraia, em portugues do Brasil, " .
           "APENAS em JSON valido sem texto fora:\n" .
           "{\"situation\":\"...\",\"problem\":\"...\",\"implication\":\"...\",\"need_payoff\":\"...\",\"score\":0-100,\"temperature\":\"cold|warm|hot\",\"next_step\":\"...\"}\n" .
           "- situation: contexto/cargo/empresa/uso atual (1-2 frases)\n" .
           "- problem: dor explicita ou implicita (1-2 frases)\n" .
           "- implication: consequencias da dor para o negocio (1-2 frases)\n" .
           "- need_payoff: valor que ele teria resolvendo isso (1-2 frases)\n" .
           "- score: 0-100 probabilidade de fechar negocio agora\n" .
           "- temperature: cold (<40), warm (40-70), hot (>70)\n" .
           "- next_step: melhor proxima acao do vendedor (1 frase)";

    $raw = llm_chat('groq', 'llama-3.3-70b-versatile',
        [['role'=>'system','content'=>$sys], ['role'=>'user','content'=>"Conversa:\n\n$text"]],
        ['max_tokens' => 700, 'temperature' => 0.2]);
    if (!$raw) return null;
    if (!preg_match('/\{.*\}/s', $raw, $m)) return null;
    $data = json_decode($m[0], true);
    if (!is_array($data)) return null;

    $score = max(0, min(100, (int)($data['score'] ?? 0)));
    $temp  = in_array($data['temperature'] ?? '', ['cold','warm','hot'], true) ? $data['temperature']
           : ($score >= 70 ? 'hot' : ($score >= 40 ? 'warm' : 'cold'));

    $payload = [
        'tenant_id'       => (int)$conv['tenant_id'],
        'conversation_id' => $convId,
        'situation'       => mb_substr($data['situation'] ?? '', 0, 1000),
        'problem'         => mb_substr($data['problem'] ?? '', 0, 1000),
        'implication'     => mb_substr($data['implication'] ?? '', 0, 1000),
        'need_payoff'     => mb_substr($data['need_payoff'] ?? '', 0, 1000),
        'score'           => $score,
        'temperature'     => $temp,
        'next_step'       => mb_substr($data['next_step'] ?? '', 0, 255),
        'model_used'      => 'groq/llama-3.3-70b-versatile',
    ];
    $exists = db_one('SELECT id FROM spin_qualifications WHERE conversation_id = ?', [$convId]);
    if ($exists) {
        db_q('UPDATE spin_qualifications SET situation = ?, problem = ?, implication = ?, need_payoff = ?, score = ?, temperature = ?, next_step = ?, model_used = ? WHERE conversation_id = ?',
            [$payload['situation'],$payload['problem'],$payload['implication'],$payload['need_payoff'],$payload['score'],$payload['temperature'],$payload['next_step'],$payload['model_used'],$convId]);
    } else {
        db_insert('spin_qualifications', $payload);
    }
    return $payload;
}

function pulse_spin_get(int $convId): ?array {
    return db_one('SELECT * FROM spin_qualifications WHERE conversation_id = ?', [$convId]) ?: null;
}
