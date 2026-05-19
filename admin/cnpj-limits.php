<?php
/**
 * HERMES.b2b — Admin: Gerenciar Planos e Limites CNPJ
 */

require_once __DIR__ . '/../config.php';
require_super_admin();
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../core/cnpj_db.php';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_tenant') {
        $tid      = (int) $_POST['tenant_id'];
        $plan_id  = $_POST['plan_id'] !== '' ? (int) $_POST['plan_id'] : null;
        $override = $_POST['limit_override'] !== '' ? (int) $_POST['limit_override'] : null;
        $addon    = (int) $_POST['addon_credits'];

        db_q(
            "UPDATE tenants
             SET cnpj_plan_id        = ?,
                 cnpj_limit_override = ?,
                 cnpj_addon_credits  = ?
             WHERE id = ?",
            [$plan_id, $override, $addon, $tid]
        );
        audit_log('admin', 'cnpj_tenant_updated', ['tenant_id' => $tid]);
        flash_set('success', 'Tenant atualizado com sucesso.');
        header('Location: cnpj-limits.php');
        exit;
    }

    if ($action === 'add_addon') {
        $tid = (int) $_POST['tenant_id'];
        $qty = (int) $_POST['quantity'];
        db_q(
            "UPDATE tenants SET cnpj_addon_credits = cnpj_addon_credits + ? WHERE id = ?",
            [$qty, $tid]
        );
        audit_log('admin', 'cnpj_addon_added', ['tenant_id' => $tid, 'qty' => $qty]);
        flash_set('success', "{$qty} créditos adicionados.");
        header('Location: cnpj-limits.php');
        exit;
    }
}

$plans = db_all("SELECT * FROM cnpj_plans ORDER BY monthly_limit");
$packs = db_all("SELECT * FROM cnpj_addon_packs ORDER BY quantity");

$tenants = db_all("
    SELECT
        t.id,
        t.name,
        (SELECT u.email FROM users u JOIN tenant_users tu ON tu.user_id = u.id
         WHERE tu.tenant_id = t.id ORDER BY u.id ASC LIMIT 1) AS email,
        t.cnpj_plan_id,
        t.cnpj_limit_override,
        t.cnpj_addon_credits,
        p.name         AS plan_name,
        p.monthly_limit,
        COALESCE(t.cnpj_limit_override, p.monthly_limit, 0) + t.cnpj_addon_credits AS effective_limit,
        (
            SELECT COALESCE(SUM(dl.records_count), 0)
            FROM cnpj_download_log dl
            WHERE dl.tenant_id = t.id
              AND YEAR(dl.downloaded_at)  = YEAR(NOW())
              AND MONTH(dl.downloaded_at) = MONTH(NOW())
        ) AS used_this_month
    FROM tenants t
    LEFT JOIN cnpj_plans p ON p.id = t.cnpj_plan_id
    ORDER BY t.name
");

admin_layout('Limites CNPJ', 'cnpj-limits', function () use ($plans, $packs, $tenants) {
?>
<style>
  .card        { background:#fff; border-radius:12px; padding:24px; margin-bottom:24px; box-shadow:0 1px 4px rgba(0,0,0,.08); }
  table        { width:100%; border-collapse:collapse; }
  th, td       { padding:10px 12px; border-bottom:1px solid #f3f4f6; text-align:left; font-size:.88rem; }
  th           { background:#f9fafb; font-weight:600; }
  .bar-wrap    { background:#e5e7eb; border-radius:4px; height:8px; width:120px; }
  .bar         { height:8px; border-radius:4px; background:#22c55e; }
  .bar.warn    { background:#f59e0b; }
  .bar.danger  { background:#ef4444; }
  .badge       { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.75rem; font-weight:600; }
  .badge-green { background:#d1fae5; color:#065f46; }
  .badge-yellow{ background:#fef3c7; color:#92400e; }
  .badge-red   { background:#fee2e2; color:#991b1b; }
  details summary { cursor:pointer; color:var(--cr,#6366f1); font-size:.85rem; }
  details form    { margin-top:10px; display:grid; gap:8px; max-width:300px; }
  details label   { font-size:.82rem; color:#6b7280; }
  details input,
  details select  { padding:6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:.85rem; width:100%; box-sizing:border-box; }
  .btn-sm         { padding:6px 14px; background:var(--cr,#6366f1); color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:.83rem; }
  .btn-sm:hover   { opacity:.88; }
  .btn-green      { background:#22c55e; }
</style>

<h1 style="margin-bottom:4px">Limites de Download CNPJ</h1>
<p style="color:#6b7280;margin-bottom:24px">Gerencie planos, créditos addon e limites por tenant.</p>

<?= flash_render() ?>

<!-- Planos disponíveis -->
<div class="card">
  <h2 style="margin-bottom:12px">Planos disponíveis</h2>
  <table>
    <tr><th>Plano</th><th>Leads/mês</th><th>Preço</th><th>Status</th></tr>
    <?php foreach ($plans as $p): ?>
    <tr>
      <td><?= e($p['name']) ?></td>
      <td><?= number_format($p['monthly_limit']) ?></td>
      <td>R$ <?= number_format($p['price_monthly'], 2, ',', '.') ?></td>
      <td><span class="badge badge-green"><?= $p['active'] ? 'Ativo' : 'Inativo' ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <h2 style="margin-top:24px;margin-bottom:12px">Pacotes adicionais</h2>
  <table>
    <tr><th>Quantidade</th><th>Preço</th><th>R$/lead</th></tr>
    <?php foreach ($packs as $pk): ?>
    <tr>
      <td><?= number_format($pk['quantity']) ?> leads</td>
      <td>R$ <?= number_format($pk['price'], 2, ',', '.') ?></td>
      <td>R$ <?= number_format($pk['price'] / $pk['quantity'], 3, ',', '.') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<!-- Tenants -->
<div class="card">
  <h2 style="margin-bottom:12px">Tenants — uso do mês atual</h2>
  <table>
    <thead>
      <tr>
        <th>Tenant</th>
        <th>Plano</th>
        <th>Limite efetivo</th>
        <th>Usado</th>
        <th>%</th>
        <th>Barra</th>
        <th>Créditos addon</th>
        <th>Ações</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($tenants as $t):
      $eff   = max(1, (int) $t['effective_limit']);
      $used  = (int) $t['used_this_month'];
      $pct   = min(100, round($used / $eff * 100));
      $cls   = $pct >= 90 ? 'danger' : ($pct >= 50 ? 'warn' : '');
      $badge = $pct >= 100 ? 'badge-red' : ($pct >= 50 ? 'badge-yellow' : 'badge-green');
    ?>
    <tr>
      <td>
        <strong><?= e($t['name']) ?></strong><br>
        <small style="color:#6b7280"><?= e($t['email']) ?></small>
      </td>
      <td><?= e($t['plan_name'] ?? '—') ?></td>
      <td><?= number_format($eff) ?></td>
      <td><?= number_format($used) ?></td>
      <td><span class="badge <?= $badge ?>"><?= $pct ?>%</span></td>
      <td>
        <div class="bar-wrap">
          <div class="bar <?= $cls ?>" style="width:<?= $pct ?>%"></div>
        </div>
      </td>
      <td><?= number_format((int) $t['cnpj_addon_credits']) ?></td>
      <td>
        <details>
          <summary>Editar</summary>

          <!-- Save tenant -->
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_tenant">
            <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
            <label>Plano
              <select name="plan_id">
                <option value="">— nenhum —</option>
                <?php foreach ($plans as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $t['cnpj_plan_id'] == $p['id'] ? 'selected' : '' ?>>
                  <?= e($p['name']) ?> (<?= number_format($p['monthly_limit']) ?> leads)
                </option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Override manual
              <input type="number" name="limit_override" min="0"
                     value="<?= e($t['cnpj_limit_override'] ?? '') ?>"
                     placeholder="Deixe vazio para usar plano">
            </label>
            <label>Créditos addon atuais
              <input type="number" name="addon_credits" min="0"
                     value="<?= (int) $t['cnpj_addon_credits'] ?>">
            </label>
            <button type="submit" class="btn-sm">Salvar</button>
          </form>

          <!-- Add addon credits -->
          <form method="post" style="margin-top:10px;display:flex;gap:8px;align-items:center;max-width:300px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_addon">
            <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
            <select name="quantity" style="flex:1">
              <?php foreach ($packs as $pk): ?>
              <option value="<?= $pk['quantity'] ?>">+ <?= number_format($pk['quantity']) ?> leads</option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-sm btn-green">Adicionar</button>
          </form>
        </details>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
});
