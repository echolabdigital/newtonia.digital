<?php
/**
 * Newton AI — Admin: Editar tenant
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_layout.php';

require_super_admin();

$id     = (int) ($_GET['id'] ?? 0);
$tenant = db_one("SELECT * FROM tenants WHERE id = ?", [$id]);
if (!$tenant) { http_response_code(404); echo 'Tenant não encontrado.'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $data = [
            'name'           => $_POST['name'],
            'email'          => $_POST['email'],
            'plan_id'        => (int) $_POST['plan_id'],
            'active'         => isset($_POST['active']) ? 1 : 0,
            'custom_domain'  => $_POST['custom_domain'] ?? '',
            'brand_name'     => $_POST['brand_name'] ?? '',
            'brand_color'    => $_POST['brand_color'] ?? '#6366f1',
            'zapi_instance'  => $_POST['zapi_instance'] ?? '',
            'zapi_token'     => $_POST['zapi_token'] ?? '',
        ];
        db_update('tenants', $data, ['id' => $id]);
        audit_log('admin', 'tenant_updated', ['tenant_id' => $id]);
        flash_set('success', 'Tenant atualizado.');
        header('Location: tenant-edit.php?id=' . $id);
        exit;
    }

    // Reset CNPJ quota for current month (emergency)
    if ($action === 'reset_cnpj_quota') {
        db_q(
            "DELETE FROM cnpj_download_log
             WHERE tenant_id = ?
               AND YEAR(downloaded_at)  = YEAR(NOW())
               AND MONTH(downloaded_at) = MONTH(NOW())",
            [$id]
        );
        audit_log('admin', 'cnpj_quota_reset', ['tenant_id' => $id]);
        flash_set('success', 'Quota CNPJ do mês zerada.');
        header('Location: tenant-edit.php?id=' . $id);
        exit;
    }
}

// CNPJ quota info (read-only — full management in cnpj-limits.php)
$cnpj_used  = cnpj_monthly_used($id);
$cnpj_limit = cnpj_monthly_limit($id);
$cnpj_pct   = cnpj_usage_pct($cnpj_used, $cnpj_limit);
$cnpj_plan  = $tenant['cnpj_plan_id']
    ? db_one("SELECT * FROM cnpj_plans WHERE id = ?", [$tenant['cnpj_plan_id']])
    : null;

$plans = db_all("SELECT * FROM plans WHERE active = 1 ORDER BY name");

admin_layout('Editar Tenant — ' . e($tenant['name']), 'tenants', function () use ($tenant, $plans, $cnpj_used, $cnpj_limit, $cnpj_pct, $cnpj_plan, $id) {
?>
<style>
  .card        { background:#fff; border-radius:12px; padding:24px; margin-bottom:24px; box-shadow:0 1px 4px rgba(0,0,0,.08); }
  .form-grid   { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  label        { display:block; font-size:.82rem; font-weight:600; color:#6b7280; margin-bottom:4px; }
  input[type=text],
  input[type=email],
  input[type=color],
  select       { width:100%; padding:8px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:.9rem; }
  .btn-primary { background:var(--cr,#6366f1); color:#fff; border:none; border-radius:8px; padding:10px 22px; cursor:pointer; font-size:.9rem; }
  .btn-danger  { background:#ef4444; color:#fff; border:none; border-radius:8px; padding:8px 16px; cursor:pointer; font-size:.85rem; }
  .quota-bar-wrap { background:#e5e7eb; border-radius:6px; height:10px; margin-top:8px; }
  .quota-bar      { height:10px; border-radius:6px; }
  .quota-bar.ok     { background:#22c55e; }
  .quota-bar.warn   { background:#f59e0b; }
  .quota-bar.danger { background:#ef4444; }
</style>

<?= flash_render() ?>

<!-- Main form -->
<div class="card">
  <h2 style="margin-bottom:20px">Informações do tenant</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="form-grid">
      <div>
        <label>Nome</label>
        <input type="text" name="name" value="<?= e($tenant['name']) ?>" required>
      </div>
      <div>
        <label>E-mail</label>
        <input type="email" name="email" value="<?= e($tenant['email']) ?>" required>
      </div>
      <div>
        <label>Plano (app)</label>
        <select name="plan_id">
          <?php foreach ($plans as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $tenant['plan_id'] == $p['id'] ? 'selected' : '' ?>>
            <?= e($p['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Domínio personalizado</label>
        <input type="text" name="custom_domain" value="<?= e($tenant['custom_domain'] ?? '') ?>" placeholder="app.cliente.com.br">
      </div>
      <div>
        <label>Nome da marca (white-label)</label>
        <input type="text" name="brand_name" value="<?= e($tenant['brand_name'] ?? '') ?>">
      </div>
      <div>
        <label>Cor principal</label>
        <input type="color" name="brand_color" value="<?= e($tenant['brand_color'] ?? '#6366f1') ?>">
      </div>
      <div>
        <label>Z-API Instance</label>
        <input type="text" name="zapi_instance" value="<?= e($tenant['zapi_instance'] ?? '') ?>">
      </div>
      <div>
        <label>Z-API Token</label>
        <input type="text" name="zapi_token" value="<?= e($tenant['zapi_token'] ?? '') ?>">
      </div>
    </div>
    <div style="margin-top:16px">
      <label style="display:flex;align-items:center;gap:8px;font-size:.9rem">
        <input type="checkbox" name="active" value="1" <?= $tenant['active'] ? 'checked' : '' ?>>
        Tenant ativo
      </label>
    </div>
    <div style="margin-top:20px">
      <button type="submit" class="btn-primary">Salvar alterações</button>
    </div>
  </form>
</div>

<!-- CNPJ Quota (read-only panel) -->
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <h2>Newton CNPJ — uso do mês</h2>
    <a href="cnpj-limits.php" style="font-size:.85rem;color:var(--cr,#6366f1)">Gerenciar planos e limites ›</a>
  </div>

  <?php
    $bar_cls = $cnpj_pct >= 90 ? 'danger' : ($cnpj_pct >= 50 ? 'warn' : 'ok');
  ?>
  <p style="font-size:.9rem;color:#374151">
    <strong>Plano CNPJ:</strong> <?= $cnpj_plan ? e($cnpj_plan['name']) . ' (' . number_format($cnpj_plan['monthly_limit']) . ' leads/mês)' : '—' ?>
    <?php if ($tenant['cnpj_limit_override'] !== null): ?>
      &nbsp;&mdash;&nbsp;<em>override: <?= number_format($tenant['cnpj_limit_override']) ?></em>
    <?php endif; ?>
  </p>
  <p style="font-size:.9rem;color:#374151;margin-top:6px">
    Usado: <strong><?= number_format($cnpj_used) ?></strong> /
    <?= number_format($cnpj_limit) ?> leads
    (<?= $cnpj_pct ?>%)
    &nbsp;|&nbsp; Créditos addon: <strong><?= number_format((int)$tenant['cnpj_addon_credits']) ?></strong>
  </p>
  <div class="quota-bar-wrap">
    <div class="quota-bar <?= $bar_cls ?>" style="width:<?= $cnpj_pct ?>%"></div>
  </div>

  <form method="post" style="margin-top:16px"
        onsubmit="return confirm('Zerar a quota CNPJ deste mês para este tenant?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset_cnpj_quota">
    <button type="submit" class="btn-danger">Zerar quota do mês (emergência)</button>
  </form>
</div>
<?php
});
