<?php
/**
 * Super-admin impersonation
 * GET /admin/impersonate.php?tenant_id=X  → entra no tenant como super-admin
 * GET /admin/impersonate.php?exit=1       → sai e volta pro /admin/
 */
require_once __DIR__ . '/../config.php';
require_super_admin();

auth_start_session();

// ── Sair da impersonação ─────────────────────────────────────────────────────
if (!empty($_GET['exit'])) {
    unset($_SESSION['tenant_id'], $_SESSION['impersonating']);
    header('Location: /admin/tenants.php');
    exit;
}

// ── Entrar em um tenant ──────────────────────────────────────────────────────
$tid = (int) ($_GET['tenant_id'] ?? 0);
if (!$tid) {
    header('Location: /admin/tenants.php');
    exit;
}

$tenant = db_one('SELECT id, name, status FROM tenants WHERE id = ?', [$tid]);
if (!$tenant) {
    http_response_code(404);
    echo 'Tenant não encontrado.';
    exit;
}

// Seta o tenant na sessão + flag de impersonação (pra mostrar banner no app)
$_SESSION['tenant_id']    = $tid;
$_SESSION['impersonating'] = [
    'tenant_id'   => $tid,
    'tenant_name' => $tenant['name'],
];

header('Location: /app/');
exit;
