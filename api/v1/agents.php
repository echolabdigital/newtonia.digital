<?php
/**
 * Newton IA — GET /api/v1/agents
 * Lista agentes do tenant autenticado.
 */
require_once __DIR__ . '/_bootstrap.php';

$ctx = api_boot('agents:read', ['GET']);
api_track($ctx);

$rows = db_all(
    'SELECT id, name, status, provider, model, context_window, created_at
     FROM agents WHERE tenant_id = ? ORDER BY id DESC',
    [(int)$ctx['tenant']['id']]
);

api_ok(['agents' => $rows, 'count' => count($rows)]);
