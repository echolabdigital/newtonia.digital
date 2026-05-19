<?php
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../core/crm.php';

$tid   = (int) $tenant['id'];
$plan  = $tenant['plan_id'] ? db_one('SELECT * FROM plans WHERE id = ?', [$tenant['plan_id']]) : null;
$brand = tenant_brand();
$zapi  = tenant_zapi();

// ── Radar (CNPJ) stats ─────────────────────────────────────────────────────
$cnpj_limit  = cnpj_monthly_limit($tid);
$cnpj_used   = cnpj_monthly_used($tid);
$cnpj_pct    = cnpj_usage_pct($cnpj_used, $cnpj_limit);
$cnpj_addon  = (int) db_val('SELECT cnpj_addon_credits FROM tenants WHERE id = ?', [$tid]);
$cnpj_remain = max(0, $cnpj_limit - $cnpj_used);
$cnpj_bar_cls = $cnpj_pct >= 90 ? 'danger' : ($cnpj_pct >= 50 ? 'warn' : 'ok');

// ── Pipeline stats ─────────────────────────────────────────────────────────
$pipeline_cols = crm_ensure_columns($tid);
$pipeline_by_col = [];
foreach ($pipeline_cols as $c) {
    $count = (int) db_val('SELECT COUNT(*) FROM crm_cards WHERE tenant_id=? AND column_id=?', [$tid, (int)$c['id']]);
    $pipeline_by_col[] = ['name' => $c['name'], 'color' => $c['color'], 'count' => $count, 'id' => (int)$c['id']];
}
$pipeline_total = array_sum(array_column($pipeline_by_col, 'count'));
$pipeline_hot   = (int) db_val('SELECT COUNT(*) FROM crm_cards WHERE tenant_id=? AND score>=70', [$tid]);
$cards_today    = (int) db_val('SELECT COUNT(*) FROM crm_cards WHERE tenant_id=? AND DATE(created_at)=CURDATE()', [$tid]);

// ── Hot leads (top 5 maior score no Pipeline) ──────────────────────────────
$hot_leads = db_all(
    'SELECT c.id, c.nome_fantasia, c.razao_social, c.score, c.telefone, c.cidade_uf,
            c.cnpj, col.name AS col_name, col.color AS col_color, u.name AS assigned_name
     FROM crm_cards c
     LEFT JOIN crm_columns col ON col.id = c.column_id
     LEFT JOIN users u ON u.id = c.assigned_user_id
     WHERE c.tenant_id = ? AND c.score >= 50
     ORDER BY c.score DESC, c.last_action DESC LIMIT 5',
    [$tid]
);

// ── Feed de atividade (últimos 7 dias do Pipeline) ─────────────────────────
$activity = db_all(
    'SELECT h.action, h.detail, h.created_at,
            c.id AS card_id, c.nome_fantasia, c.razao_social,
            u.name AS user_name
     FROM crm_card_history h
     JOIN crm_cards c ON c.id = h.card_id
     LEFT JOIN users u ON u.id = h.user_id
     WHERE c.tenant_id = ? AND h.created_at > NOW() - INTERVAL 7 DAY
     ORDER BY h.created_at DESC LIMIT 8',
    [$tid]
);

// Listas + downloads recentes
$recent_lists = db_all(
    'SELECT id, name, created_at FROM cnpj_lists WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 5',
    [$tid]
);
$recent_downloads = db_all(
    'SELECT records_count, downloaded_at, filters_json FROM cnpj_download_log WHERE tenant_id = ? ORDER BY downloaded_at DESC LIMIT 5',
    [$tid]
);

// ── Smart CTA baseado no estado atual ──────────────────────────────────────
if ($pipeline_hot > 0) {
    $cta_text  = "Você tem $pipeline_hot lead" . ($pipeline_hot > 1 ? 's' : '') . " quente" . ($pipeline_hot > 1 ? 's' : '') . " esperando contato";
    $cta_href  = 'crm.php';
    $cta_label = 'Abrir Pipeline →';
} elseif ($pipeline_total > 0) {
    $cta_text  = "$pipeline_total lead" . ($pipeline_total > 1 ? 's' : '') . " no funil — continue de onde parou";
    $cta_href  = 'crm.php';
    $cta_label = 'Abrir Pipeline →';
} elseif ($cnpj_used > 0) {
    $cta_text  = 'Comece a organizar seus leads no Pipeline';
    $cta_href  = 'cnpj.php';
    $cta_label = 'Voltar à busca →';
} else {
    $cta_text  = 'Pronto pra encontrar seus primeiros leads?';
    $cta_href  = 'cnpj.php';
    $cta_label = 'Começar prospecção →';
}

// HERMES — 6 módulos do pilar (ordem definida pelo usuário)
$modules = [
    ['k'=>'pipeline',  'title'=>'Pipeline',    'sub'=>'Gestão comercial',          'href'=>'crm.php',  'available'=>true,  'color'=>'#059669'],
    ['k'=>'maillab',   'title'=>'Mail Lab',    'sub'=>'Self-service ou managed',   'href'=>'maillab.php', 'available'=>true,  'color'=>'#0d9488'],
    ['k'=>'radar',     'title'=>'Radar Leads', 'sub'=>'Prospecção',                'href'=>'cnpj.php', 'available'=>true,  'color'=>'#10b981'],
    ['k'=>'signal',    'title'=>'Signal',      'sub'=>'Disparador WhatsApp',       'href'=>'#',        'available'=>false, 'color'=>'#14b8a6'],
    ['k'=>'whatslab',  'title'=>'Whats Lab',   'sub'=>'Conversas · via NEWTON IA', 'href'=>'#',        'available'=>false, 'color'=>'#06b6d4'],
    ['k'=>'pitch',     'title'=>'Pitch',       'sub'=>'Scripts SPIN',              'href'=>'#',        'available'=>false, 'color'=>'#34d399'],
];

// Helper de tier de score
function dash_score_tier(int $s): string {
    if ($s >= 70) return 'hot';
    if ($s >= 50) return 'warm';
    if ($s >= 25) return 'cool';
    return 'cold';
}

// Helper de tempo relativo
function dash_time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)       return 'agora há pouco';
    if ($diff < 3600)     return floor($diff/60) . ' min atrás';
    if ($diff < 86400)    return floor($diff/3600) . 'h atrás';
    if ($diff < 86400*7)  return floor($diff/86400) . 'd atrás';
    return date('d/m', strtotime($datetime));
}

// Helper de label de ação
function dash_action_label(string $a): string {
    return [
        'created' => 'criou',
        'moved'   => 'moveu',
        'updated' => 'editou',
        'note'    => 'adicionou nota',
        'comment' => 'comentou',
    ][$a] ?? $a;
}

app_layout('Visão Geral', 'overview', function() use (
    $tenant, $plan, $zapi, $modules, $brand,
    $cnpj_limit, $cnpj_used, $cnpj_pct, $cnpj_addon, $cnpj_remain, $cnpj_bar_cls,
    $pipeline_by_col, $pipeline_total, $pipeline_hot, $cards_today,
    $hot_leads, $activity, $recent_lists, $recent_downloads,
    $cta_text, $cta_href, $cta_label
) {
?>
<style>
  /* === Hero refinado (não mais gradient gritante) === */
  .dash-hero { background:#fff; border-radius:14px; padding:20px 24px; margin-bottom:16px; box-shadow:0 1px 4px rgba(0,0,0,.05); border:1px solid var(--line); display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
  .dash-hero .h-greet { display:flex; flex-direction:column; gap:3px; min-width:0; flex:1; }
  .dash-hero h1 { font-size:1.25rem; font-weight:700; margin:0; color:var(--ink); }
  .dash-hero h1 .wave { display:inline-block; animation:wave 1.4s ease-in-out infinite; transform-origin: 70% 70%; }
  @keyframes wave { 0%, 60%, 100% { transform: rotate(0); } 10%, 30% { transform: rotate(14deg); } 20% { transform: rotate(-8deg); } 40% { transform: rotate(-4deg); } 50% { transform: rotate(10deg); } }
  .dash-hero .h-cta-text { font-size:.88rem; color:var(--mute); margin:0; }
  .dash-hero .h-cta-text strong { color:var(--ink); font-weight:600; }
  .dash-hero .h-cta { background:var(--cr); color:#fff; border:none; padding:10px 18px; border-radius:8px; font-size:.85rem; font-weight:500; text-decoration:none; display:inline-flex; align-items:center; gap:6px; cursor:pointer; transition:all .15s; font-family:inherit; white-space:nowrap; }
  .dash-hero .h-cta:hover { background:#0ea371; }

  /* === KPI cards === */
  .kpi-row { display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; margin-bottom:16px; }
  .kpi { background:#fff; border-radius:12px; padding:14px 16px; box-shadow:0 1px 4px rgba(0,0,0,.05); border:1px solid var(--line); position:relative; overflow:hidden; }
  .kpi .lbl { font-family:'Geist Mono',monospace; font-size:.62rem; text-transform:uppercase; letter-spacing:.08em; color:var(--mute); font-weight:600; }
  .kpi .val { font-size:1.7rem; font-weight:700; color:var(--ink); margin-top:6px; line-height:1; letter-spacing:-0.02em; }
  .kpi .val.small { font-size:1.05rem; padding-top:8px; }
  .kpi .sub { font-size:.74rem; color:var(--mute); margin-top:6px; }
  .kpi .accent-bar { position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--kpi-color, var(--pipeline)); border-radius:99px 0 0 99px; }
  .kpi.kpi-pipe   { --kpi-color: var(--hermes); }
  .kpi.kpi-hot    { --kpi-color: #b91c1c; }
  .kpi.kpi-today  { --kpi-color: #14b8a6; }
  .kpi.kpi-quota  { --kpi-color: #f59e0b; }
  .kpi.kpi-quota.ok    { --kpi-color: #22c55e; }
  .kpi.kpi-quota.warn  { --kpi-color: #f59e0b; }
  .kpi.kpi-quota.danger{ --kpi-color: var(--coral); }

  /* === Pipeline funnel visualization === */
  .pipe-viz { background:#fff; border-radius:12px; padding:16px 18px; box-shadow:0 1px 4px rgba(0,0,0,.05); border:1px solid var(--line); margin-bottom:16px; }
  .pipe-viz .pv-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
  .pipe-viz .pv-head h3 { margin:0; font-size:.95rem; font-weight:600; display:flex; align-items:center; gap:6px; }
  .pipe-viz .pv-head h3 .badge { font-family:'Geist Mono',monospace; font-size:.56rem; font-weight:600; background:var(--hermes); color:#fff; padding:2px 7px; border-radius:4px; letter-spacing:.08em; text-transform:uppercase; }
  .pipe-viz .pv-head a { font-size:.82rem; color:var(--cr); text-decoration:none; font-weight:500; }
  .pipe-viz .pv-head a:hover { text-decoration:underline; }
  .pipe-funnel { display:flex; height:38px; border-radius:8px; overflow:hidden; background:var(--bone); gap:2px; }
  .pipe-seg { display:flex; flex-direction:column; align-items:center; justify-content:center; min-width:80px; color:#fff; font-family:'Geist Mono',monospace; font-size:.7rem; font-weight:600; text-decoration:none; transition:all .15s; padding:4px; cursor:pointer; }
  .pipe-seg:hover { filter:brightness(1.1); }
  .pipe-seg .pv-count { font-size:.92rem; font-weight:700; line-height:1; }
  .pipe-seg .pv-name { font-size:.58rem; opacity:.85; margin-top:1px; text-transform:uppercase; letter-spacing:.04em; }
  .pipe-empty { padding:18px; text-align:center; color:var(--mute); font-size:.85rem; }

  /* === Two-col layout (Hot leads + Activity) === */
  .two-col-main { display:grid; grid-template-columns:1.1fr 1fr; gap:14px; margin-bottom:16px; }
  .panel { background:#fff; border-radius:12px; padding:16px 18px; box-shadow:0 1px 4px rgba(0,0,0,.05); border:1px solid var(--line); }
  .panel .p-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; padding-bottom:10px; border-bottom:1px solid var(--line); }
  .panel .p-head h3 { margin:0; font-size:.92rem; font-weight:600; }
  .panel .p-head a { font-size:.78rem; color:var(--cr); text-decoration:none; font-weight:500; }
  .panel .p-head a:hover { text-decoration:underline; }

  /* Hot leads list */
  .hot-list { display:flex; flex-direction:column; gap:8px; }
  .hot-item { display:flex; align-items:center; gap:10px; padding:8px 10px; background:var(--bone); border-radius:8px; transition:all .15s; cursor:pointer; text-decoration:none; color:var(--ink); }
  .hot-item:hover { background:var(--pipeline-soft, rgba(5,150,105,.08)); transform:translateX(2px); }
  .hot-score { flex-shrink:0; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-family:'Geist Mono',monospace; font-weight:700; font-size:.82rem; }
  .hot-score.hot  { background:#fee2e2; color:#b91c1c; }
  .hot-score.warm { background:#fef3c7; color:#92400e; }
  .hot-info { flex:1; min-width:0; }
  .hot-name { font-weight:600; font-size:.84rem; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .hot-meta { font-size:.72rem; color:var(--mute); margin-top:2px; display:flex; align-items:center; gap:8px; }
  .hot-meta .col-tag { display:inline-flex; align-items:center; gap:4px; }
  .hot-meta .col-tag .dot { width:6px; height:6px; border-radius:50%; }
  .hot-empty { padding:30px 14px; text-align:center; color:var(--mute); font-size:.85rem; }
  .hot-empty .empty-cta { display:inline-block; margin-top:8px; color:var(--cr); font-weight:500; text-decoration:none; }

  /* Activity feed */
  .activity-feed { display:flex; flex-direction:column; gap:0; }
  .act-item { display:flex; gap:10px; padding:10px 0; border-bottom:1px solid var(--line); }
  .act-item:last-child { border-bottom:none; }
  .act-dot { width:7px; height:7px; border-radius:50%; background:var(--pipeline); margin-top:7px; flex-shrink:0; }
  .act-content { flex:1; min-width:0; font-size:.82rem; color:var(--ink-2); line-height:1.4; }
  .act-content strong { color:var(--ink); font-weight:600; }
  .act-content .who { color:var(--pipeline); font-weight:600; }
  .act-time { font-family:'Geist Mono',monospace; font-size:.66rem; color:var(--mute); margin-top:2px; }

  /* === Radar quick prospect (slim row) === */
  .radar-row { background:#fff; border-radius:12px; padding:14px 18px; box-shadow:0 1px 4px rgba(0,0,0,.05); border:1px solid var(--line); margin-bottom:16px; }
  .radar-row .rr-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
  .radar-row .rr-head h3 { margin:0; font-size:.88rem; font-weight:600; display:flex; align-items:center; gap:6px; }
  .radar-row .rr-head h3 .icon-dot { width:8px; height:8px; border-radius:50%; background:#10b981; }
  .radar-quick { display:flex; gap:8px; flex-wrap:wrap; }
  .radar-quick a { display:inline-flex; align-items:center; gap:6px; padding:9px 14px; background:var(--bone); border-radius:8px; text-decoration:none; color:var(--ink-2); font-size:.82rem; font-weight:500; transition:all .15s; border:1px solid transparent; }
  .radar-quick a:hover { background:#10b98115; color:#059669; border-color:#10b98140; }
  .radar-quick a .emoji { font-size:1rem; }

  /* === Side panels (listas + downloads) === */
  .side-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }
  .side-row .list-item { display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--line); font-size:.83rem; }
  .side-row .list-item:last-child { border-bottom:none; }
  .side-row .list-item a { color:var(--ink); text-decoration:none; font-weight:500; }
  .side-row .list-item a:hover { color:var(--cr); }
  .side-row .empty-mini { padding:14px 0; color:var(--mute); font-size:.82rem; text-align:center; }
  .side-row .empty-mini a { color:var(--cr); }

  /* === HERMES modules row (slim) === */
  .mod-row-title { font-family:'Geist Mono',monospace; font-size:.66rem; text-transform:uppercase; letter-spacing:.1em; color:var(--mute); font-weight:600; margin:24px 0 10px; }
  .mod-row-title::before { content:'// '; opacity:.5; }
  .mod-row { display:grid; grid-template-columns:repeat(6, 1fr); gap:10px; }
  .mod-tile { background:#fff; border-radius:10px; padding:14px; border:1px solid var(--line); text-decoration:none; color:var(--ink); transition:all .15s; position:relative; overflow:hidden; }
  .mod-tile::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--mod-color); }
  .mod-tile:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(0,0,0,.06); border-color:var(--mod-color); }
  .mod-tile.disabled { opacity:.55; cursor:not-allowed; }
  .mod-tile.disabled:hover { transform:none; box-shadow:0 1px 4px rgba(0,0,0,.05); border-color:var(--line); }
  .mod-title { font-weight:600; font-size:.92rem; }
  .mod-sub { font-family:'Geist Mono',monospace; font-size:.66rem; color:var(--mute); margin-top:3px; }
  .mod-status { font-family:'Geist Mono',monospace; font-size:.56rem; padding:2px 6px; border-radius:4px; letter-spacing:.06em; text-transform:uppercase; font-weight:600; margin-top:8px; display:inline-block; }
  .mod-status.on  { background:#dcfce7; color:#166534; }
  .mod-status.off { background:#f3f4f6; color:var(--mute); }

  /* Footer */
  .dash-foot { text-align:center; color:var(--mute); font-size:.74rem; margin-top:30px; padding-top:18px; border-top:1px solid var(--line); }
  .dash-foot a { color:var(--cr); text-decoration:none; }
  .dash-foot a:hover { text-decoration:underline; }

  @media (max-width: 1200px) {
    .mod-row { grid-template-columns:repeat(3, 1fr); }
  }
  @media (max-width: 980px) {
    .kpi-row { grid-template-columns:repeat(2, 1fr); }
    .mod-row { grid-template-columns:repeat(3, 1fr); }
    .two-col-main, .side-row { grid-template-columns:1fr; }
  }
  @media (max-width: 540px) {
    .kpi-row, .mod-row { grid-template-columns:1fr 1fr; }
    .dash-hero { flex-direction:column; align-items:flex-start; }
  }
</style>

<?= flash_render() ?>

<?php if (!empty($_GET['welcome'])): ?>
<div style="background:linear-gradient(135deg, rgba(16,185,129,.12), rgba(16,185,129,.04));border:1px solid rgba(16,185,129,.3);border-radius:12px;padding:16px 20px;margin-bottom:16px;display:flex;align-items:center;gap:14px">
  <div style="font-size:1.6rem">🎉</div>
  <div style="flex:1">
    <div style="font-weight:700;color:var(--ink);font-size:.95rem">Bem-vindo ao HERMES.b2b!</div>
    <div style="font-size:.84rem;color:var(--mute);margin-top:2px">Seu trial de 3 dias começou. Configure seu primeiro filtro no <strong>Radar Leads</strong> pra encontrar empresas qualificadas.</div>
  </div>
  <a href="cnpj.php" style="background:var(--hermes);color:#fff;padding:9px 16px;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:600">🎯 Começar →</a>
</div>
<?php endif; ?>

<?php if (!empty($_GET['signup']) && $_GET['signup'] === 'ok'): ?>
<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:12px;padding:14px 18px;margin-bottom:16px;font-size:.88rem;color:#92400e">
  ⏳ <strong>Conta criada!</strong> Sua cobrança está sendo gerada no Asaas. Confira <a href="billing.php" style="color:#92400e;font-weight:600">Plano e cobranças</a> pra ver o link de pagamento.
</div>
<?php endif; ?>

<!-- ── Hero refinado com CTA contextual ── -->
<div class="dash-hero">
  <div class="h-greet">
    <h1>Olá<?= !empty(auth_user_name()) ? ', ' . e(explode(' ', auth_user_name())[0]) : '' ?> <span class="wave">👋</span></h1>
    <p class="h-cta-text"><?= e($cta_text) ?></p>
  </div>
  <a href="<?= e($cta_href) ?>" class="h-cta"><?= e($cta_label) ?></a>
</div>

<!-- ── KPIs ── -->
<div class="kpi-row">
  <div class="kpi kpi-pipe">
    <div class="accent-bar"></div>
    <div class="lbl">No funil</div>
    <div class="val"><?= number_format($pipeline_total, 0, ',', '.') ?></div>
    <div class="sub">leads no Pipeline</div>
  </div>
  <div class="kpi kpi-hot">
    <div class="accent-bar"></div>
    <div class="lbl">Hot leads 🔥</div>
    <div class="val"><?= number_format($pipeline_hot, 0, ',', '.') ?></div>
    <div class="sub">score ≥ 70</div>
  </div>
  <div class="kpi kpi-today">
    <div class="accent-bar"></div>
    <div class="lbl">Hoje</div>
    <div class="val"><?= number_format($cards_today, 0, ',', '.') ?></div>
    <div class="sub"><?= $cards_today === 0 ? 'nenhum lead novo' : 'adicionado' . ($cards_today > 1 ? 's' : '') . ' no funil' ?></div>
  </div>
  <div class="kpi kpi-quota <?= $cnpj_bar_cls ?>">
    <div class="accent-bar"></div>
    <div class="lbl">Cota Radar</div>
    <div class="val"><?= number_format($cnpj_used, 0, ',', '.') ?><span style="font-size:.7em;color:var(--mute);font-weight:500"> / <?= number_format($cnpj_limit, 0, ',', '.') ?></span></div>
    <div class="sub">restam <strong><?= number_format($cnpj_remain, 0, ',', '.') ?></strong> · <?= max(0, 100 - $cnpj_pct) ?>% livre</div>
  </div>
</div>

<!-- ── Pipeline funnel visualization ── -->
<div class="pipe-viz">
  <div class="pv-head">
    <h3>📋 Distribuição do funil <span class="badge">PIPELINE</span></h3>
    <a href="crm.php">Abrir Pipeline →</a>
  </div>
  <?php if ($pipeline_total > 0): ?>
    <div class="pipe-funnel">
      <?php foreach ($pipeline_by_col as $seg): ?>
        <?php $pct = $pipeline_total > 0 ? round(($seg['count'] / $pipeline_total) * 100, 1) : 0; ?>
        <a class="pipe-seg" href="crm.php" style="background:<?= e($seg['color']) ?>;flex:<?= max(1, $seg['count']) ?>" title="<?= e($seg['name']) ?>: <?= $seg['count'] ?> (<?= $pct ?>%)">
          <span class="pv-count"><?= $seg['count'] ?></span>
          <span class="pv-name"><?= e(mb_strimwidth($seg['name'], 0, 12, '…')) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="pipe-empty">
      Nenhum lead no funil ainda.
      <a href="cnpj.php" style="color:var(--cr);text-decoration:none;font-weight:500"> Comece pelo Radar →</a>
    </div>
  <?php endif; ?>
</div>

<!-- ── Hot leads + Activity (2 colunas) ── -->
<div class="two-col-main">
  <div class="panel">
    <div class="p-head">
      <h3>🔥 Hot leads esperando contato</h3>
      <a href="crm.php">Ver todos →</a>
    </div>
    <?php if ($hot_leads): ?>
      <div class="hot-list">
        <?php foreach ($hot_leads as $h):
          $tier = dash_score_tier((int)$h['score']);
          $name = $h['nome_fantasia'] ?: $h['razao_social'] ?: '—';
        ?>
        <a class="hot-item" href="crm.php">
          <div class="hot-score <?= $tier ?>"><?= (int)$h['score'] ?></div>
          <div class="hot-info">
            <div class="hot-name"><?= e($name) ?></div>
            <div class="hot-meta">
              <?php if ($h['col_name']): ?>
                <span class="col-tag"><span class="dot" style="background:<?= e($h['col_color']) ?>"></span><?= e($h['col_name']) ?></span>
              <?php endif; ?>
              <?php if ($h['cidade_uf']): ?>· <?= e($h['cidade_uf']) ?><?php endif; ?>
              <?php if ($h['assigned_name']): ?>· <?= e(explode(' ', $h['assigned_name'])[0]) ?><?php endif; ?>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="hot-empty">
        Nenhum lead com score alto ainda.
        <br><a class="empty-cta" href="cnpj.php">Buscar empresas no Radar →</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="p-head">
      <h3>⏱ Atividade recente</h3>
      <span style="font-family:'Geist Mono',monospace;font-size:.66rem;color:var(--mute)">últimos 7 dias</span>
    </div>
    <?php if ($activity): ?>
      <div class="activity-feed">
        <?php foreach ($activity as $a):
          $name = $a['nome_fantasia'] ?: $a['razao_social'] ?: 'um lead';
          $who  = $a['user_name'] ?: 'Sistema';
          $verb = dash_action_label($a['action']);
        ?>
        <div class="act-item">
          <div class="act-dot"></div>
          <div class="act-content">
            <span class="who"><?= e(explode(' ', $who)[0]) ?></span> <?= e($verb) ?>
            <strong><?= e(mb_strimwidth($name, 0, 30, '…')) ?></strong>
            <?php if (!empty($a['detail']) && $a['action'] !== 'moved'): ?>
              <div style="color:var(--mute);font-size:.76rem;margin-top:2px"><?= e(mb_strimwidth($a['detail'], 0, 80, '…')) ?></div>
            <?php endif; ?>
            <div class="act-time"><?= dash_time_ago($a['created_at']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="hot-empty">
        Sem atividade nos últimos 7 dias.
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Radar quick actions ── -->
<div class="radar-row">
  <div class="rr-head">
    <h3><span class="icon-dot"></span>Radar — prospecção rápida</h3>
    <a href="cnpj.php" style="font-size:.82rem;color:var(--cr);text-decoration:none;font-weight:500">Busca avançada →</a>
  </div>
  <div class="radar-quick">
    <a href="cnpj.php"><span class="emoji">🔍</span> Buscar empresas</a>
    <a href="cnpj.php?situacao=02&tem_email=1&sem_mei=1"><span class="emoji">📧</span> Ativas com e-mail</a>
    <a href="cnpj.php?situacao=02&tem_tel=1&sem_mei=1"><span class="emoji">📞</span> Com telefone</a>
    <a href="cnpj.php?situacao=02&sort=recentes"><span class="emoji">🆕</span> Recém-abertas</a>
    <a href="cnpj.php?situacao=02&mei=1"><span class="emoji">🚀</span> MEIs ativos</a>
    <a href="cnpj-lists.php"><span class="emoji">📋</span> Minhas listas</a>
  </div>
</div>

<!-- ── Listas + downloads (slim row) ── -->
<div class="side-row">
  <div class="panel">
    <div class="p-head"><h3>📋 Listas salvas</h3><a href="cnpj-lists.php">Ver todas →</a></div>
    <?php if ($recent_lists): ?>
      <?php foreach ($recent_lists as $l): ?>
        <div class="list-item">
          <a href="cnpj-lists.php?view=<?= $l['id'] ?>"><?= e($l['name']) ?></a>
          <small style="color:var(--mute);font-family:'Geist Mono',monospace"><?= date('d/m', strtotime($l['created_at'])) ?></small>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty-mini">Nenhuma lista salva ainda. <a href="cnpj.php">Fazer busca →</a></div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="p-head">
      <h3>⬇ Exportações recentes</h3>
      <a href="cnpj-lists.php" style="font-size:.75rem;color:var(--cr);text-decoration:none;font-weight:600">Ver todas →</a>
    </div>
    <?php if ($recent_downloads): ?>
      <?php foreach ($recent_downloads as $d):
        // Descobre destino: _view = CNPJ individual → Leads Vistos
        //                   filtros de busca → reconstrói URL do Radar
        $fj   = json_decode($d['filters_json'] ?? '{}', true) ?: [];
        $isView = isset($fj['_view']);
        if ($isView) {
            $dest_href  = 'leads-vistos.php';
            $dest_label = 'Ver em Leads Vistos →';
        } else {
            // Remove chaves internas (_src, _view, etc.) e reconstrói URL de busca
            $params = array_filter($fj, fn($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY);
            $dest_href  = 'cnpj.php' . ($params ? '?' . http_build_query($params) : '');
            $dest_label = 'Repetir busca →';
        }
      ?>
        <div class="list-item">
          <a href="<?= e($dest_href) ?>" style="color:var(--ink);text-decoration:none;font-weight:500">
            <strong><?= number_format($d['records_count'], 0, ',', '.') ?></strong> leads extraídos
          </a>
          <small style="color:var(--mute);font-family:'Geist Mono',monospace"><?= date('d/m H:i', strtotime($d['downloaded_at'])) ?></small>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty-mini">Nenhuma exportação ainda. <a href="cnpj.php" style="color:var(--cr)">Ir ao Radar →</a></div>
    <?php endif; ?>
  </div>
</div>

<!-- ── HERMES Modules (slim row) ── -->
<div class="mod-row-title">HERMES · módulos do stack</div>
<div class="mod-row">
  <?php foreach ($modules as $m): ?>
    <?php
      $avail = !empty($m['available']);
      $href  = $avail ? $m['href'] : '#';
      $tag   = '<a' . ($avail ? '' : ' onclick="event.preventDefault();return false"') . ' href="' . e($href) . '"';
    ?>
    <a class="mod-tile <?= $avail?'':'disabled' ?>" href="<?= e($href) ?>"
       style="--mod-color: <?= e($m['color']) ?>"
       <?= $avail ? '' : 'onclick="event.preventDefault();return false"' ?>>
      <div class="mod-title"><?= e($m['title']) ?></div>
      <div class="mod-sub"><?= e($m['sub']) ?></div>
      <span class="mod-status <?= $avail?'on':'off' ?>"><?= $avail?'● Ativo':'○ Em breve' ?></span>
    </a>
  <?php endforeach; ?>
</div>

<!-- ── Footer ── -->
<div class="dash-foot">
  <strong>HERMES<span style="color:var(--hermes);font-family:'Geist Mono',monospace">.b2b</span></strong> · Comercial e prospecção
  · <span style="color:var(--mute)">Seu Negócio Ecoa. Seus Contratos Fecham.</span>
  · <a href="https://www.hermesb2b.co" target="_blank">hermesb2b.co</a>
</div>
<?php
});
