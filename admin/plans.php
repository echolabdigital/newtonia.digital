<?php
require_once __DIR__ . '/../config.php';
require_super_admin();
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../core/plans.php';

// Migra schema + seed dos 4 tiers se necessário
plans_ensure_hermes_schema();

// Salvar edição
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $pid = (int) ($_POST['plan_id'] ?? 0);
        $data = [
            'name'               => trim($_POST['name'] ?? ''),
            'price_cents'        => (int) round(((float) str_replace(',', '.', $_POST['price_monthly'] ?? 0)) * 100),
            'annual_price_cents' => (int) round(((float) str_replace(',', '.', $_POST['price_annual'] ?? 0)) * 100),
            'users_limit'        => (int) ($_POST['users_limit']        ?? 1),
            'limit_contacts'     => (int) ($_POST['pipeline_limit']     ?? 0),
            'limit_cnpj_monthly' => (int) ($_POST['radar_limit']        ?? 0),
            'mail_self_limit'    => (int) ($_POST['mail_self_limit']    ?? 1),
            'trial_days'         => (int) ($_POST['trial_days']         ?? 0),
            'support_level'      => $_POST['support_level']             ?? 'email',
            'popular'            => isset($_POST['popular']) ? 1 : 0,
            'visible_public'     => isset($_POST['visible_public']) ? 1 : 0,
            'active'             => isset($_POST['active']) ? 1 : 0,
        ];
        if ($pid) {
            db_update('plans', $data, 'id = :id', ['id' => $pid]);
            audit_log('plan.updated', 'plan', $pid);
            flash_set('success', 'Plano atualizado.');
        }
    } catch (\Throwable $e) {
        flash_set('error', 'Erro: ' . $e->getMessage());
    }
    header('Location: plans.php'); exit;
}

$plans = hermes_plans_list();
$packs = hermes_lead_packs();

admin_layout('Planos HERMES.b2b', 'plans', function() use ($plans, $packs) {
?>
<style>
  /* HERMES tokens locais */
  .hp-hero { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 22px; margin-bottom:18px; }
  .hp-hero h2 { font-size:1.05rem; margin:0 0 4px; }
  .hp-hero p  { font-size:.85rem; color:#6b7280; margin:0; }

  .hp-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:14px; margin-bottom:24px; }
  @media (max-width: 1100px) { .hp-grid { grid-template-columns:repeat(2, 1fr); } }

  .hp-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:20px; display:flex; flex-direction:column; position:relative; overflow:hidden; transition:all .15s; }
  .hp-card:hover { box-shadow:0 4px 14px rgba(0,0,0,.06); border-color:#10b981; }
  .hp-card.popular { border-color:#10b981; box-shadow:0 4px 14px rgba(16,185,129,.12); }
  .hp-card.popular::before { content:'⭐ Mais popular'; position:absolute; top:0; right:0; background:#10b981; color:#fff; font-family:'Geist Mono',monospace; font-size:.56rem; padding:3px 10px; border-radius:0 14px 0 8px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; }
  .hp-card .tier { font-family:'Geist Mono',monospace; font-size:.62rem; color:#6b7280; text-transform:uppercase; letter-spacing:.08em; font-weight:600; margin-bottom:6px; }
  .hp-card h3 { font-size:1.2rem; font-weight:700; margin:0 0 8px; color:#111827; }
  .hp-card .price { font-size:1.8rem; font-weight:700; color:#111827; letter-spacing:-0.02em; line-height:1; }
  .hp-card .price small { font-size:.7rem; color:#6b7280; font-weight:500; }
  .hp-card .annual { font-family:'Geist Mono',monospace; font-size:.7rem; color:#10b981; margin-top:4px; font-weight:600; }
  .hp-card .annual.disabled { color:#9ca3af; }
  .hp-divider { border-top:1px solid #f3f4f6; margin:14px 0; }
  .hp-feat { display:flex; align-items:center; justify-content:space-between; padding:5px 0; font-size:.8rem; color:#374151; }
  .hp-feat .label { display:flex; align-items:center; gap:5px; color:#6b7280; }
  .hp-feat .val { font-weight:600; color:#111827; font-family:'Geist Mono',monospace; }
  .hp-badges { display:flex; gap:5px; flex-wrap:wrap; margin-top:10px; }
  .hp-badges span { font-family:'Geist Mono',monospace; font-size:.56rem; padding:2px 6px; border-radius:4px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; }
  .hp-badges .on { background:#dcfce7; color:#166534; }
  .hp-badges .off { background:#f3f4f6; color:#6b7280; }
  .hp-badges .priv { background:#fef3c7; color:#92400e; }
  .hp-edit { margin-top:auto; padding-top:14px; }
  .hp-edit-btn { width:100%; padding:8px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:7px; cursor:pointer; font-size:.82rem; font-weight:500; color:#374151; font-family:inherit; transition:all .15s; }
  .hp-edit-btn:hover { background:#f9fafb; border-color:#10b981; color:#059669; }

  /* Lead Packs */
  .hp-packs { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 22px; margin-bottom:18px; }
  .hp-packs h2 { font-size:1rem; margin:0 0 4px; }
  .hp-packs p  { font-size:.82rem; color:#6b7280; margin:0 0 14px; }
  .hp-packs-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; }
  @media (max-width: 900px) { .hp-packs-grid { grid-template-columns:repeat(2, 1fr); } }
  .hp-pack { background:#f9fafb; border-radius:10px; padding:14px; text-align:center; }
  .hp-pack .pl-leads { font-family:'Geist Mono',monospace; font-size:.66rem; color:#6b7280; text-transform:uppercase; letter-spacing:.06em; }
  .hp-pack .pl-num { font-size:1.4rem; font-weight:700; color:#111827; margin:4px 0 6px; }
  .hp-pack .pl-price { font-size:1rem; font-weight:600; color:#10b981; }
  .hp-pack .pl-per { font-family:'Geist Mono',monospace; font-size:.66rem; color:#6b7280; margin-top:4px; }

  /* Edit form */
  .hp-edit-panel { display:none; background:#fff; border:1px solid #10b981; border-radius:12px; padding:22px; margin-bottom:18px; }
  .hp-edit-panel.open { display:block; }
  .hp-edit-panel h3 { font-size:1.05rem; margin:0 0 14px; color:#111827; }
  .hp-edit-form { display:grid; grid-template-columns:repeat(2, 1fr); gap:14px; }
  .hp-edit-form .full { grid-column:1 / -1; }
  .hp-edit-form label { font-family:'Geist Mono',monospace; font-size:.62rem; color:#6b7280; text-transform:uppercase; letter-spacing:.06em; font-weight:600; display:block; margin-bottom:4px; }
  .hp-edit-form input, .hp-edit-form select { width:100%; padding:8px 12px; border:1px solid #e5e7eb; border-radius:7px; font-size:.86rem; font-family:inherit; }
  .hp-edit-form input:focus, .hp-edit-form select:focus { outline:none; border-color:#10b981; box-shadow:0 0 0 3px rgba(16,185,129,.1); }
  .hp-edit-form .checkrow { display:flex; align-items:center; gap:6px; }
  .hp-edit-form .checkrow input { width:auto; }
  .hp-edit-form .checkrow label { margin:0; font-size:.8rem; color:#111827; text-transform:none; letter-spacing:normal; font-weight:500; font-family:inherit; }
  .hp-actions { display:flex; gap:10px; justify-content:flex-end; padding-top:16px; border-top:1px solid #f3f4f6; margin-top:16px; }
  .hp-btn { padding:9px 18px; border:none; border-radius:7px; font-size:.86rem; font-weight:600; cursor:pointer; font-family:inherit; }
  .hp-btn-save { background:#10b981; color:#fff; }
  .hp-btn-save:hover { background:#0ea371; }
  .hp-btn-cancel { background:#fff; color:#374151; border:1px solid #e5e7eb; }
</style>

<div class="hp-hero">
  <h2>💰 Planos HERMES.b2b</h2>
  <p>4 tiers + Lead Packs avulsos. Edição não afeta tenants já criados — mudanças se aplicam só pra novos assinantes ou ao trocar plano.</p>
</div>

<!-- 4 TIERS COMO CARDS -->
<div class="hp-grid">
<?php foreach ($plans as $p):
  $tier = $p['tier_code'];
  $isPopular = !empty($p['popular']);
?>
  <div class="hp-card <?= $isPopular ? 'popular' : '' ?>">
    <div class="tier">// <?= htmlspecialchars(strtoupper($tier)) ?></div>
    <h3><?= htmlspecialchars($p['name']) ?></h3>
    <div class="price">
      <?php if ((int)$p['price_cents'] === 0): ?>
        <?php if ((int)$p['trial_days'] > 0): ?>
          <?= (int)$p['trial_days'] ?> dias <small>grátis</small>
        <?php else: ?>
          Consulte
        <?php endif; ?>
      <?php else: ?>
        <?= brl_cents_fmt((int)$p['price_cents']) ?> <small>/ mês</small>
      <?php endif; ?>
    </div>
    <?php if ((int)$p['annual_price_cents'] > 0): ?>
      <div class="annual">Anual: <?= brl_cents_fmt((int)$p['annual_price_cents']) ?> (2 meses grátis)</div>
    <?php else: ?>
      <div class="annual disabled">—</div>
    <?php endif; ?>

    <div class="hp-divider"></div>

    <div class="hp-feat"><span class="label">🎯 Radar/mês</span><span class="val"><?= number_format((int)$p['limit_cnpj_monthly'], 0, ',', '.') ?></span></div>
    <div class="hp-feat"><span class="label">📋 Pipeline</span><span class="val"><?= number_format((int)$p['limit_contacts'], 0, ',', '.') ?></span></div>
    <div class="hp-feat"><span class="label">👥 Usuários</span><span class="val"><?= (int)$p['users_limit'] ?></span></div>
    <div class="hp-feat"><span class="label">✉ Mail Lab</span><span class="val"><?= (int)$p['mail_self_limit'] ?></span></div>
    <div class="hp-feat"><span class="label">🎧 Suporte</span><span class="val" style="font-size:.7rem;font-family:inherit"><?= htmlspecialchars(support_label($p['support_level'])) ?></span></div>

    <div class="hp-badges">
      <?= $p['active']         ? '<span class="on">● Ativo</span>'    : '<span class="off">○ Inativo</span>' ?>
      <?= $p['visible_public'] ? '<span class="on">Público</span>'    : '<span class="priv">Privado</span>' ?>
      <?php if ($isPopular): ?><span class="on">⭐ Popular</span><?php endif; ?>
    </div>

    <div class="hp-edit">
      <button type="button" class="hp-edit-btn" onclick='editTier(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)'>✎ Editar plano</button>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- EDIT FORM (escondido até clicar em editar) -->
<div class="hp-edit-panel" id="edit-panel">
  <h3 id="edit-title">Editando plano</h3>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="plan_id" id="f-plan-id">
    <div class="hp-edit-form">
      <div class="full">
        <label>Nome do plano</label>
        <input type="text" name="name" id="f-name" required>
      </div>
      <div>
        <label>Preço mensal (R$)</label>
        <input type="text" name="price_monthly" id="f-price-monthly" placeholder="397,00">
      </div>
      <div>
        <label>Preço anual (R$)</label>
        <input type="text" name="price_annual" id="f-price-annual" placeholder="3970,00 (2 meses grátis)">
      </div>
      <div>
        <label>Radar — extrações/mês</label>
        <input type="number" name="radar_limit" id="f-radar" min="0">
      </div>
      <div>
        <label>Pipeline — cards</label>
        <input type="number" name="pipeline_limit" id="f-pipeline" min="0">
      </div>
      <div>
        <label>Usuários</label>
        <input type="number" name="users_limit" id="f-users" min="1">
      </div>
      <div>
        <label>Mail Lab self-service (contas)</label>
        <input type="number" name="mail_self_limit" id="f-mail" min="0">
      </div>
      <div>
        <label>Trial (dias)</label>
        <input type="number" name="trial_days" id="f-trial" min="0">
      </div>
      <div>
        <label>Nível de suporte</label>
        <select name="support_level" id="f-support">
          <option value="community">Comunidade</option>
          <option value="email">E-mail (48h)</option>
          <option value="whatsapp">WhatsApp prioritário</option>
          <option value="sla">SLA + gerente</option>
          <option value="dedicated">SLA dedicado</option>
        </select>
      </div>
      <div class="full" style="display:flex;gap:18px;flex-wrap:wrap">
        <div class="checkrow">
          <input type="checkbox" name="active" id="f-active" value="1">
          <label for="f-active">Ativo (aceita novos assinantes)</label>
        </div>
        <div class="checkrow">
          <input type="checkbox" name="visible_public" id="f-public" value="1">
          <label for="f-public">Visível na LP/checkout público</label>
        </div>
        <div class="checkrow">
          <input type="checkbox" name="popular" id="f-popular" value="1">
          <label for="f-popular">⭐ Mais popular (badge no card)</label>
        </div>
      </div>
    </div>
    <div class="hp-actions">
      <button type="button" class="hp-btn hp-btn-cancel" onclick="cancelEdit()">Cancelar</button>
      <button type="submit" class="hp-btn hp-btn-save">💾 Salvar plano</button>
    </div>
  </form>
</div>

<!-- LEAD PACKS -->
<div class="hp-packs">
  <h2>📦 Lead Packs (top-up de extrações Radar)</h2>
  <p>Pacotes avulsos pra assinantes que estouram a cota. Validade 12 meses. Pagamento via Cartão ou PIX no Asaas (one-time charge).</p>
  <div class="hp-packs-grid">
    <?php foreach ($packs as $pk): ?>
    <div class="hp-pack">
      <div class="pl-leads">Pack <?= number_format($pk['leads'], 0, ',', '.') ?></div>
      <div class="pl-num"><?= number_format($pk['leads'], 0, ',', '.') ?></div>
      <div class="pl-price"><?= brl_cents_fmt($pk['price_cents']) ?></div>
      <div class="pl-per">R$ <?= number_format($pk['per_lead'], 2, ',', '.') ?> / lead</div>
    </div>
    <?php endforeach; ?>
  </div>
  <p style="margin-top:14px;font-size:.78rem;color:#6b7280">
    💡 <strong>10.000+ leads</strong> entra no fluxo "Consulte-nos" (SCALE/Custom). Lead packs serão configurados na integração de pagamento do Asaas (chunk 2).
  </p>
</div>

<script>
function editTier(p) {
  document.getElementById('edit-panel').classList.add('open');
  document.getElementById('edit-title').textContent = 'Editando: ' + p.name;
  document.getElementById('f-plan-id').value      = p.id;
  document.getElementById('f-name').value         = p.name;
  document.getElementById('f-price-monthly').value= (p.price_cents / 100).toFixed(2).replace('.', ',');
  document.getElementById('f-price-annual').value = (p.annual_price_cents / 100).toFixed(2).replace('.', ',');
  document.getElementById('f-radar').value        = p.limit_cnpj_monthly;
  document.getElementById('f-pipeline').value     = p.limit_contacts;
  document.getElementById('f-users').value        = p.users_limit;
  document.getElementById('f-mail').value         = p.mail_self_limit;
  document.getElementById('f-trial').value        = p.trial_days || 0;
  document.getElementById('f-support').value      = p.support_level || 'email';
  document.getElementById('f-active').checked     = p.active == 1;
  document.getElementById('f-public').checked     = p.visible_public == 1;
  document.getElementById('f-popular').checked    = p.popular == 1;
  document.getElementById('edit-panel').scrollIntoView({behavior:'smooth', block:'start'});
}
function cancelEdit() {
  document.getElementById('edit-panel').classList.remove('open');
}
</script>
<?php
});
