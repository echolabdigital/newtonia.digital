<?php
require_once __DIR__ . '/../config.php';
require_super_admin();
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../core/plans.php';

plans_ensure_hermes_schema(); // garante schema atualizado

// ── AJAX actions ────────────────────────────────────────────────────────────
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $tid = (int) ($_POST['tenant_id'] ?? 0);
    if (!$tid) { echo json_encode(['error' => 'missing tenant']); exit; }

    if ($_POST['action'] === 'liberate_credits') {
        $credits = (int) ($_POST['credits'] ?? 0);
        if ($credits <= 0 || $credits > 100000) { echo json_encode(['error' => 'invalid credits']); exit; }
        db_q('UPDATE tenants SET cnpj_addon_credits = cnpj_addon_credits + ? WHERE id = ?', [$credits, $tid]);
        audit_log('tenant.liberate_credits', 'tenant', $tid, ['credits' => $credits]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($_POST['action'] === 'change_status') {
        $status = $_POST['status'] ?? '';
        if (!in_array($status, ['active','pending','suspended','cancelled'], true)) {
            echo json_encode(['error' => 'invalid status']); exit;
        }
        db_q('UPDATE tenants SET status = ? WHERE id = ?', [$status, $tid]);
        audit_log('tenant.status_change', 'tenant', $tid, ['status' => $status]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($_POST['action'] === 'change_plan') {
        $plan_id = (int) ($_POST['plan_id'] ?? 0);
        if (!$plan_id) { echo json_encode(['error' => 'invalid plan']); exit; }
        db_q('UPDATE tenants SET plan_id = ? WHERE id = ?', [$plan_id, $tid]);
        audit_log('tenant.plan_change', 'tenant', $tid, ['plan_id' => $plan_id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['error' => 'unknown action']);
    exit;
}

// ── Filtros + query ─────────────────────────────────────────────────────────
$status_filter = $_GET['status'] ?? '';
$q             = trim($_GET['q'] ?? '');

$where = []; $params = [];
if ($status_filter && in_array($status_filter, ['active','pending','suspended','cancelled'], true)) {
    $where[] = 't.status = ?';
    $params[] = $status_filter;
}
if ($q !== '') {
    $where[] = '(t.name LIKE ? OR t.email LIKE ? OR t.slug LIKE ?)';
    $like = "%$q%";
    array_push($params, $like, $like, $like);
}
$wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$tenants = db_all(
    "SELECT t.*,
            (SELECT u.email FROM users u JOIN tenant_users tu ON tu.user_id = u.id
             WHERE tu.tenant_id = t.id ORDER BY u.id ASC LIMIT 1) AS email,
            p.name AS plan_name, p.tier_code, p.price_cents,
            p.limit_cnpj_monthly AS plan_radar_limit,
            p.limit_contacts AS plan_pipeline_limit,
            (SELECT COUNT(*) FROM crm_cards WHERE tenant_id = t.id) AS cards_count,
            (SELECT COALESCE(SUM(records_count), 0) FROM cnpj_download_log
             WHERE tenant_id = t.id
               AND YEAR(downloaded_at) = YEAR(NOW())
               AND MONTH(downloaded_at) = MONTH(NOW())) AS leads_used_month,
            (SELECT MAX(u.last_login_at) FROM users u
             JOIN tenant_users tu ON tu.user_id = u.id
             WHERE tu.tenant_id = t.id) AS last_login,
            (SELECT COUNT(*) FROM tenant_users WHERE tenant_id = t.id) AS users_count
     FROM tenants t
     LEFT JOIN plans p ON p.id = t.plan_id
     $wsql
     ORDER BY t.created_at DESC",
    $params
);

$plans = hermes_plans_list();

admin_layout('Tenants', 'tenants', function() use ($tenants, $status_filter, $q, $plans) {
?>
<style>
  .tn-toolbar { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; margin-bottom:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .tn-toolbar input, .tn-toolbar select { padding:8px 12px; border:1px solid #e5e7eb; border-radius:7px; font-size:.85rem; font-family:inherit; }
  .tn-toolbar input:focus, .tn-toolbar select:focus { outline:none; border-color:#0ea5e9; box-shadow:0 0 0 3px rgba(14,165,233,.1); }
  .tn-toolbar .tn-search { flex:1; min-width:200px; }
  .tn-toolbar button { padding:8px 16px; background:#0ea5e9; color:#fff; border:none; border-radius:7px; font-size:.85rem; font-weight:500; cursor:pointer; font-family:inherit; }
  .tn-toolbar button:hover { background:#0ea371; }
  .tn-toolbar .tn-new { background:#0f172a; }
  .tn-toolbar .tn-new:hover { background:#1e293b; }

  .tn-table-wrap { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; }
  .tn-table { width:100%; border-collapse:collapse; }
  .tn-table th { background:#f9fafb; padding:11px 14px; text-align:left; font-family:'Geist Mono','SF Mono',monospace; font-size:.66rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.06em; border-bottom:1px solid #e5e7eb; }
  .tn-table td { padding:13px 14px; border-bottom:1px solid #f3f4f6; vertical-align:top; font-size:.85rem; }
  .tn-table tr:hover { background:#fafafa; }
  .tn-table tr:last-child td { border-bottom:none; }

  .tn-brand { display:flex; align-items:center; gap:10px; }
  .tn-avatar { width:36px; height:36px; border-radius:8px; flex-shrink:0; font-family:'Geist Mono',monospace; color:#fff; font-weight:700; display:flex; align-items:center; justify-content:center; font-size:.9rem; }
  .tn-name { font-weight:600; color:#111827; font-size:.88rem; }
  .tn-email { font-size:.72rem; color:#6b7280; margin-top:1px; }

  .tn-tier { font-family:'Geist Mono',monospace; font-size:.62rem; padding:2px 7px; border-radius:4px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; display:inline-block; }
  .tn-tier.trial    { background:#f3f4f6; color:#6b7280; }
  .tn-tier.starter  { background:#dcfce7; color:#166534; }
  .tn-tier.pro      { background:#0ea5e915; color:#0284c7; border:1px solid #0ea5e940; }
  .tn-tier.business { background:#0ea5e915; color:#0369a1; border:1px solid #0ea5e940; }
  .tn-tier.none     { background:#f3f4f6; color:#9ca3af; font-style:italic; }

  .tn-status { font-family:'Geist Mono',monospace; font-size:.6rem; padding:2px 8px; border-radius:99px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; }
  .tn-status.active    { background:#dcfce7; color:#166534; }
  .tn-status.pending   { background:#fef3c7; color:#92400e; }
  .tn-status.suspended { background:#fef2f2; color:#991b1b; }
  .tn-status.cancelled { background:#f3f4f6; color:#6b7280; }

  .tn-metrics { display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; min-width:280px; }
  .tn-metric { line-height:1.2; }
  .tn-metric .v { font-family:'Geist Mono',monospace; font-weight:700; font-size:.92rem; color:#111827; }
  .tn-metric .v.warn { color:#ea580c; }
  .tn-metric .v.danger { color:#991b1b; }
  .tn-metric .l { font-size:.66rem; color:#6b7280; }

  .tn-actions { display:flex; gap:5px; justify-content:flex-end; }
  .tn-btn { padding:6px 10px; background:#fff; border:1px solid #e5e7eb; border-radius:6px; font-size:.72rem; font-weight:500; color:#374151; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:3px; font-family:inherit; }
  .tn-btn:hover { background:#f3f4f6; border-color:#9ca3af; }
  .tn-btn.primary { background:#0ea5e9; color:#fff; border-color:#0ea5e9; }
  .tn-btn.primary:hover { background:#0ea371; border-color:#0ea371; }
  .tn-btn.warn { background:#fef3c7; color:#92400e; border-color:#fcd34d; }
  .tn-btn.warn:hover { background:#fde68a; }

  /* Modal */
  .mod-bg { display:none; position:fixed; inset:0; background:rgba(15,23,42,.5); z-index:300; align-items:center; justify-content:center; }
  .mod-bg.open { display:flex; }
  .mod-box { background:#fff; border-radius:12px; padding:22px; width:420px; max-width:90%; box-shadow:0 20px 50px rgba(0,0,0,.2); }
  .mod-box h3 { font-size:1rem; margin:0 0 14px; }
  .mod-box label { display:block; font-family:'Geist Mono',monospace; font-size:.66rem; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; margin-bottom:5px; }
  .mod-box input, .mod-box select { width:100%; padding:9px 12px; border:1px solid #e5e7eb; border-radius:7px; font-size:.86rem; font-family:inherit; margin-bottom:12px; }
  .mod-actions { display:flex; gap:8px; justify-content:flex-end; padding-top:12px; border-top:1px solid #f3f4f6; }
  .mod-btn { padding:8px 14px; border:none; border-radius:7px; font-size:.84rem; font-weight:500; cursor:pointer; font-family:inherit; }
  .mod-btn.primary { background:#0ea5e9; color:#fff; }
  .mod-btn.ghost { background:#fff; color:#374151; border:1px solid #e5e7eb; }

  .toast { position:fixed; bottom:20px; right:20px; background:#0f172a; color:#fff; padding:11px 18px; border-radius:8px; font-size:.84rem; z-index:400; opacity:0; transition:opacity .2s; pointer-events:none; }
  .toast.show { opacity:1; }

  .tn-empty { text-align:center; padding:48px 20px; color:#9ca3af; }

  @media (max-width: 1100px) {
    .tn-metrics { grid-template-columns:1fr 1fr; min-width:200px; }
  }
</style>

<div class="tn-toolbar">
  <form method="GET" style="display:flex;gap:10px;flex:1;flex-wrap:wrap">
    <input type="text" name="q" class="tn-search" placeholder="🔍 Buscar por nome, e-mail, slug…" value="<?= e($q) ?>">
    <select name="status">
      <option value="">Todos status</option>
      <option value="active"    <?= $status_filter==='active'?'selected':'' ?>>Ativos</option>
      <option value="pending"   <?= $status_filter==='pending'?'selected':'' ?>>Pendentes</option>
      <option value="suspended" <?= $status_filter==='suspended'?'selected':'' ?>>Suspensos</option>
      <option value="cancelled" <?= $status_filter==='cancelled'?'selected':'' ?>>Cancelados</option>
    </select>
    <button type="submit">Filtrar</button>
  </form>
  <a href="tenant-edit.php" class="tn-btn primary" style="text-decoration:none">+ Novo tenant</a>
</div>

<div class="tn-table-wrap">
  <table class="tn-table">
    <thead>
      <tr>
        <th>Tenant</th>
        <th>Plano</th>
        <th>Status</th>
        <th>Métricas (mês atual)</th>
        <th>Último acesso</th>
        <th style="text-align:right">Ações</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($tenants as $t):
      $tier = $t['tier_code'] ?: 'none';
      $tier_label = $t['plan_name'] ?: 'Sem plano';
      $cards = (int)$t['cards_count'];
      $leads_used = (int)$t['leads_used_month'];
      $leads_limit = (int)$t['plan_radar_limit'] + (int)$t['cnpj_addon_credits'];
      $leads_pct = $leads_limit > 0 ? min(100, round(($leads_used / $leads_limit) * 100)) : 0;
      $pct_cls = $leads_pct >= 90 ? 'danger' : ($leads_pct >= 70 ? 'warn' : '');
      $last_login = $t['last_login'] ? date('d/m H:i', strtotime($t['last_login'])) : '—';
      $brand_color = $t['brand_color'] ?: '#0ea5e9';
      $initial = strtoupper(mb_substr($t['brand_name'] ?: $t['name'], 0, 1));
    ?>
      <tr>
        <td>
          <div class="tn-brand">
            <div class="tn-avatar" style="background:<?= e($brand_color) ?>"><?= e($initial) ?></div>
            <div>
              <div class="tn-name"><?= e($t['brand_name'] ?: $t['name']) ?></div>
              <div class="tn-email"><?= e($t['email']) ?> · <?= (int)$t['users_count'] ?>👥</div>
            </div>
          </div>
        </td>
        <td>
          <span class="tn-tier <?= e($tier) ?>"><?= e($tier_label) ?></span>
          <?php if ($t['price_cents']): ?>
            <div style="font-size:.7rem;color:#6b7280;margin-top:3px">R$ <?= number_format($t['price_cents']/100, 0, ',', '.') ?>/mês</div>
          <?php endif; ?>
        </td>
        <td><span class="tn-status <?= e($t['status']) ?>"><?= e($t['status']) ?></span></td>
        <td>
          <div class="tn-metrics">
            <div class="tn-metric">
              <div class="v <?= $pct_cls ?>"><?= number_format($leads_used, 0, ',', '.') ?></div>
              <div class="l">extrações / <?= number_format($leads_limit, 0, ',', '.') ?></div>
            </div>
            <div class="tn-metric">
              <div class="v"><?= number_format($cards, 0, ',', '.') ?></div>
              <div class="l">cards no Pipeline</div>
            </div>
            <div class="tn-metric">
              <div class="v"><?= number_format((int)$t['cnpj_addon_credits'], 0, ',', '.') ?></div>
              <div class="l">créditos extras</div>
            </div>
          </div>
        </td>
        <td style="color:#6b7280;font-size:.78rem;font-family:'Geist Mono',monospace"><?= e($last_login) ?></td>
        <td>
          <div class="tn-actions">
            <a href="tenant-detail.php?id=<?= (int)$t['id'] ?>" class="tn-btn primary">📊 Detalhes</a>
            <button class="tn-btn" onclick='openLiberateModal(<?= (int)$t['id'] ?>, <?= json_encode($t['brand_name'] ?: $t['name']) ?>)'>+ Cota</button>
            <a href="impersonate.php?tenant_id=<?= (int)$t['id'] ?>" class="tn-btn" title="Entrar neste tenant como super-admin" style="background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe">▶ Entrar</a>
            <a href="tenant-edit.php?id=<?= (int)$t['id'] ?>" class="tn-btn">✎</a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$tenants): ?>
      <tr><td colspan="6" class="tn-empty">Nenhum tenant encontrado.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Modal: liberar créditos extras -->
<div class="mod-bg" id="liberate-modal" onclick="if(event.target.id==='liberate-modal')closeLiberate()">
  <div class="mod-box">
    <h3 id="liberate-title">Liberar créditos extras</h3>
    <p style="font-size:.82rem;color:#6b7280;margin:0 0 12px">Os créditos se somam à cota mensal do plano e não expiram.</p>
    <label>Quantidade de extrações extras</label>
    <input type="number" id="liberate-credits" min="1" max="100000" placeholder="100">
    <input type="hidden" id="liberate-tenant-id">
    <div class="mod-actions">
      <button class="mod-btn ghost" onclick="closeLiberate()">Cancelar</button>
      <button class="mod-btn primary" onclick="saveLiberate()">Liberar</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2400);
}

function openLiberateModal(tid, name) {
  document.getElementById('liberate-tenant-id').value = tid;
  document.getElementById('liberate-title').textContent = 'Liberar créditos: ' + name;
  document.getElementById('liberate-credits').value = '';
  document.getElementById('liberate-modal').classList.add('open');
  setTimeout(() => document.getElementById('liberate-credits').focus(), 80);
}
function closeLiberate() { document.getElementById('liberate-modal').classList.remove('open'); }

async function saveLiberate() {
  const tid = document.getElementById('liberate-tenant-id').value;
  const credits = parseInt(document.getElementById('liberate-credits').value);
  if (!credits || credits <= 0) { alert('Quantidade inválida'); return; }
  const fd = new FormData();
  fd.append('action', 'liberate_credits');
  fd.append('tenant_id', tid);
  fd.append('credits', credits);
  const r = await fetch('tenants.php', { method: 'POST', body: fd }).then(r => r.json());
  if (r.ok) { closeLiberate(); toast('✓ ' + credits + ' créditos liberados'); setTimeout(() => location.reload(), 800); }
  else alert('Erro: ' + (r.error || 'desconhecido'));
}
</script>
<?php
});
