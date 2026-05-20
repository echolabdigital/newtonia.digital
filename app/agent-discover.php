<?php
/**
 * Newton IA — Lista instancias Z-API disponiveis na conta Partner
 * Auto-discover via global zapi.partner_token configurado no admin.
 *
 * GET: retorna JSON com instancias filtradas por middleware (mobile by default)
 *      e indica quais ja estao em uso por outros agentes do tenant.
 */
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/../core/zapi.php';

header('Content-Type: application/json');

$partner = setting_get('zapi.partner_token') ?: '';
$client  = setting_get('zapi.default_client_token') ?: '';

if (!$partner) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Partner Token Z-API nao configurado. Pedir ao admin pra cadastrar em Integracoes.',
    ]);
    exit;
}

$middleware = $_GET['middleware'] ?? 'mobile'; // mobile | web | all
$tid        = (int) $tenant['id'];

// Lista instancias da conta Z-API
$list = zapi_partner_list($partner);
if (!is_array($list) || empty($list)) {
    echo json_encode([
        'ok'        => true,
        'instances' => [],
        'note'      => 'Nenhuma instancia encontrada na conta Z-API.',
    ]);
    exit;
}

// Filtra middleware
if ($middleware !== 'all') {
    $list = array_filter($list, fn($i) => ($i['middleware'] ?? 'web') === $middleware);
}

// Instancias ja em uso (qualquer tenant — pra evitar bind duplicado)
$inUse = db_all('SELECT config_json, tenant_id FROM agent_channels');
$usedIds = [];
foreach ($inUse as $row) {
    $cfg = json_decode($row['config_json'] ?? '{}', true);
    if (!empty($cfg['instance'])) {
        $usedIds[$cfg['instance']] = (int)$row['tenant_id'];
    }
}

$out = [];
foreach ($list as $inst) {
    $id      = $inst['id'] ?? '';
    $usedBy  = $usedIds[$id] ?? null;
    $out[] = [
        'id'              => $id,
        'token'           => $inst['token'] ?? '',
        'name'            => $inst['name']  ?? '',
        'middleware'      => $inst['middleware'] ?? 'web',
        'phone_connected' => $inst['phoneConnected'] ?? null,
        'in_use'          => $usedBy !== null,
        'in_use_by_me'    => $usedBy === $tid,
    ];
}

echo json_encode([
    'ok'           => true,
    'client_token' => $client, // default — agente preenche automaticamente
    'instances'    => array_values($out),
]);
