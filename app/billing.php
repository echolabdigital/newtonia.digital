<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../core/billing.php';
require_once __DIR__ . '/../core/plans.php';

$tenant = require_tenant();
$tid = (int) $tenant['id'];

billing_ensure_schema();
plans_ensure_hermes_schema();

// ─── AJAX actions ───────────────────────────────────────────────────────────
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    // CSRF obrigatório em todas as actions de billing
    if (!csrf_check()) {
        echo json_encode(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.']);
        exit;
    }

    // Retry — atualiza CPF/CNPJ (se enviado) + cria subscription
    if ($_POST['action'] === 'retry_subscription') {
        $plan_id = (int) ($tenant['plan_id'] ?? 0);
        if (!$plan_id) { echo json_encode(['error' => 'Tenant sem plano. Escolha um plano abaixo.']); exit; }

        // Se enviou CPF/CNPJ, atualiza customer no Asaas primeiro
        $cpf_cnpj = trim($_POST['cpf_cnpj'] ?? '');
        if ($cpf_cnpj !== '') {
            $upd = billing_update_customer_doc($tid, $cpf_cnpj);
            if (!$upd['ok']) { echo json_encode($upd); exit; }
        }

        $cycle = $_POST['cycle'] ?? 'MONTHLY';
        $billing = $_POST['billing_type'] ?? 'UNDEFINED';
        $r = billing_create_subscription($tid, $plan_id, $billing, $cycle);
        echo json_encode($r);
        exit;
    }

    if ($_POST['action'] === 'subscribe') {
        $plan_id = (int) ($_POST['plan_id'] ?? 0);
        $cycle = $_POST['cycle'] ?? 'MONTHLY';
        $billing = $_POST['billing_type'] ?? 'UNDEFINED'; // CREDIT_CARD, PIX, BOLETO, UNDEFINED
        $r = billing_create_subscription($tid, $plan_id, $billing, $cycle);
        echo json_encode($r);
        exit;
    }

    if ($_POST['action'] === 'buy_pack') {
        $code = $_POST['pack_code'] ?? '';
        $billing = $_POST['billing_type'] ?? 'PIX';
        $r = billing_create_lead_pack_charge($tid, $code, $billing);
        echo json_encode($r);
        exit;
    }

    if ($_POST['action'] === 'cancel') {
        $r = billing_cancel_subscription($tid);
        echo json_encode($r);
        exit;
    }

    echo json_encode(['error' => 'unknown action']);
    exit;
}

$current_plan = $tenant['plan_id'] ? db_one('SELECT * FROM plans WHERE id = ?', [$tenant['plan_id']]) : null;
$active_sub = billing_active_subscription($tid);
$pack_balance = billing_lead_pack_balance($tid);
$plans = hermes_plans_list(true); // só públicos
$packs = hermes_lead_packs();
$customer = db_one('SELECT * FROM asaas_customers WHERE tenant_id = ?', [$tid]);
$recent_payments = db_all(
    "SELECT * FROM asaas_payments WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 10",
    [$tid]
);
// Pagamento pendente mais recente (pra banner de cobrança)
$pending_payment = db_one(
    "SELECT * FROM asaas_payments WHERE tenant_id = ?
       AND status IN ('PENDING','AWAITING_RISK_ANALYSIS','OVERDUE')
     ORDER BY created_at DESC LIMIT 1",
    [$tid]
);

$cnpj_used = cnpj_monthly_used($tid);
$cnpj_limit = cnpj_monthly_limit($tid);

app_layout('Plano e Cobranças', 'billing', function() use (
    $tenant, $current_plan, $active_sub, $pack_balance, $plans, $packs, $customer, $recent_payments, $cnpj_used, $cnpj_limit, $pending_payment
) {
?>
<style>
  .bil-hero { background:#fff; border:1px solid var(--line); border-radius:12px; padding:20px 24px; margin-bottom:18px; }
  .bil-hero h1 { font-size:1.3rem; font-weight:700; margin:0 0 6px; }
  .bil-hero p { color:var(--mute); font-size:.9rem; margin:0; }
  .bil-hero .badge { font-family:'Geist Mono',monospace; font-size:.58rem; background:var(--hermes); color:#fff; padding:3px 8px; border-radius:4px; letter-spacing:.08em; text-transform:uppercase; font-weight:600; margin-left:8px; vertical-align:middle; }

  .bil-current { background:#fff; border:1px solid var(--line); border-radius:12px; padding:18px 22px; margin-bottom:18px; display:grid; grid-template-columns:auto 1fr auto; gap:18px; align-items:center; }
  .bil-current .ic { width:48px; height:48px; border-radius:10px; background:var(--hermes); color:#fff; display:flex; align-items:center; justify-content:center; }
  .bil-current h2 { font-size:1.1rem; margin:0 0 4px; }
  .bil-current .meta { font-size:.84rem; color:var(--mute); }
  .bil-current .price { font-size:1.4rem; font-weight:700; text-align:right; }
  .bil-current .price small { color:var(--mute); font-size:.55em; font-weight:500; }

  .bil-status { background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 18px; margin-bottom:18px; display:flex; gap:18px; flex-wrap:wrap; }
  .bil-status .item { flex:1; min-width:160px; }
  .bil-status .lbl { font-family:'Geist Mono',monospace; font-size:.62rem; color:var(--mute); text-transform:uppercase; letter-spacing:.06em; }
  .bil-status .val { font-size:1.2rem; font-weight:700; color:var(--ink); }
  .bil-status .sub { font-size:.74rem; color:var(--mute); margin-top:2px; }

  .bil-sec-title { font-size:1.05rem; font-weight:700; margin:24px 0 12px; color:var(--ink); }

  .bil-plans { display:grid; grid-template-columns:repeat(3, 1fr); gap:14px; margin-bottom:24px; }
  @media (max-width: 980px) { .bil-plans { grid-template-columns:1fr; } }
  .bil-plan { background:#fff; border:1px solid var(--line); border-radius:14px; padding:20px; position:relative; display:flex; flex-direction:column; }
  .bil-plan.popular { border-color:var(--hermes); box-shadow:0 4px 14px rgba(16,185,129,.1); }
  .bil-plan.popular::before { content:'⭐ Mais popular'; position:absolute; top:0; right:0; background:var(--hermes); color:#fff; font-family:'Geist Mono',monospace; font-size:.56rem; padding:3px 10px; border-radius:0 14px 0 8px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; }
  .bil-plan.current { border-color:var(--hermes); }
  .bil-plan.current::after { content:'PLANO ATUAL'; position:absolute; bottom:-1px; left:-1px; right:-1px; background:var(--hermes); color:#fff; font-family:'Geist Mono',monospace; font-size:.58rem; padding:5px; border-radius:0 0 13px 13px; font-weight:600; letter-spacing:.08em; text-align:center; }
  .bil-plan h3 { font-size:1.3rem; font-weight:700; margin:0 0 6px; }
  .bil-plan .tier-code { font-family:'Geist Mono',monospace; font-size:.62rem; color:var(--mute); text-transform:uppercase; letter-spacing:.08em; margin-bottom:8px; }
  .bil-plan .pr { font-size:2rem; font-weight:700; line-height:1; }
  .bil-plan .pr small { font-size:.55em; color:var(--mute); font-weight:500; }
  .bil-plan .annual-hint { font-family:'Geist Mono',monospace; font-size:.7rem; color:var(--hermes); margin-top:6px; font-weight:600; }
  .bil-plan .feat-list { list-style:none; padding:0; margin:18px 0; flex:1; }
  .bil-plan .feat-list li { font-size:.85rem; color:var(--ink-2); padding:5px 0; display:flex; align-items:flex-start; gap:8px; }
  .bil-plan .feat-list li::before { content:'✓'; color:var(--hermes); font-weight:700; flex-shrink:0; margin-top:1px; }
  .bil-plan .feat-list li.x::before { content:'✕'; color:var(--mute); }
  .bil-plan .feat-list li.x { color:var(--mute); }
  .bil-plan .sub-cta { background:var(--hermes); color:#fff; border:none; padding:12px 18px; border-radius:8px; font-size:.92rem; font-weight:600; cursor:pointer; font-family:inherit; width:100%; transition:all .15s; }
  .bil-plan .sub-cta:hover { background:#0ea371; }
  .bil-plan .sub-cta.ghost { background:#fff; color:var(--hermes); border:1px solid var(--hermes); }
  .bil-plan .sub-cta.ghost:hover { background:var(--bone); }
  .bil-plan .sub-cta:disabled { background:var(--bone); color:var(--mute); cursor:not-allowed; }

  /* Lead packs */
  .bil-packs { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; margin-bottom:24px; }
  @media (max-width: 980px) { .bil-packs { grid-template-columns:1fr 1fr; } }
  .bil-pack { background:#fff; border:1px solid var(--line); border-radius:10px; padding:16px; text-align:center; }
  .bil-pack .pl-num { font-size:1.3rem; font-weight:700; color:var(--ink); }
  .bil-pack .pl-label { font-family:'Geist Mono',monospace; font-size:.62rem; color:var(--mute); text-transform:uppercase; margin-bottom:4px; }
  .bil-pack .pl-price { font-size:1rem; font-weight:600; color:var(--hermes); margin:8px 0 4px; }
  .bil-pack .pl-per { font-family:'Geist Mono',monospace; font-size:.66rem; color:var(--mute); margin-bottom:10px; }
  .bil-pack button { background:var(--hermes); color:#fff; border:none; padding:8px; border-radius:7px; width:100%; font-size:.8rem; cursor:pointer; font-family:inherit; font-weight:500; }
  .bil-pack button:hover { background:#0ea371; }

  /* Payment history */
  .bil-history { background:#fff; border:1px solid var(--line); border-radius:12px; padding:18px; }
  .bil-history h3 { font-size:.95rem; margin:0 0 12px; padding-bottom:8px; border-bottom:1px solid var(--line); }
  .bil-history-tbl { width:100%; border-collapse:collapse; font-size:.84rem; }
  .bil-history-tbl th { background:var(--bone); padding:8px; text-align:left; font-family:'Geist Mono',monospace; font-size:.62rem; color:var(--mute); text-transform:uppercase; }
  .bil-history-tbl td { padding:9px 8px; border-bottom:1px solid var(--line); }
  .bil-history-tbl tr:last-child td { border-bottom:none; }
  .bil-empty { text-align:center; color:var(--mute); padding:18px; }

  /* Modal */
  .bm-bg { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:300; align-items:center; justify-content:center; }
  .bm-bg.open { display:flex; }
  .bm-box { background:#fff; border-radius:14px; padding:24px; max-width:440px; width:90%; box-shadow:0 20px 50px rgba(0,0,0,.2); }
  .bm-box h3 { margin:0 0 14px; font-size:1.1rem; }
  .bm-box label { display:block; font-family:'Geist Mono',monospace; font-size:.62rem; color:var(--mute); text-transform:uppercase; letter-spacing:.06em; margin-bottom:5px; }
  .bm-box select { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:7px; font-size:.88rem; font-family:inherit; margin-bottom:14px; }
  .bm-box .summary { background:var(--bone); padding:12px 14px; border-radius:8px; margin-bottom:14px; font-size:.86rem; }
  .bm-actions { display:flex; gap:8px; justify-content:flex-end; padding-top:12px; border-top:1px solid var(--line); }
  .bm-btn { padding:9px 16px; border:none; border-radius:7px; font-size:.86rem; font-weight:600; cursor:pointer; font-family:inherit; }
  .bm-btn.primary { background:var(--hermes); color:#fff; }
  .bm-btn.ghost { background:#fff; color:var(--ink-2); border:1px solid var(--line); }

  .toast { position:fixed; bottom:20px; right:20px; background:#0f172a; color:#fff; padding:11px 18px; border-radius:8px; font-size:.86rem; z-index:400; opacity:0; transition:opacity .2s; pointer-events:none; }
  .toast.show { opacity:1; }
</style>

<?php if (!empty($_GET['err'])): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:14px;font-size:.86rem">
  ⚠ <strong>Algo deu errado:</strong> <?= htmlspecialchars($_GET['err']) ?>
</div>
<?php endif; ?>

<?php if ($pending_payment): ?>
<div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px solid #fbbf24;border-radius:14px;padding:18px 22px;margin-bottom:18px;display:flex;align-items:center;gap:18px;flex-wrap:wrap">
  <div style="font-size:1.8rem">⏳</div>
  <div style="flex:1;min-width:200px">
    <div style="font-weight:700;color:#78350f;font-size:1.02rem;margin-bottom:2px">Cobrança pendente: <?= brl_cents_fmt((int)$pending_payment['value_cents']) ?></div>
    <div style="font-size:.84rem;color:#92400e">
      <?= htmlspecialchars($pending_payment['kind'] === 'lead_pack' ? 'Lead pack' : 'Assinatura HERMES.b2b') ?>
      <?php if ($pending_payment['due_date']): ?> · vence em <?= date('d/m/Y', strtotime($pending_payment['due_date'])) ?><?php endif; ?>
      · Pague com PIX ou Cartão pra ativar seu acesso.
    </div>
  </div>
  <?php if ($pending_payment['invoice_url']): ?>
    <a href="<?= htmlspecialchars($pending_payment['invoice_url']) ?>" target="_blank"
       style="background:#0f172a;color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;white-space:nowrap">
      💳 Pagar agora →
    </a>
  <?php endif; ?>
</div>
<?php elseif ($current_plan && (int)$current_plan['price_cents'] > 0 && !$active_sub && $customer):
  $has_doc = !empty($customer['cpf_cnpj']);
?>
<!-- Plano selecionado mas sem subscription — completar cadastro fiscal pra emitir -->
<div style="background:#fff;border:2px solid var(--hermes);border-radius:14px;padding:22px 26px;margin-bottom:18px;box-shadow:0 4px 14px rgba(16,185,129,.08)">
  <div style="display:flex;align-items:flex-start;gap:14px;margin-bottom:18px">
    <div style="width:38px;height:38px;border-radius:50%;background:#dcfce7;color:#166534;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">📋</div>
    <div style="flex:1">
      <h3 style="font-size:1.05rem;font-weight:700;margin:0 0 4px">Falta 1 passo pra ativar o <?= htmlspecialchars($current_plan['name']) ?></h3>
      <p style="font-size:.88rem;color:var(--mute);margin:0;line-height:1.45">Pra emitir a primeira cobrança no Asaas, precisamos do <strong>CPF</strong> (pessoa física) ou <strong>CNPJ</strong> (empresa). Esse dado fica registrado na nota fiscal.</p>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:14px">
    <div>
      <label style="font-family:'Geist Mono',monospace;font-size:.62rem;color:var(--mute);text-transform:uppercase;letter-spacing:.06em;font-weight:600;display:block;margin-bottom:5px">CPF ou CNPJ</label>
      <input type="text" id="retry-cpf" placeholder="000.000.000-00 ou 00.000.000/0000-00"
             value="<?= htmlspecialchars($customer['cpf_cnpj'] ?? '') ?>"
             style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:7px;font-family:'Geist Mono',monospace;font-size:.88rem">
    </div>
    <div>
      <label style="font-family:'Geist Mono',monospace;font-size:.62rem;color:var(--mute);text-transform:uppercase;letter-spacing:.06em;font-weight:600;display:block;margin-bottom:5px">Ciclo</label>
      <select id="retry-cycle" style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:7px;font-family:inherit;font-size:.86rem;background:#fff">
        <option value="MONTHLY">Mensal — <?= brl_cents_fmt((int)$current_plan['price_cents']) ?></option>
        <?php if ((int)$current_plan['annual_price_cents'] > 0): ?>
        <option value="YEARLY">Anual — <?= brl_cents_fmt((int)$current_plan['annual_price_cents']) ?> (2 meses grátis)</option>
        <?php endif; ?>
      </select>
    </div>
    <div>
      <label style="font-family:'Geist Mono',monospace;font-size:.62rem;color:var(--mute);text-transform:uppercase;letter-spacing:.06em;font-weight:600;display:block;margin-bottom:5px">Pagamento</label>
      <select id="retry-billing" style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:7px;font-family:inherit;font-size:.86rem;background:#fff">
        <option value="PIX">⚡ PIX (recomendado)</option>
        <option value="CREDIT_CARD">💳 Cartão de crédito</option>
      </select>
    </div>
  </div>

  <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
    <div style="font-size:.78rem;color:var(--mute)">🔒 Seus dados são protegidos. Asaas é certificado PCI e LGPD.</div>
    <button onclick="retrySubscription()" id="retry-btn" style="background:var(--hermes);color:#fff;border:none;padding:11px 22px;border-radius:8px;font-size:.92rem;font-weight:600;cursor:pointer;font-family:inherit">
      Gerar cobrança →
    </button>
  </div>

  <div id="retry-error" style="display:none;margin-top:14px;padding:11px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:7px;font-size:.82rem;color:#991b1b"></div>
</div>

<script>
// Mask CPF/CNPJ dinâmico
(function() {
  const inp = document.getElementById('retry-cpf');
  if (!inp) return;
  inp.addEventListener('input', e => {
    let v = e.target.value.replace(/\D/g, '').slice(0, 14);
    if (v.length > 11) {
      // CNPJ
      v = v.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})/, '$1.$2.$3/$4-$5');
    } else if (v.length > 9) {
      v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{0,2})/, '$1.$2.$3-$4');
    } else if (v.length > 6) {
      v = v.replace(/(\d{3})(\d{3})(\d{0,3})/, '$1.$2.$3');
    } else if (v.length > 3) {
      v = v.replace(/(\d{3})(\d{0,3})/, '$1.$2');
    }
    e.target.value = v;
  });
})();
</script>
<?php endif; ?>

<div class="bil-hero">
  <h1>Plano e Cobranças <span class="badge">BILLING</span></h1>
  <p>Gerencie sua assinatura, compre lead packs e veja o histórico de pagamentos.</p>
</div>

<!-- Plano atual -->
<?php if ($current_plan): ?>
<div class="bil-current">
  <div class="ic">
    <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
  </div>
  <div>
    <h2>Plano <?= htmlspecialchars($current_plan['name']) ?></h2>
    <div class="meta">
      <?= $active_sub ? 'Status: <strong>' . htmlspecialchars(strtolower($active_sub['status'])) . '</strong>' : 'Sem assinatura ativa' ?>
      <?php if ($active_sub && $active_sub['next_due_date']): ?>
        · próxima cobrança em <strong><?= date('d/m/Y', strtotime($active_sub['next_due_date'])) ?></strong>
      <?php endif; ?>
    </div>
  </div>
  <div class="price">
    <?php if ((int)$current_plan['price_cents'] > 0): ?>
      <?= brl_cents_fmt((int)$current_plan['price_cents']) ?> <small>/ mês</small>
    <?php else: ?>
      <small>Trial</small>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="bil-status">
  <div class="item">
    <div class="lbl">Extrações no mês</div>
    <div class="val"><?= number_format($cnpj_used, 0, ',', '.') ?> / <?= number_format($cnpj_limit, 0, ',', '.') ?></div>
    <div class="sub">cota do plano + créditos extras</div>
  </div>
  <div class="item">
    <div class="lbl">Lead pack — saldo</div>
    <div class="val"><?= number_format($pack_balance, 0, ',', '.') ?></div>
    <div class="sub">extrações extras disponíveis</div>
  </div>
  <div class="item">
    <div class="lbl">Customer Asaas</div>
    <div class="val" style="font-size:.92rem;font-family:'Geist Mono',monospace"><?= $customer ? htmlspecialchars($customer['asaas_customer_id']) : '—' ?></div>
    <div class="sub"><?= $customer ? 'sincronizado' : 'será criado na 1ª cobrança' ?></div>
  </div>
</div>

<!-- Planos disponíveis -->
<div class="bil-sec-title">📋 Planos disponíveis</div>
<div class="bil-plans">
<?php foreach ($plans as $p):
  $is_current = $current_plan && (int)$current_plan['id'] === (int)$p['id'];
  $is_popular = !empty($p['popular']);
?>
  <div class="bil-plan <?= $is_popular ? 'popular' : '' ?> <?= $is_current ? 'current' : '' ?>">
    <div class="tier-code">// <?= strtoupper($p['tier_code']) ?></div>
    <h3><?= htmlspecialchars($p['name']) ?></h3>
    <div class="pr"><?= brl_cents_fmt((int)$p['price_cents']) ?><small> / mês</small></div>
    <?php if ((int)$p['annual_price_cents'] > 0): ?>
      <div class="annual-hint">Anual: <?= brl_cents_fmt((int)$p['annual_price_cents']) ?> · 2 meses grátis</div>
    <?php endif; ?>
    <ul class="feat-list">
      <li><strong><?= number_format((int)$p['limit_cnpj_monthly'], 0, ',', '.') ?></strong> extrações Radar/mês</li>
      <li><strong><?= number_format((int)$p['limit_contacts'], 0, ',', '.') ?></strong> cards no Pipeline</li>
      <li><strong><?= (int)$p['users_limit'] ?></strong> usuário<?= (int)$p['users_limit'] > 1 ? 's' : '' ?></li>
      <li><strong><?= (int)$p['mail_self_limit'] ?></strong> conta Mail Lab self-service</li>
      <li><?= htmlspecialchars(support_label($p['support_level'])) ?></li>
    </ul>
    <?php if ($is_current): ?>
      <button class="sub-cta" disabled>✓ Plano atual</button>
    <?php else: ?>
      <button class="sub-cta" onclick='openSubModal(<?= htmlspecialchars(json_encode(['id'=>(int)$p['id'],'name'=>$p['name'],'monthly'=>(int)$p['price_cents'],'annual'=>(int)$p['annual_price_cents']]), ENT_QUOTES) ?>)'>
        <?= $current_plan ? 'Trocar pra este' : 'Assinar' ?>
      </button>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<!-- Lead Packs -->
<div class="bil-sec-title">📦 Lead Packs · top-up de extrações Radar</div>
<div class="bil-packs">
<?php foreach ($packs as $pk): ?>
  <div class="bil-pack">
    <div class="pl-label">Pack <?= number_format($pk['leads'], 0, ',', '.') ?></div>
    <div class="pl-num"><?= number_format($pk['leads'], 0, ',', '.') ?> leads</div>
    <div class="pl-price"><?= brl_cents_fmt($pk['price_cents']) ?></div>
    <div class="pl-per">R$ <?= number_format($pk['per_lead'], 2, ',', '.') ?> / lead</div>
    <button onclick='openPackModal(<?= htmlspecialchars(json_encode($pk), ENT_QUOTES) ?>)'>Comprar com PIX</button>
  </div>
<?php endforeach; ?>
</div>

<!-- Histórico -->
<div class="bil-history">
  <h3>📜 Histórico de pagamentos</h3>
  <?php if (!$recent_payments): ?>
    <div class="bil-empty">Nenhum pagamento ainda.</div>
  <?php else: ?>
    <table class="bil-history-tbl">
      <thead>
        <tr><th>Data</th><th>Descrição</th><th>Valor</th><th>Status</th><th>Ação</th></tr>
      </thead>
      <tbody>
      <?php foreach ($recent_payments as $pay): ?>
        <tr>
          <td><?= date('d/m/Y', strtotime($pay['created_at'])) ?></td>
          <td><?= htmlspecialchars($pay['kind'] === 'lead_pack' ? 'Lead pack' : 'Assinatura') ?></td>
          <td><strong><?= brl_cents_fmt((int)$pay['value_cents']) ?></strong></td>
          <td><span style="font-family:'Geist Mono',monospace;font-size:.7rem"><?= htmlspecialchars($pay['status']) ?></span></td>
          <td>
            <?php if ($pay['invoice_url']): ?>
              <a href="<?= htmlspecialchars($pay['invoice_url']) ?>" target="_blank" style="color:var(--hermes);text-decoration:none;font-weight:500">Ver fatura →</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- Cancelar assinatura -->
<?php if ($active_sub && strtoupper($active_sub['status']) === 'ACTIVE'): ?>
<div style="margin-top:28px;padding:18px 22px;background:#fff;border:1px solid #fecaca;border-radius:12px">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
    <div>
      <div style="font-family:'Geist Mono',monospace;font-size:.62rem;color:var(--mute);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">// zona de risco</div>
      <div style="font-weight:600;font-size:.95rem;color:var(--ink)">Cancelar assinatura</div>
      <div style="font-size:.82rem;color:var(--mute);margin-top:3px">
        Ao cancelar, seu acesso continua até o fim do período pago. Não é reembolsável.
      </div>
    </div>
    <button onclick="openCancelModal()"
            style="background:#fff;color:var(--coral,#be123c);border:1px solid #fecaca;padding:9px 18px;border-radius:8px;font-size:.86rem;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap;transition:all .15s"
            onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='#fff'">
      Cancelar assinatura
    </button>
  </div>
</div>
<?php endif; ?>

<!-- Modal: confirmar cancelamento -->
<div class="bm-bg" id="cancel-modal" onclick="if(event.target.id==='cancel-modal')closeCancelModal()">
  <div class="bm-box">
    <h3 style="color:var(--coral,#be123c)">Cancelar assinatura?</h3>
    <div style="font-size:.88rem;color:var(--mute);margin-bottom:18px;line-height:1.55">
      Tem certeza que deseja cancelar o plano <strong><?= htmlspecialchars($current_plan['name'] ?? '') ?></strong>?<br><br>
      <strong>O que acontece:</strong>
      <ul style="margin:8px 0 0 16px;line-height:1.8">
        <li>Acesso mantido até o fim do período atual</li>
        <li>Renovação automática cancelada</li>
        <li>Lead packs comprados não expiram</li>
        <li>Não há reembolso por período restante</li>
      </ul>
    </div>
    <div class="bm-actions">
      <button class="bm-btn ghost" onclick="closeCancelModal()">Manter assinatura</button>
      <button class="bm-btn" id="cancel-confirm-btn"
              style="background:var(--coral,#be123c);color:#fff"
              onclick="doCancel()">Sim, cancelar</button>
    </div>
  </div>
</div>

<!-- Modal: subscribe -->
<div class="bm-bg" id="sub-modal" onclick="if(event.target.id==='sub-modal')closeSubModal()">
  <div class="bm-box">
    <h3 id="sub-title">Assinar plano</h3>
    <div class="summary" id="sub-summary"></div>
    <label>Ciclo de cobrança</label>
    <select id="sub-cycle" onchange="updateSubSummary()">
      <option value="MONTHLY">Mensal</option>
      <option value="YEARLY">Anual (2 meses grátis)</option>
    </select>
    <label>Forma de pagamento</label>
    <select id="sub-billing">
      <option value="PIX">⚡ PIX (recomendado)</option>
      <option value="CREDIT_CARD">💳 Cartão de crédito (recorrente)</option>
    </select>
    <input type="hidden" id="sub-plan-id">
    <div class="bm-actions">
      <button class="bm-btn ghost" onclick="closeSubModal()">Cancelar</button>
      <button class="bm-btn primary" onclick="doSubscribe()">Criar cobrança</button>
    </div>
  </div>
</div>

<!-- Modal: lead pack -->
<div class="bm-bg" id="pack-modal" onclick="if(event.target.id==='pack-modal')closePackModal()">
  <div class="bm-box">
    <h3 id="pack-title">Comprar Lead Pack</h3>
    <div class="summary" id="pack-summary"></div>
    <label>Forma de pagamento</label>
    <select id="pack-billing">
      <option value="PIX">⚡ PIX (instantâneo)</option>
      <option value="CREDIT_CARD">💳 Cartão de crédito</option>
    </select>
    <input type="hidden" id="pack-code">
    <div class="bm-actions">
      <button class="bm-btn ghost" onclick="closePackModal()">Cancelar</button>
      <button class="bm-btn primary" onclick="doBuyPack()">Gerar cobrança</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const _csrf = <?= json_encode(csrf_token()) ?>;
let _curPlan = null;
let _curPack = null;

// Helper: monta FormData com CSRF já incluído
function billingFd(data) {
  const fd = new FormData();
  fd.append('_csrf', _csrf);
  for (const [k, v] of Object.entries(data)) fd.append(k, v);
  return fd;
}

function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2400);
}

function brl(cents) { return 'R$ ' + (cents/100).toFixed(2).replace('.',','); }

function openSubModal(plan) {
  _curPlan = plan;
  document.getElementById('sub-title').textContent = 'Assinar: ' + plan.name;
  document.getElementById('sub-plan-id').value = plan.id;
  document.getElementById('sub-cycle').value = 'MONTHLY';
  document.getElementById('sub-billing').value = 'UNDEFINED';
  updateSubSummary();
  document.getElementById('sub-modal').classList.add('open');
}
function closeSubModal() { document.getElementById('sub-modal').classList.remove('open'); }

function updateSubSummary() {
  if (!_curPlan) return;
  const cycle = document.getElementById('sub-cycle').value;
  const value = cycle === 'YEARLY' ? _curPlan.annual : _curPlan.monthly;
  const period = cycle === 'YEARLY' ? '/ ano' : '/ mês';
  document.getElementById('sub-summary').innerHTML =
    '<strong>' + _curPlan.name + '</strong> · ' + (cycle === 'YEARLY' ? 'Anual' : 'Mensal') +
    '<br><span style="font-size:1.2rem;font-weight:700;color:var(--hermes)">' + brl(value) + '</span> <span style="color:var(--mute)">' + period + '</span>';
}

async function doSubscribe() {
  const fd = billingFd({
    action: 'subscribe',
    plan_id: document.getElementById('sub-plan-id').value,
    cycle: document.getElementById('sub-cycle').value,
    billing_type: document.getElementById('sub-billing').value,
  });
  const r = await fetch('billing.php', { method: 'POST', body: fd }).then(r => r.json());
  if (r.ok) {
    toast('✓ Cobrança gerada! Verifique o e-mail.');
    closeSubModal();
    setTimeout(() => location.reload(), 1500);
  } else {
    alert('Erro: ' + (r.error || 'desconhecido'));
  }
}

function openPackModal(pack) {
  _curPack = pack;
  document.getElementById('pack-title').textContent = 'Comprar ' + pack.leads + ' leads';
  document.getElementById('pack-code').value = pack.code;
  document.getElementById('pack-summary').innerHTML =
    '<strong>' + pack.leads.toLocaleString('pt-BR') + ' extrações Radar</strong><br>' +
    '<span style="font-size:1.2rem;font-weight:700;color:var(--hermes)">' + brl(pack.price_cents) + '</span>' +
    '<br><small style="color:var(--mute)">R$ ' + pack.per_lead.toFixed(2).replace('.',',') + ' / lead · validade 12 meses</small>';
  document.getElementById('pack-modal').classList.add('open');
}
function closePackModal() { document.getElementById('pack-modal').classList.remove('open'); }

async function retrySubscription() {
  const cpf = (document.getElementById('retry-cpf')?.value || '').replace(/\D/g, '');
  const cycle = document.getElementById('retry-cycle').value;
  const billing = document.getElementById('retry-billing').value;
  const errBox = document.getElementById('retry-error');
  const btn = document.getElementById('retry-btn');
  errBox.style.display = 'none';

  // Validação local
  if (!cpf || (cpf.length !== 11 && cpf.length !== 14)) {
    errBox.style.display = 'block';
    errBox.textContent = 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.';
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Gerando...';

  const fd = billingFd({
    action: 'retry_subscription',
    cpf_cnpj: cpf,
    cycle: cycle,
    billing_type: billing,
  });

  const r = await fetch('billing.php', { method: 'POST', body: fd }).then(r => r.json());

  if (r.ok) {
    toast('✓ Cobrança gerada! Redirecionando...');
    setTimeout(() => location.reload(), 1000);
  } else {
    btn.disabled = false;
    btn.textContent = 'Gerar cobrança →';
    errBox.style.display = 'block';
    errBox.innerHTML = '<strong>Não foi possível gerar:</strong> ' + (r.error || 'erro desconhecido') + (r.http ? ' <span style="font-family:Geist Mono,monospace;font-size:.7rem;opacity:.6">(HTTP ' + r.http + ')</span>' : '');
  }
}

function openCancelModal() { document.getElementById('cancel-modal').classList.add('open'); }
function closeCancelModal() { document.getElementById('cancel-modal').classList.remove('open'); }

async function doCancel() {
  const btn = document.getElementById('cancel-confirm-btn');
  btn.disabled = true;
  btn.textContent = 'Cancelando...';
  const fd = billingFd({ action: 'cancel' });
  const r = await fetch('billing.php', { method: 'POST', body: fd }).then(r => r.json());
  if (r.ok) {
    toast('Assinatura cancelada. Acesso mantido até o fim do período.');
    closeCancelModal();
    setTimeout(() => location.reload(), 1800);
  } else {
    btn.disabled = false;
    btn.textContent = 'Sim, cancelar';
    alert('Erro: ' + (r.error || 'desconhecido'));
  }
}

async function doBuyPack() {
  const fd = billingFd({
    action: 'buy_pack',
    pack_code: document.getElementById('pack-code').value,
    billing_type: document.getElementById('pack-billing').value,
  });
  const r = await fetch('billing.php', { method: 'POST', body: fd }).then(r => r.json());
  if (r.ok) {
    toast('✓ Cobrança PIX gerada!');
    closePackModal();
    if (r.payment.invoiceUrl) {
      setTimeout(() => window.open(r.payment.invoiceUrl, '_blank'), 600);
    }
    setTimeout(() => location.reload(), 2000);
  } else {
    alert('Erro: ' + (r.error || 'desconhecido'));
  }
}
</script>

<!-- Rodapé de compliance PCI / segurança -->
<div style="margin-top:32px;padding:14px 18px;background:#f8fafc;border:1px solid var(--line);border-radius:10px;display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap;text-align:center">
  <div style="display:flex;align-items:center;gap:6px;font-size:.75rem;color:var(--mute)">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    Dados de cartão processados pela <strong style="color:var(--ink)">Asaas</strong> · certificada <strong style="color:var(--ink)">PCI DSS</strong>
  </div>
  <div style="width:1px;height:14px;background:var(--line)"></div>
  <div style="display:flex;align-items:center;gap:6px;font-size:.75rem;color:var(--mute)">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
    Nunca armazenamos dados do seu cartão
  </div>
  <div style="width:1px;height:14px;background:var(--line)"></div>
  <div style="display:flex;align-items:center;gap:6px;font-size:.75rem;color:var(--mute)">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    Conforme <strong style="color:var(--ink)">LGPD</strong> · <a href="/privacy.php" style="color:var(--hermes);text-decoration:none">Política de Privacidade</a>
  </div>
</div>
<?php
});
