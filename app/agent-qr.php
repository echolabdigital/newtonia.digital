<?php
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
header('Content-Type: application/json');

$channelId = (int)($_GET['id'] ?? 0);
if (!$channelId) { echo json_encode(['error' => 'invalid']); exit; }

$channel = db_one('SELECT * FROM agent_channels WHERE id = ? AND tenant_id = ?', [$channelId, (int)$tenant['id']]);
if (!$channel) { echo json_encode(['error' => 'not found']); exit; }

$cfg = json_decode($channel['config_json'], true) ?? [];
$qr  = zapi_get_qrcode($cfg['instance'] ?? '', $cfg['token'] ?? '', $cfg['client_token'] ?? '');

echo json_encode(['qr' => $qr]);
