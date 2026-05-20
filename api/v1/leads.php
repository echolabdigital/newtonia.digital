<?php
/**
 * Newton IA — POST /api/v1/leads
 *
 * Ingest de leads via API (HERMES Radar, Make, n8n, etc).
 * Cria/atualiza lead em uma lista (cria a lista se nao existir).
 *
 * Body JSON — DUAS formas:
 *
 *   (A) Lead unico:
 *       {
 *         "list":   "HERMES Radar — Vets Florianopolis",
 *         "lead":   { "name":"...", "phone":"...", "business":"...", "city":"...", "state":"...", "rating":4.8, "notes":"..." }
 *       }
 *
 *   (B) Lote:
 *       {
 *         "list":   "HERMES Radar — Vets Florianopolis",
 *         "leads":  [ {...}, {...}, ... ]
 *       }
 *
 * Dedup: por phone+list. Phone obrigatorio.
 *
 * Retorna: { ok, list_id, imported, skipped, ids: [...] }
 */
require_once __DIR__ . '/_bootstrap.php';

$ctx = api_boot('flux:write', ['POST']);
api_track($ctx);

$body    = $ctx['body'];
$tid     = (int)$ctx['tenant']['id'];
$listKey = trim((string)($body['list'] ?? 'API ingest'));
$single  = is_array($body['lead'] ?? null) ? [$body['lead']] : null;
$batch   = is_array($body['leads'] ?? null) ? $body['leads'] : null;
$leads   = $single ?: $batch;

if (!$leads) api_fail(400, 'missing_field', 'Envie "lead" (objeto) ou "leads" (array)');
if (count($leads) > 500) api_fail(400, 'batch_too_large', 'Maximo 500 leads por requisicao');

// Acha ou cria a lista pelo nome
$list = db_one('SELECT * FROM lead_lists WHERE tenant_id = ? AND name = ? LIMIT 1', [$tid, $listKey]);
if (!$list) {
    $listId = flux_list_create($tid, $listKey, 'api', (int)($ctx['key']['user_id'] ?? 0) ?: null);
    $list = db_one('SELECT * FROM lead_lists WHERE id = ?', [$listId]);
}
$listId = (int)$list['id'];

$imported = 0; $skipped = 0; $ids = [];
foreach ($leads as $l) {
    if (!is_array($l)) { $skipped++; continue; }
    $phone = preg_replace('/\D+/', '', (string)($l['phone'] ?? ''));
    if (!$phone || strlen($phone) < 8) { $skipped++; continue; }

    $exists = db_val('SELECT id FROM leads WHERE list_id = ? AND phone = ? LIMIT 1', [$listId, $phone]);
    if ($exists) { $skipped++; continue; }

    $id = flux_lead_add($tid, $listId, [
        'name'     => (string)($l['name']     ?? ''),
        'phone'    => $phone,
        'business' => (string)($l['business'] ?? ''),
        'city'     => (string)($l['city']     ?? ''),
        'state'    => (string)($l['state']    ?? ''),
        'rating'   => isset($l['rating']) ? (float)$l['rating'] : null,
        'notes'    => (string)($l['notes']    ?? ''),
        'source'   => (string)($l['source']   ?? 'hermes-radar'),
    ]);
    if ($id) { $imported++; $ids[] = (int)$id; }
    else { $skipped++; }
}
flux_list_refresh_count($listId);

api_ok([
    'list_id'  => $listId,
    'list'     => $list['name'],
    'imported' => $imported,
    'skipped'  => $skipped,
    'ids'      => $ids,
]);
