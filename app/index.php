<?php
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/_layout.php';

$tid   = (int) $tenant['id'];
$brand = tenant_brand();

// ── Métricas SYNAPSE ──────────────────────────────────────────────────────────
$agents_total  = (int) db_val('SELECT COUNT(*) FROM agents WHERE tenant_id = ?', [$tid]);
$agents_active = (int) db_val('SELECT COUNT(*) FROM agents WHERE tenant_id = ? AND status = "active"', [$tid]);

$conv_open  = (int) db_val('SELECT COUNT(*) FROM conversations WHERE tenant_id = ? AND status = "open"', [$tid]);
$conv_today = (int) db_val('SELECT COUNT(*) FROM conversations WHERE tenant_id = ? AND DATE(started_at) = CURDATE()', [$tid]);
$conv_total = (int) db_val('SELECT COUNT(*) FROM conversations WHERE tenant_id = ?', [$tid]);
$conv_paused = (int) db_val('SELECT COUNT(*) FROM conversations WHERE tenant_id = ? AND status = "paused"', [$tid]);

$msgs_today = (int) db_val(
    'SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.tenant_id = ? AND DATE(m.sent_at) = CURDATE()',
    [$tid]
);
$msgs_month = (int) db_val(
    'SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.tenant_id = ? AND MONTH(m.sent_at) = MONTH(CURDATE()) AND YEAR(m.sent_at) = YEAR(CURDATE())',
    [$tid]
);

// Plano e limite mensal de msgs
$plan = $tenant['plan_id'] ? db_one('SELECT * FROM plans WHERE id = ?', [$tenant['plan_id']]) : null;
$msg_limit = $plan ? (int)($plan['limit_contacts'] ?? 0) : 0;
$msg_pct   = $msg_limit > 0 ? min(100, round($msgs_month / $msg_limit * 100)) : 0;
$msg_bar   = $msg_pct >= 90 ? 'danger' : ($msg_pct >= 60 ? 'warn' : 'ok');

// Conversas recentes
$recent_convs = db_all(
    'SELECT c.*, a.name AS agent_name,
            (SELECT content FROM messages WHERE conversation_id = c.id ORDER BY sent_at DESC LIMIT 1) AS last_msg
     FROM conversations c
     LEFT JOIN agents a ON a.id = c.agent_id
     WHERE c.tenant_id = ?
     ORDER BY COALESCE(c.last_message_at, c.started_at) DESC
     LIMIT 8',
    [$tid]
);

// Agentes com stats
$agents_stats = db_all(
    'SELECT a.*,
            (SELECT COUNT(*) FROM conversations WHERE agent_id = a.id AND status = "open") AS conv_open,
            (SELECT COUNT(*) FROM conversations WHERE agent_id = a.id AND DATE(started_at) = CURDATE()) AS conv_today
     FROM agents a WHERE a.tenant_id = ? ORDER BY conv_open DESC, a.created_at DESC LIMIT 5',
    [$tid]
);

// Helper
function ago(string $dt): string {
    $d = time() - strtotime($dt);
    if ($d < 60)      return 'agora';
    if ($d < 3600)    return floor($d/60) . 'min';
    if ($d < 86400)   return floor($d/3600) . 'h';
    if ($d < 86400*7) return floor($d/86400) . 'd';
    return date('d/m', strtotime($dt));
}

app_layout('Dashboard · Newton IA', 'overview', function() use (
    $tenant, $brand, $plan,
    $agents_total, $agents_active,
    $conv_open, $conv_today, $conv_total, $conv_paused,
    $msgs_today, $msgs_month, $msg_limit, $msg_pct, $msg_bar,
    $recent_convs, $agents_stats
) {
?>
<style>
.dash { max-width: 960px; margin: 0 auto; padding: 2rem 1.5rem; }
.dash-header { margin-bottom: 1.75rem; }
.dash-header h1 { margin: 0 0 .25rem; font-size: 1.5rem; font-weight: 600; color: #18181b; }
.dash-header p  { margin: 0; font-size: .875rem; color: #8b8a93; }
.kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 1.25rem; }
.kpi { background: #fff; border: 1px solid #e7e5e0; border-radius: 12px; padding: 1rem 1.1rem; position: relative; overflow: hidden; }
.kpi::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--c); border-radius: 3px 0 0 3px; }
.kpi .lbl { font-family: 'Geist Mono', monospace; font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: #8b8a93; }
.kpi .val { font-size: 1.8rem; font-weight: 700; color: #18181b; line-height: 1; margin: .4rem 0 .3rem; letter-spacing: -.03em; }
.kpi .sub { font-size: .76rem; color: #8b8a93; }
.two-col { display: grid; grid-template-columns: 1.4fr 1fr; gap: 14px; margin-bottom: 1.25rem; }
.panel { background: #fff; border: 1px solid #e7e5e0; border-radius: 12px; overflow: hidden; }
.panel-head { padding: .9rem 1.1rem; border-bottom: 1px solid #e7e5e0; display: flex; align-items: center; justify-content: space-between; }
.panel-head h3 { margin: 0; font-size: .9rem; font-weight: 600; color: #18181b; }
.panel-head a  { font-size: .78rem; color: #0ea5e9; text-decoration: none; font-weight: 500; }
.panel-head a:hover { text-decoration: underline; }
.panel-body { padding: 0; }
.conv-row { display: flex; align-items: center; gap: .75rem; padding: .8rem 1.1rem; border-bottom: 1px solid #f5f3ef; transition: background .12s; text-decoration: none; color: inherit; }
.conv-row:last-child { border-bottom: none; }
.conv-row:hover { background: #f8f7f4; }
.conv-avatar { width: 36px; height: 36px; border-radius: 9px; background: #f0f9ff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: .85rem; color: #0ea5e9; flex-shrink: 0; }
.conv-info { flex: 1; min-width: 0; }
.conv-name { font-weight: 600; font-size: .84rem; color: #18181b; }
.conv-prev { font-size: .76rem; color: #8b8a93; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 240px; margin-top: 1px; }
.conv-meta { text-align: right; flex-shrink: 0; }
.conv-time { font-family: 'Geist Mono', monospace; font-size: .67rem; color: #b0adb8; }
.conv-badge { font-size: .65rem; font-weight: 600; padding: 2px 7px; border-radius: 99px; display: inline-block; margin-top: 3px; }
.badge-open   { background: #f0fdf4; color: #16a34a; }
.badge-paused { background: #fefce8; color: #ca8a04; }
.badge-closed { background: #f8fafc; color: #64748b; }
.agent-row { display: flex; align-items: center; gap: .75rem; padding: .75rem 1.1rem; border-bottom: 1px solid #f5f3ef; text-decoration: none; color: inherit; transition: background .12s; }
.agent-row:last-child { border-bottom: none; }
.agent-row:hover { background: #f8f7f4; }
.agent-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.agent-dot.on  { background: #22c55e; box-shadow: 0 0 6px #22c55e66; }
.agent-dot.off { background: #d1d5db; }
.agent-name { font-weight: 600; font-size: .84rem; color: #18181b; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.agent-stat { font-family: 'Geist Mono', monospace; font-size: .7rem; color: #8b8a93; }
.bar-wrap { height: 6px; background: #f0eee8; border-radius: 99px; overflow: hidden; margin-top: .4rem; }
.bar-fill { height: 100%; border-radius: 99px; transition: width .3s; }
.bar-ok     { background: #22c55e; }
.bar-warn   { background: #f59e0b; }
.bar-danger { background: #ef4444; }
.empty-state { padding: 2.5rem 1.1rem; text-align: center; color: #8b8a93; font-size: .875rem; }
.empty-state a { color: #0ea5e9; text-decoration: none; font-weight: 500; }
.welcome-banner { background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border: 1px solid #bae6fd; border-radius: 12px; padding: 1.1rem 1.4rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 1rem; }
.welcome-banner .icon { font-size: 1.75rem; flex-shrink: 0; }
.welcome-banner h2 { margin: 0 0 .2rem; font-size: 1rem; font-weight: 600; color: #0c4a6e; }
.welcome-banner p  { margin: 0; font-size: .83rem; color: #0369a1; }
.welcome-banner a  { display: inline-flex; align-items: center; gap: .35rem; background: #0ea5e9; color: #fff; padding: .55rem 1rem; border-radius: 8px; text-decoration: none; font-size: .82rem; font-weight: 600; white-space: nowrap; flex-shrink: 0; }
@media (max-width: 880px) {
  .kpi-grid { grid-template-columns: repeat(2, 1fr); }
  .two-col  { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
  .kpi-grid { grid-template-columns: 1fr 1fr; }
}
</style>

<?= flash_render() ?>

<?php if ($conv_total === 0 && $agents_total === 0): ?>
<div class="dash">
  <div class="welcome-banner">
    <div class="icon">🤖</div>
    <div style="flex:1">
      <h2>Bem-vindo ao Newton IA!</h2>
      <p>Crie seu primeiro agente e conecte ao WhatsApp em menos de 5 minutos.</p>
    </div>
    <a href="agents.php">Criar agente →</a>
  </div>
</div>
<?php endif ?>

<?php if (!empty($_GET['signup']) && $_GET['signup'] === 'ok'): ?>
<div class="dash" style="padding-bottom:0">
  <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:12px;padding:.875rem 1.1rem;font-size:.875rem;color:#92400e;margin-bottom:1rem">
    ⏳ <strong>Conta criada!</strong> Sua cobrança está sendo gerada. Confira <a href="billing.php" style="color:#92400e;font-weight:600">Plano e cobranças</a> para ver o link de pagamento.
  </div>
</div>
<?php endif ?>

<div class="dash">
  <div class="dash-header">
    <h1>Olá<?= auth_user_name() ? ', ' . e(explode(' ', auth_user_name())[0]) : '' ?> 👋</h1>
    <p>Aqui está o resumo da sua operação Newton IA</p>
  </div>

  <!-- KPIs -->
  <div class="kpi-grid">
    <div class="kpi" style="--c:#0ea5e9">
      <div class="lbl">Agentes</div>
      <div class="val"><?= $agents_total ?></div>
      <div class="sub"><?= $agents_active ?> ativo<?= $agents_active !== 1 ? 's' : '' ?></div>
    </div>
    <div class="kpi" style="--c:#22c55e">
      <div class="lbl">Conversas abertas</div>
      <div class="val"><?= $conv_open ?></div>
      <div class="sub"><?= $conv_today ?> hoje · <?= $conv_paused ?> pausada<?= $conv_paused !== 1 ? 's' : '' ?></div>
    </div>
    <div class="kpi" style="--c:#8b5cf6">
      <div class="lbl">Mensagens hoje</div>
      <div class="val"><?= number_format($msgs_today) ?></div>
      <div class="sub"><?= number_format($conv_total) ?> conversa<?= $conv_total !== 1 ? 's' : '' ?> no total</div>
    </div>
    <div class="kpi <?= $msg_bar ?>" style="--c:<?= $msg_bar === 'ok' ? '#22c55e' : ($msg_bar === 'warn' ? '#f59e0b' : '#ef4444') ?>">
      <div class="lbl">Msgs este mês</div>
      <div class="val"><?= number_format($msgs_month) ?><?php if ($msg_limit): ?><span style="font-size:.6em;color:#8b8a93;font-weight:500"> / <?= number_format($msg_limit) ?></span><?php endif ?></div>
      <?php if ($msg_limit): ?>
      <div class="bar-wrap"><div class="bar-fill bar-<?= $msg_bar ?>" style="width:<?= $msg_pct ?>%"></div></div>
      <?php else: ?>
      <div class="sub">sem limite configurado</div>
      <?php endif ?>
    </div>
  </div>

  <!-- Conversas recentes + Agentes -->
  <div class="two-col">
    <!-- Conversas recentes -->
    <div class="panel">
      <div class="panel-head">
        <h3>Conversas recentes</h3>
        <a href="conversations.php">Ver todas →</a>
      </div>
      <div class="panel-body">
        <?php if (empty($recent_convs)): ?>
        <div class="empty-state">
          Nenhuma conversa ainda.<br>
          <a href="agents.php">Criar um agente →</a>
        </div>
        <?php else: ?>
        <?php foreach ($recent_convs as $c):
          $name = $c['contact_name'] ?: $c['contact_phone'] ?: '?';
          $init = mb_strtoupper(mb_substr($name, 0, 1));
          $ts   = $c['last_message_at'] ?? $c['started_at'];
        ?>
        <a class="conv-row" href="conversation-view.php?id=<?= (int)$c['id'] ?>">
          <div class="conv-avatar"><?= e($init) ?></div>
          <div class="conv-info">
            <div class="conv-name"><?= e(mb_strimwidth($name, 0, 24, '…')) ?></div>
            <div class="conv-prev"><?= e(mb_strimwidth($c['last_msg'] ?? '…', 0, 55, '…')) ?></div>
          </div>
          <div class="conv-meta">
            <div class="conv-time"><?= ago($ts) ?></div>
            <div class="conv-badge badge-<?= e($c['status']) ?>"><?= match($c['status']) { 'open'=>'Aberta','paused'=>'Pausada','closed'=>'Fechada',default=>$c['status'] } ?></div>
          </div>
        </a>
        <?php endforeach ?>
        <?php endif ?>
      </div>
    </div>

    <!-- Agentes -->
    <div class="panel">
      <div class="panel-head">
        <h3>Agentes</h3>
        <a href="agents.php">Gerenciar →</a>
      </div>
      <div class="panel-body">
        <?php if (empty($agents_stats)): ?>
        <div class="empty-state">
          Nenhum agente ainda.<br>
          <a href="agent-edit.php">Criar agente →</a>
        </div>
        <?php else: ?>
        <?php foreach ($agents_stats as $a): ?>
        <a class="agent-row" href="agent-edit.php?id=<?= (int)$a['id'] ?>">
          <div class="agent-dot <?= $a['status'] === 'active' ? 'on' : 'off' ?>"></div>
          <div class="agent-name"><?= e($a['name']) ?></div>
          <div class="agent-stat"><?= (int)$a['conv_open'] ?> abertas · <?= (int)$a['conv_today'] ?> hoje</div>
        </a>
        <?php endforeach ?>
        <?php if ($agents_stats): ?>
        <div style="padding:.75rem 1.1rem;border-top:1px solid #f5f3ef">
          <a href="agent-edit.php" style="font-size:.8rem;color:#0ea5e9;text-decoration:none;font-weight:500">+ Novo agente</a>
        </div>
        <?php endif ?>
        <?php endif ?>
      </div>
    </div>
  </div>

  <!-- Inbox rápido: conversas precisando de atenção -->
  <?php $needs_attention = array_filter($recent_convs, fn($c) => $c['status'] === 'paused'); ?>
  <?php if ($needs_attention): ?>
  <div class="panel" style="margin-bottom:1.25rem">
    <div class="panel-head">
      <h3>⚠ Aguardando atendimento humano</h3>
      <a href="inbox.php">Abrir inbox →</a>
    </div>
    <div class="panel-body">
      <?php foreach ($needs_attention as $c):
        $name = $c['contact_name'] ?: $c['contact_phone'] ?: '?';
        $ts   = $c['last_message_at'] ?? $c['started_at'];
      ?>
      <a class="conv-row" href="inbox.php?id=<?= (int)$c['id'] ?>">
        <div class="conv-avatar" style="background:#fefce8;color:#ca8a04"><?= e(mb_strtoupper(mb_substr($name,0,1))) ?></div>
        <div class="conv-info">
          <div class="conv-name"><?= e(mb_strimwidth($name,0,24,'…')) ?></div>
          <div class="conv-prev"><?= e(mb_strimwidth($c['last_msg'] ?? '…',0,55,'…')) ?></div>
        </div>
        <div class="conv-meta">
          <div class="conv-time"><?= ago($ts) ?></div>
          <div class="conv-badge badge-paused">Pausada</div>
        </div>
      </a>
      <?php endforeach ?>
    </div>
  </div>
  <?php endif ?>

</div>
<?php
});
