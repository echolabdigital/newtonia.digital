<?php
require_once __DIR__ . '/../config.php';
require_super_admin();
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../core/plans.php';
require_once __DIR__ . '/../core/tenant_features.php';

plans_ensure_hermes_schema();
tenant_features_ensure_schema();

$tid = (int) ($_GET['id'] ?? 0);
if (!$tid) { header('Location: tenants.php'); exit; }

// ── AJAX actions ────────────────────────────────────────────────────────────
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $uid = (int) auth_user_id();

    if ($_POST['action'] === 'set_feature') {
        $feature = $_POST['feature'] ?? '';
        $enabled = !empty($_POST['enabled']);
        if (!isset(hermes_all_features()[$feature])) {
            echo json_encode(['error' => 'invalid feature']); exit;
        }
        tenant_set_feature($tid, $feature, $enabled, $uid);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($_POST['action'] === 'clear_feature') {
        $feature = $_POST['feature'] ?? '';
        tenant_clear_feature_override($tid, $feature);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($_POST['action'] === 'change_plan') {
        $plan_id = (int) ($_POST['plan_id'] ?? 0);
        if ($plan_id) {
            db_q('UPDATE tenants SET plan_id = ? WHERE id = ?', [$plan_id, $tid]);
            audit_log('tenant.plan_change', 'tenant', $tid, ['plan_id' => $plan_id]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($_POST['action'] === 'change_status') {
        $st = $_POST['status'] ?? '';
        if (in_array($st, ['active','pending','suspended','cancelled'], true)) {
            db_q('UPDATE tenants SET status = ? WHERE id = ?', [$st, $tid]);
            audit_log('tenant.status', 'tenant', $tid, ['status' => $st]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($_POST['action'] === 'liberate_credits') {
        $c = (int) ($_POST['credits'] ?? 0);
        if ($c > 0 && $c <= 100000) {
            db_q('UPDATE tenants SET cnpj_addon_credits = cnpj_addon_credits + ? WHERE id = ?', [$c, $tid]);
            audit_log('tenant.liberate_credits', 'tenant', $tid, ['credits' => $c]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // === Simulate Asaas payment confirmation (test sandbox flow) ===
    if ($_POST['action'] === 'simulate_payment_confirmed') {
        require_once __DIR__ . '/../core/billing.php';
        // Pega o pagamento pendente mais recente do tenant
        $pay = db_one(
            "SELECT * FROM asaas_payments WHERE tenant_id = ?
               AND status IN ('PENDING','AWAITING_RISK_ANALYSIS','OVERDUE')
             ORDER BY created_at DESC LIMIT 1",
            [$tid]
        );
        if (!$pay) {
            echo json_encode(['error' => 'Nenhum pagamento pendente']);
            exit;
        }
        // Marca como pago
        db_q("UPDATE asaas_payments SET status='RECEIVED', paid_date=CURDATE() WHERE id = ?", [$pay['id']]);
        // Se é assinatura, ativa o tenant + subscription
        if ($pay['asaas_subscription_id']) {
            db_q("UPDATE tenants SET status='active' WHERE id = ?", [$tid]);
            db_q("UPDATE asaas_subscriptions SET status='ACTIVE' WHERE asaas_subscription_id = ?", [$pay['asaas_subscription_id']]);
        } else {
            // Lead pack → ativa créditos
            billing_activate_lead_pack($pay['asaas_payment_id']);
        }
        // Registra evento fake
        $fake_event_id = 'sim_' . bin2hex(random_bytes(8));
        db_q(
            "INSERT INTO asaas_events (id, event, payment_id, subscription_id, status)
             VALUES (?, ?, ?, ?, 'simulated')",
            [$fake_event_id, 'PAYMENT_RECEIVED', $pay['asaas_payment_id'], $pay['asaas_subscription_id']]
        );
        audit_log('payment.simulated_confirmed', 'tenant', $tid, ['payment_id' => $pay['asaas_payment_id']]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['error' => 'unknown action']);
    exit;
}

// ── Dados ──────────────────────────────────────────────────────────────────
$t = db_one(
    "SELECT t.*,
            (SELECT u.email FROM users u JOIN tenant_users tu ON tu.user_id = u.id
             WHERE tu.tenant_id = t.id ORDER BY u.id ASC LIMIT 1) AS email,
            p.name AS plan_name, p.tier_code, p.price_cents,
            p.limit_cnpj_monthly AS plan_radar_limit,
            p.limit_contacts AS plan_pipeline_limit,
            p.users_limit AS plan_users_limit,
            p.mail_self_limit AS plan_mail_limit
     FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id
     WHERE t.id = ?",
    [$tid]
);
if (!$t) { header('Location: tenants.php'); exit; }

$plans = hermes_plans_list();
$features_all = hermes_all_features();
$features_state = tenant_features_for($tid);

// Pagamento pendente (pra mostrar e permitir simular)
$pending_pay = db_one(
    "SELECT * FROM asaas_payments WHERE tenant_id = ?
       AND status IN ('PENDING','AWAITING_RISK_ANALYSIS','OVERDUE')
     ORDER BY created_at DESC LIMIT 1",
    [$tid]
);

// Métricas
$metrics = [
    'cards_count'      => (int) db_val('SELECT COUNT(*) FROM crm_cards WHERE tenant_id = ?', [$tid]),
    'leads_used_month' => (int) db_val(
        "SELECT COALESCE(SUM(records_count),0) FROM cnpj_download_log
         WHERE tenant_id = ? AND YEAR(downloaded_at) = YEAR(NOW()) AND MONTH(downloaded_at) = MONTH(NOW())",
        [$tid]
    ),
    'leads_used_total' => (int) db_val("SELECT COALESCE(SUM(records_count),0) FROM cnpj_download_log WHERE tenant_id = ?", [$tid]),
    'users_count'      => (int) db_val('SELECT COUNT(*) FROM tenant_users WHERE tenant_id = ?', [$tid]),
    'lists_count'      => (int) db_val('SELECT COUNT(*) FROM cnpj_lists WHERE tenant_id = ?', [$tid]),
    'columns_count'    => (int) db_val('SELECT COUNT(*) FROM crm_columns WHERE tenant_id = ?', [$tid]),
];

// Override de feature por tenant (registros explícitos)
$overrides = db_all('SELECT feature, enabled FROM tenant_features WHERE tenant_id = ? AND override = 1', [$tid]);
$overrides_map = [];
foreach ($overrides as $o) $overrides_map[$o['feature']] = (int)$o['enabled'];

// Usuários do tenant
$users = db_all(
    'SELECT u.id, u.name, u.email, u.last_login_at, tu.role
     FROM users u JOIN tenant_users tu ON tu.user_id = u.id
     WHERE tu.tenant_id = ? ORDER BY u.name',
    [$tid]
);

// Histórico recente
$audit = db_all(
    "SELECT action, payload, created_at, user_id FROM audit_log
     WHERE entity = 'tenant' AND entity_id = ? ORDER BY created_at DESC LIMIT 20",
    [$tid]
);

admin_layout($t['name'] . ' · Detalhes', 'tenants', function() use ($t, $plans, $features_all, $features_state, $overrides_map, $metrics, $users, $audit, $pending_pay) {
    $tier = $t['tier_code'] ?: 'none';
    $brand_color = $t['brand_color'] ?: '#0ea5e9';
    $initial = strtoupper(mb_substr($t['brand_name'] ?: $t['name'], 0, 1));
    $leads_limit = (int)$t['plan_radar_limit'] + (int)$t['cnpj_addon_credits'];
    $leads_used  = $metrics['leads_used_month'];
    $leads_pct   = $leads_limit > 0 ? min(100, round(($leads_used / $leads_limit) * 100)) : 0;
?>
<style>
  .td-back { display:inline-flex; align-items:center; gap:5px; color:#6b7280; text-decoration:none; font-size:.82rem; margin-bottom:12px; }
  .td-back:hover { color:#0ea5e9; }

  .td-hero { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px 22px; margin-bottom:14px; display:flex; gap:18px; align-items:center; flex-wrap:wrap; }
  .td-avatar { width:56px; height:56px; border-radius:10px; flex-shrink:0; color:#fff; font-weight:700; font-family:'Geist Mono',monospace; font-size:1.4rem; display:flex; align-items:center; justify-content:center; }
  .td-meta h1 { font-size:1.3rem; font-weight:700; color:#111827; margin:0 0 4px; }
  .td-meta .sub { font-size:.85rem; color:#6b7280; }
  .td-tags { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; }
  .td-tag { font-family:'Geist Mono',monospace; font-size:.6rem; padding:3px 8px; border-radius:4px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; }
  .td-tag.tier.trial    { background:#f3f4f6; color:#6b7280; }
  .td-tag.tier.starter  { background:#dcfce7; color:#166534; }
  .td-tag.tier.pro      { background:#0ea5e915; color:#0284c7; }
  .td-tag.tier.business { background:#0ea5e915; color:#0369a1; }
  .td-tag.tier.none     { background:#f3f4f6; color:#9ca3af; }
  .td-tag.status.active    { background:#dcfce7; color:#166534; }
  .td-tag.status.pending   { background:#fef3c7; color:#92400e; }
  .td-tag.status.suspended { background:#fef2f2; color:#991b1b; }
  .td-tag.status.cancelled { background:#f3f4f6; color:#6b7280; }

  .td-kpis { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; margin-bottom:14px; }
  .td-kpi { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px; }
  .td-kpi .l { font-family:'Geist Mono',monospace; font-size:.6rem; color:#6b7280; text-transform:uppercase; letter-spacing:.06em; }
  .td-kpi .v { font-size:1.6rem; font-weight:700; color:#111827; line-height:1; margin-top:5px; letter-spacing:-0.02em; }
  .td-kpi .v small { font-size:.6em; color:#9ca3af; font-weight:500; }
  .td-kpi .bar { background:#e5e7eb; height:5px; border-radius:99px; margin-top:8px; overflow:hidden; }
  .td-kpi .bar > i { display:block; height:100%; background:#0ea5e9; transition:width .3s; }
  .td-kpi .bar > i.warn { background:#f59e0b; }
  .td-kpi .bar > i.danger { background:#ef4444; }
  .td-kpi .sub { font-size:.7rem; color:#6b7280; margin-top:4px; }

  .td-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }
  @media (max-width: 1100px) { .td-grid { grid-template-columns:1fr; } .td-kpis { grid-template-columns:1fr 1fr; } }

  .td-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px; }
  .td-card h3 { font-size:.95rem; font-weight:700; margin:0 0 12px; padding-bottom:8px; border-bottom:1px solid #f3f4f6; }

  .td-action-row { display:flex; gap:8px; align-items:center; margin-bottom:10px; flex-wrap:wrap; }
  .td-action-row label { font-family:'Geist Mono',monospace; font-size:.62rem; color:#6b7280; text-transform:uppercase; letter-spacing:.06em; min-width:80px; }
  .td-action-row select, .td-action-row input { flex:1; min-width:120px; padding:7px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:.82rem; font-family:inherit; }
  .td-action-row button { padding:7px 14px; background:#0ea5e9; color:#fff; border:none; border-radius:6px; font-size:.78rem; cursor:pointer; font-family:inherit; font-weight:500; }
  .td-action-row button:hover { background:#0ea371; }
  .td-action-row button.warn { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; }
  .td-action-row button.danger { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

  .td-feat-row { display:flex; align-items:center; justify-content:space-between; padding:10px 0; border-bottom:1px solid #f3f4f6; }
  .td-feat-row:last-child { border-bottom:none; }
  .td-feat-info { flex:1; }
  .td-feat-name { font-weight:600; font-size:.86rem; color:#111827; }
  .td-feat-status { font-size:.7rem; color:#6b7280; margin-top:2px; font-family:'Geist Mono',monospace; }
  .td-feat-status .override { color:#92400e; font-weight:600; }
  .td-toggle { width:38px; height:22px; background:#e5e7eb; border-radius:99px; position:relative; cursor:pointer; transition:background .2s; flex-shrink:0; }
  .td-toggle::after { content:''; position:absolute; left:2px; top:2px; width:18px; height:18px; background:#fff; border-radius:50%; transition:left .2s; box-shadow:0 1px 3px rgba(0,0,0,.15); }
  .td-toggle.on { background:#0ea5e9; }
  .td-toggle.on::after { left:18px; }
  .td-feat-clear { background:transparent; border:none; color:#9ca3af; cursor:pointer; font-size:.7rem; margin-left:10px; padding:2px 6px; }
  .td-feat-clear:hover { color:#0ea5e9; }

  .td-users-tbl { width:100%; border-collapse:collapse; font-size:.82rem; }
  .td-users-tbl th { background:#f9fafb; padding:8px; text-align:left; font-family:'Geist Mono',monospace; font-size:.62rem; color:#6b7280; text-transform:uppercase; }
  .td-users-tbl td { padding:9px 8px; border-bottom:1px solid #f3f4f6; }
  .td-users-tbl tr:last-child td { border-bottom:none; }

  .td-audit { font-size:.78rem; }
  .td-audit-item { padding:8px 0; border-bottom:1px solid #f3f4f6; display:flex; gap:10px; }
  .td-audit-item:last-child { border-bottom:none; }
  .td-audit-time { font-family:'Geist Mono',monospace; font-size:.66rem; color:#9ca3af; flex-shrink:0; min-width:90px; }
  .td-audit-action { font-family:'Geist Mono',monospace; color:#0ea5e9; font-weight:600; }

  .toast { position:fixed; bottom:20px; right:20px; background:#0f172a; color:#fff; padding:10px 16px; border-radius:8px; font-size:.84rem; z-index:400; opacity:0; transition:opacity .2s; pointer-events:none; }
  .toast.show { opacity:1; }
</style>

<a href="tenants.php" class="td-back">← Voltar pra lista</a>

<div class="td-hero">
  <div class="td-avatar" style="background:<?= e($brand_color) ?>"><?= e($initial) ?></div>
  <div class="td-meta">
    <h1><?= e($t['brand_name'] ?: $t['name']) ?></h1>
    <div class="sub"><?= e($t['email']) ?> · slug: <code><?= e($t['slug']) ?></code> · criado em <?= date('d/m/Y', strtotime($t['created_at'])) ?></div>
  </div>
  <div class="td-tags">
    <span class="td-tag tier <?= e($tier) ?>"><?= e($t['plan_name'] ?: 'Sem plano') ?></span>
    <span class="td-tag status <?= e($t['status']) ?>"><?= e($t['status']) ?></span>
  </div>
</div>

<!-- KPIs -->
<div class="td-kpis">
  <div class="td-kpi">
    <div class="l">Extrações no mês</div>
    <div class="v"><?= number_format($leads_used, 0, ',', '.') ?> <small>/ <?= number_format($leads_limit, 0, ',', '.') ?></small></div>
    <div class="bar"><i class="<?= $leads_pct >= 90 ? 'danger' : ($leads_pct >= 70 ? 'warn' : '') ?>" style="width:<?= $leads_pct ?>%"></i></div>
    <div class="sub"><?= $leads_pct ?>% usado · <?= number_format((int)$t['cnpj_addon_credits'], 0, ',', '.') ?> créditos extras</div>
  </div>
  <div class="td-kpi">
    <div class="l">Pipeline cards</div>
    <div class="v"><?= number_format($metrics['cards_count'], 0, ',', '.') ?> <small>/ <?= number_format((int)$t['plan_pipeline_limit'], 0, ',', '.') ?></small></div>
    <div class="sub"><?= $metrics['columns_count'] ?> coluna(s) ativa(s)</div>
  </div>
  <div class="td-kpi">
    <div class="l">Usuários</div>
    <div class="v"><?= $metrics['users_count'] ?> <small>/ <?= (int)$t['plan_users_limit'] ?></small></div>
    <div class="sub">do tenant</div>
  </div>
  <div class="td-kpi">
    <div class="l">Total histórico</div>
    <div class="v"><?= number_format($metrics['leads_used_total'], 0, ',', '.') ?></div>
    <div class="sub">extrações desde o início · <?= $metrics['lists_count'] ?> listas salvas</div>
  </div>
</div>

<?php if ($pending_pay): ?>
<!-- Banner de cobrança pendente + botão simular (sandbox testing) -->
<div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px solid #fbbf24;border-radius:12px;padding:14px 18px;margin-bottom:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <div style="font-size:1.4rem">⏳</div>
  <div style="flex:1;min-width:200px">
    <div style="font-weight:700;color:#78350f;font-size:.92rem">
      Cobrança pendente: R$ <?= number_format($pending_pay['value_cents']/100, 2, ',', '.') ?>
    </div>
    <div style="font-size:.78rem;color:#92400e;font-family:'Geist Mono',monospace;margin-top:2px">
      <?= htmlspecialchars($pending_pay['asaas_payment_id']) ?> · <?= htmlspecialchars($pending_pay['status']) ?>
      <?php if ($pending_pay['due_date']): ?> · vence <?= date('d/m', strtotime($pending_pay['due_date'])) ?><?php endif; ?>
    </div>
  </div>
  <?php if ($pending_pay['invoice_url']): ?>
    <a href="<?= htmlspecialchars($pending_pay['invoice_url']) ?>" target="_blank" class="td-action-row" style="background:#fff;border:1px solid #fbbf24;color:#78350f;padding:7px 12px;border-radius:6px;text-decoration:none;font-size:.78rem;font-weight:600">Ver fatura</a>
  <?php endif; ?>
  <button onclick="simulatePayment()" style="background:#0ea5e9;color:#fff;border:none;padding:8px 14px;border-radius:6px;font-size:.78rem;font-weight:600;cursor:pointer;font-family:inherit">
    🧪 Simular pagamento (sandbox)
  </button>
</div>
<?php endif; ?>

<!-- Ações rápidas -->
<div class="td-card" style="margin-bottom:14px">
  <h3>⚙ Ações rápidas</h3>
  <div class="td-action-row">
    <label>Mudar plano</label>
    <select id="ch-plan">
      <?php foreach ($plans as $pp): ?>
        <option value="<?= (int)$pp['id'] ?>" <?= (int)$t['plan_id'] === (int)$pp['id'] ? 'selected' : '' ?>>
          <?= e($pp['name']) ?> (<?= brl_cents_fmt((int)$pp['price_cents']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <button onclick="changePlan()">Aplicar</button>
  </div>
  <div class="td-action-row">
    <label>Status</label>
    <select id="ch-status">
      <?php foreach (['active','pending','suspended','cancelled'] as $st): ?>
        <option value="<?= $st ?>" <?= $t['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
      <?php endforeach; ?>
    </select>
    <button onclick="changeStatus()">Aplicar</button>
  </div>
  <div class="td-action-row">
    <label>+ Cota Radar</label>
    <input type="number" id="lib-credits" min="1" max="100000" placeholder="Ex: 100">
    <button onclick="liberateCredits()">Liberar créditos</button>
  </div>
</div>

<div class="td-grid">
  <!-- Feature Flags -->
  <div class="td-card">
    <h3>🚩 Feature flags</h3>
    <p style="font-size:.76rem;color:#6b7280;margin:0 0 12px">Override do que está no plano. <strong>Marcar = liberar feature pro tenant.</strong> Botão "↺" remove o override e volta a usar o default do plano.</p>
    <?php foreach ($features_all as $fkey => $finfo):
      $enabled = $features_state[$fkey];
      $has_override = isset($overrides_map[$fkey]);
      $default_enabled = in_array($tier, $finfo['default_plans'], true);
    ?>
    <div class="td-feat-row" data-feature="<?= e($fkey) ?>">
      <div class="td-feat-info">
        <div class="td-feat-name"><?= e($finfo['label']) ?></div>
        <div class="td-feat-status">
          Default do plano: <?= $default_enabled ? '✓ liberado' : '✗ bloqueado' ?>
          <?php if ($has_override): ?>
            · <span class="override">⚠ override manual: <?= $enabled ? 'liberado' : 'bloqueado' ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="td-toggle <?= $enabled ? 'on' : '' ?>" onclick="toggleFeature(this, '<?= e($fkey) ?>')"></div>
      <?php if ($has_override): ?>
        <button class="td-feat-clear" title="Remover override" onclick="clearFeature('<?= e($fkey) ?>')">↺</button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Usuários + Audit -->
  <div class="td-card">
    <h3>👥 Usuários (<?= count($users) ?>)</h3>
    <table class="td-users-tbl">
      <thead>
        <tr><th>Nome</th><th>E-mail</th><th>Role</th><th>Último login</th></tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><strong><?= e($u['name']) ?></strong></td>
            <td style="color:#6b7280"><?= e($u['email']) ?></td>
            <td><?= e($u['role'] ?: 'usuário') ?></td>
            <td style="color:#6b7280;font-family:'Geist Mono',monospace;font-size:.74rem">
              <?= $u['last_login_at'] ? date('d/m H:i', strtotime($u['last_login_at'])) : '—' ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Audit -->
<div class="td-card">
  <h3>📜 Histórico de mudanças (últimas 20)</h3>
  <div class="td-audit">
    <?php if (!$audit): ?>
      <div style="text-align:center;color:#9ca3af;padding:14px">Nenhuma ação registrada ainda.</div>
    <?php else: foreach ($audit as $a):
      $payload = $a['payload'] ? json_decode($a['payload'], true) : null;
    ?>
      <div class="td-audit-item">
        <div class="td-audit-time"><?= date('d/m H:i', strtotime($a['created_at'])) ?></div>
        <div>
          <span class="td-audit-action"><?= e($a['action']) ?></span>
          <?php if ($payload): ?>
            <span style="color:#6b7280"> · <?= e(json_encode($payload, JSON_UNESCAPED_UNICODE)) ?></span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const TENANT_ID = <?= (int)$t['id'] ?>;

function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2400);
}

async function post(action, params) {
  const fd = new FormData();
  fd.append('action', action);
  for (const k in params) fd.append(k, params[k]);
  return fetch('tenant-detail.php?id=' + TENANT_ID, { method: 'POST', body: fd }).then(r => r.json());
}

async function changePlan() {
  const plan_id = document.getElementById('ch-plan').value;
  const r = await post('change_plan', { plan_id });
  if (r.ok) { toast('✓ Plano atualizado'); setTimeout(() => location.reload(), 600); }
}

async function changeStatus() {
  const status = document.getElementById('ch-status').value;
  const r = await post('change_status', { status });
  if (r.ok) { toast('✓ Status atualizado'); setTimeout(() => location.reload(), 600); }
}

async function liberateCredits() {
  const credits = parseInt(document.getElementById('lib-credits').value);
  if (!credits || credits <= 0) { alert('Quantidade inválida'); return; }
  const r = await post('liberate_credits', { credits });
  if (r.ok) { toast('✓ ' + credits + ' créditos liberados'); setTimeout(() => location.reload(), 800); }
}

async function toggleFeature(toggle, feature) {
  toggle.classList.toggle('on');
  const enabled = toggle.classList.contains('on');
  const r = await post('set_feature', { feature, enabled: enabled ? 1 : 0 });
  if (r.ok) { toast('✓ Feature atualizada'); setTimeout(() => location.reload(), 500); }
}

async function clearFeature(feature) {
  const r = await post('clear_feature', { feature });
  if (r.ok) { toast('✓ Override removido — voltou ao default do plano'); setTimeout(() => location.reload(), 500); }
}

async function simulatePayment() {
  if (!confirm('Simular confirmação do pagamento? Vai marcar como pago, ativar o tenant e processar como se fosse webhook real do Asaas.')) return;
  const r = await post('simulate_payment_confirmed', {});
  if (r.ok) {
    toast('✓ Pagamento simulado e tenant ativado');
    setTimeout(() => location.reload(), 800);
  } else {
    alert('Erro: ' + (r.error || 'desconhecido'));
  }
}
</script>
<?php
});
