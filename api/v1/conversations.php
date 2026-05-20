<?php
/**
 * Newton IA — /api/v1/conversations
 *   GET            ?agent_id=&status=&limit=&offset=    lista
 *   GET            ?id=123                              detalhes + ultimas N mensagens
 */
require_once __DIR__ . '/_bootstrap.php';

$ctx = api_boot('conversations:read', ['GET']);
api_track($ctx);

$tid = (int)$ctx['tenant']['id'];
$id  = (int)($_GET['id'] ?? 0);

if ($id) {
    $conv = db_one('SELECT * FROM conversations WHERE id = ? AND tenant_id = ? LIMIT 1', [$id, $tid]);
    if (!$conv) api_fail(404, 'not_found', "Conversa $id nao existe");
    $limit = max(1, min(200, (int)($_GET['messages_limit'] ?? 50)));
    $msgs  = db_all('SELECT id, direction, content, type, status, created_at FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ' . $limit, [$id]);
    api_ok(['conversation' => $conv, 'messages' => array_reverse($msgs)]);
}

$where  = ['tenant_id = ?']; $params = [$tid];
if (!empty($_GET['agent_id'])) { $where[] = 'agent_id = ?';  $params[] = (int)$_GET['agent_id']; }
if (!empty($_GET['status']))   { $where[] = 'status = ?';    $params[] = (string)$_GET['status']; }
$limit  = max(1, min(200, (int)($_GET['limit']  ?? 50)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$rows = db_all(
    'SELECT id, agent_id, contact_phone, contact_name, status, created_at, updated_at
     FROM conversations WHERE ' . implode(' AND ', $where) .
    ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
    $params
);

api_ok(['conversations' => $rows, 'count' => count($rows), 'limit' => $limit, 'offset' => $offset]);
