<?php
require_once __DIR__ . '/../config.php';
require_super_admin();
require_once __DIR__ . '/_layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';
    try {
        if ($action === 'add') {
            $instance     = trim($_POST['instance_id'] ?? '');
            $token        = trim($_POST['token'] ?? '');
            $client_token = trim($_POST['client_token'] ?? '');
            $notes        = trim($_POST['notes'] ?? '');
            if (!$instance || !$token || !$client_token) {
                flash_set('error', 'Preencha os 3 campos da Z-API.');
            } else {
                db_insert('zapi_pool', [
                    'instance_id'  => $instance,
                    'token'        => $token,
                    'client_token' => $client_token,
                    'notes'        => $notes ?: null,
                    'status'       => 'available',
                ]);
                audit_log('zapi_pool.added', null, null, ['instance' => $instance]);
                flash_set('success', 'Instância adicionada ao pool.');
            }
        }
        if ($action === 'delete') {
            $pid = (int) ($_POST['pool_id'] ?? 0);
            $p = db_one("SELECT * FROM zapi_pool WHERE id = ?", [$pid]);
            if ($p && $p['status'] !== 'assigned') {
                db_delete('zapi_pool', 'id = :id', ['id' => $pid]);
                audit_log('zapi_pool.deleted', null, null, ['instance' => $p['instance_id']]);
                flash_set('success', 'Instância removida.');
            } else {
                flash_set('error', 'Não pode remover instância atribuída. Devolva ao pool primeiro.');
            }
        }
        if ($action === 'maintenance') {
            $pid = (int) ($_POST['pool_id'] ?? 0);
            db_update('zapi_pool', ['status' => 'maintenance'], 'id = :id AND status = :s', ['id' => $pid, 's' => 'available']);
            flash_set('success', 'Instância marcada como manutenção.');
        }
        if ($action === 'available') {
            $pid = (int) ($_POST['pool_id'] ?? 0);
            db_update('zapi_pool', ['status' => 'available'], 'id = :id AND status = :s', ['id' => $pid, 's' => 'maintenance']);
            flash_set('success', 'Instância liberada.');
        }
    } catch (\Throwable $e) {
        flash_set('error', 'Erro: ' . $e->getMessage());
    }
    header('Location: zapi-pool.php'); exit;
}

$rows = db_all(
    "SELECT zp.*, t.name AS tenant_name FROM zapi_pool zp
     LEFT JOIN tenants t ON t.id = zp.assigned_to
     ORDER BY zp.status, zp.id"
);

$counts = [
    'available'   => 0,
    'assigned'    => 0,
    'maintenance' => 0,
];
foreach ($rows as $r) $counts[$r['status']]++;

admin_layout('Z-API Pool', 'zapi', function() use ($rows, $counts) {
?>
<div class="stats-grid" style="grid-template-columns:repeat(3, 1fr);">
  <div class="stat-card">
    <div class="stat-icon" style="color:#15803d;background:rgba(22,163,74,0.1);"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="20" height="20"><path d="M5 13l4 4L19 7"/></svg></div>
    <div class="stat-val" style="color:#15803d;"><?= (int) $counts['available'] ?></div>
    <div class="stat-label">Disponíveis</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="20" height="20"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div>
    <div class="stat-val" style="color:var(--cr);"><?= (int) $counts['assigned'] ?></div>
    <div class="stat-label">Atribuídas</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="color:#a16207;background:rgba(234,179,8,0.1);"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="20" height="20"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div>
    <div class="stat-val" style="color:#a16207;"><?= (int) $counts['maintenance'] ?></div>
    <div class="stat-label">Em manutenção</div>
  </div>
</div>

<div class="panel">
  <h2>Adicionar instância ao pool</h2>
  <p style="color:var(--ink-3);font-size:.8rem;margin-bottom:1.25rem;">
    Compre instâncias na Z-API (z-api.io), pegue os 3 tokens (Instance ID, Token, Client-Token) e cole aqui. A instância vai pro pool e será atribuída automaticamente quando um tenant precisar.
  </p>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="add">
    <div class="row-3">
      <div class="field"><label>Instance ID *</label><input type="text" name="instance_id" required style="font-family:monospace;"></div>
      <div class="field"><label>Token *</label><input type="text" name="token" required style="font-family:monospace;"></div>
      <div class="field"><label>Client-Token *</label><input type="text" name="client_token" required style="font-family:monospace;"></div>
    </div>
    <div class="field"><label>Notas (opcional)</label><input type="text" name="notes" placeholder="ex: comprada em 01/05, plano premium"></div>
    <button type="submit" class="btn-action">Adicionar ao pool</button>
  </form>
</div>

<div class="panel">
  <h2>Pool</h2>
  <table>
    <thead>
      <tr><th>Instance</th><th>Status</th><th>Atribuída a</th><th>Notas</th><th>Adicionada</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td style="font-family:monospace;font-size:.75rem;"><?= e($r['instance_id']) ?></td>
        <td><span class="badge badge-<?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
        <td><?= $r['tenant_name'] ? e($r['tenant_name']) : '<span style="color:var(--ink-3);">—</span>' ?></td>
        <td style="color:var(--ink-3);font-size:.8rem;"><?= e($r['notes'] ?: '') ?></td>
        <td style="color:var(--ink-3);font-size:.8rem;"><?= e(date('d/m/Y', strtotime($r['created_at']))) ?></td>
        <td style="text-align:right;">
          <?php if ($r['status'] === 'available'): ?>
            <form method="POST" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="_action" value="maintenance"><input type="hidden" name="pool_id" value="<?= (int)$r['id'] ?>"><button class="btn-action secondary" style="font-size:.7rem;padding:.4rem .8rem;">Manutenção</button></form>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Remover esta instância do pool?');"><?= csrf_field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="pool_id" value="<?= (int)$r['id'] ?>"><button class="btn-action danger" style="font-size:.7rem;padding:.4rem .8rem;">Remover</button></form>
          <?php elseif ($r['status'] === 'maintenance'): ?>
            <form method="POST" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="_action" value="available"><input type="hidden" name="pool_id" value="<?= (int)$r['id'] ?>"><button class="btn-action" style="font-size:.7rem;padding:.4rem .8rem;">Liberar</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="6" style="text-align:center;color:var(--ink-3);padding:2rem;">Pool vazio. Adicione a primeira instância acima ↑</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php
});
