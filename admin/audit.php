<?php
require_once __DIR__ . '/../config.php';
require_super_admin();
require_once __DIR__ . '/_layout.php';

$tenant_filter = isset($_GET['tenant']) ? (int) $_GET['tenant'] : 0;
$limit = 100;

$where = []; $params = [];
if ($tenant_filter) { $where[] = 'al.tenant_id = ?'; $params[] = $tenant_filter; }
$wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows = db_all(
    "SELECT al.*, u.email AS user_email, t.name AS tenant_name
     FROM audit_log al
     LEFT JOIN users u   ON u.id = al.user_id
     LEFT JOIN tenants t ON t.id = al.tenant_id
     $wsql
     ORDER BY al.created_at DESC
     LIMIT $limit",
    $params
);

$tenants_for_filter = db_all("SELECT id, name FROM tenants ORDER BY name");

admin_layout('Audit Log', 'audit', function() use ($rows, $tenants_for_filter, $tenant_filter, $limit) {
?>
<div class="panel">
  <form method="GET" style="display:flex;gap:1rem;align-items:end;margin-bottom:1.5rem;">
    <div style="flex:1;">
      <label>Filtrar por tenant</label>
      <select name="tenant">
        <option value="0">Todos</option>
        <?php foreach ($tenants_for_filter as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $tenant_filter===(int)$t['id']?'selected':'' ?>><?= e($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn-action secondary">Aplicar</button>
  </form>

  <p style="color:var(--ink-3);font-size:.8rem;margin-bottom:1rem;">Últimos <?= $limit ?> eventos</p>

  <table>
    <thead>
      <tr><th>Quando</th><th>Tenant</th><th>Usuário</th><th>Ação</th><th>Alvo</th><th>IP</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td style="font-size:.75rem;color:var(--ink-3);font-family:monospace;"><?= e(date('d/m H:i:s', strtotime($r['created_at']))) ?></td>
        <td><?= e($r['tenant_name'] ?: '—') ?></td>
        <td style="font-size:.8rem;"><?= e($r['user_email'] ?: '—') ?></td>
        <td><span class="badge badge-active" style="font-family:monospace;"><?= e($r['action']) ?></span></td>
        <td style="font-size:.75rem;color:var(--ink-3);"><?= e(($r['target_type'] ?? '') . ($r['target_id'] ? ' #' . $r['target_id'] : '')) ?></td>
        <td style="font-size:.7rem;color:var(--ink-3);font-family:monospace;"><?= e($r['ip'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="6" style="text-align:center;color:var(--ink-3);padding:2rem;">Nenhum evento registrado.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php
});
