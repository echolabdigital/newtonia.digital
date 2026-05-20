<?php
require_once __DIR__ . '/../config.php';
require_super_admin();
require_once __DIR__ . '/_layout.php';

// Stats globais
$stats = [
    'tenants'       => (int) db_val('SELECT COUNT(*) FROM tenants WHERE status != "deleted"'),
    'tenants_active'=> (int) db_val('SELECT COUNT(*) FROM tenants WHERE status = "active"'),
    'agents'        => (int) db_val('SELECT COUNT(*) FROM agents'),
    'agents_active' => (int) db_val('SELECT COUNT(*) FROM agents WHERE status = "active"'),
    'conversations' => (int) db_val('SELECT COUNT(*) FROM conversations'),
    'conv_open'     => (int) db_val('SELECT COUNT(*) FROM conversations WHERE status = "open"'),
    'messages'      => (int) db_val('SELECT COUNT(*) FROM messages'),
    'msg_today'     => (int) db_val('SELECT COUNT(*) FROM messages WHERE DATE(sent_at) = CURDATE()'),
    'msg_week'      => (int) db_val('SELECT COUNT(*) FROM messages WHERE sent_at >= NOW() - INTERVAL 7 DAY'),
];

// LLM providers status
$catalog  = llm_catalog();
$llm_status = [];
foreach ($catalog as $p => $info) {
    $key     = setting_get("{$p}.api_key");
    $enabled = setting_get("{$p}.enabled", '0') === '1';
    $llm_status[$p] = [
        'label'       => $info['label'],
        'color'       => $info['color'],
        'icon'        => $info['icon'],
        'desc'        => $info['desc'],
        'configured'  => !empty($key),
        'enabled'     => $enabled,
        'models_count'=> count($info['models']),
    ];
}

// Tenants recentes
$recent_tenants = db_all('SELECT t.*, u.email AS owner_email FROM tenants t LEFT JOIN tenant_users tu ON tu.tenant_id = t.id AND tu.role = "owner" LEFT JOIN users u ON u.id = tu.user_id ORDER BY t.created_at DESC LIMIT 8');

// Atividade recente (mensagens por dia, últimos 7 dias)
$msg_chart = db_all('SELECT DATE(sent_at) AS d, COUNT(*) AS c FROM messages WHERE sent_at >= NOW() - INTERVAL 7 DAY GROUP BY DATE(sent_at) ORDER BY d ASC');

admin_layout('Dashboard · Newton IA', 'dashboard', function() use ($stats, $llm_status, $recent_tenants, $msg_chart) {
?>
<style>
:root { --newton: #0ea5e9; --newton-2: #0284c7; --newton-glow: rgba(14,165,233,.12); }
.stat-card { background: #fff; border: 1px solid #e7e5e0; border-radius: 14px; padding: 1.4rem 1.6rem; position: relative; overflow: hidden; transition: box-shadow .2s; }
.stat-card:hover { box-shadow: 0 8px 32px rgba(0,0,0,.07); }
.stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--accent, var(--newton)); }
.stat-val { font-size: 2.4rem; font-weight: 700; color: #18181b; line-height: 1; margin-bottom: .3rem; font-family: 'Geist Mono', monospace; }
.stat-label { font-size: .78rem; color: #8b8a93; font-weight: 500; text-transform: uppercase; letter-spacing: .07em; }
.stat-sub { font-size: .8rem; color: #8b8a93; margin-top: .35rem; }
.stat-badge { display: inline-block; font-size: .7rem; font-weight: 700; padding: 2px 8px; border-radius: 99px; margin-left: .5rem; vertical-align: middle; }
.grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
@media(max-width:900px) { .grid-4 { grid-template-columns: 1fr 1fr; } .grid-2 { grid-template-columns: 1fr; } }
@media(max-width:500px) { .grid-4 { grid-template-columns: 1fr; } }
.section-title { font-family: 'Geist Mono', monospace; font-size: .7rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #8b8a93; margin-bottom: .85rem; }
.card { background: #fff; border: 1px solid #e7e5e0; border-radius: 14px; overflow: hidden; }
.card-head { padding: 1.1rem 1.4rem; border-bottom: 1px solid #f4f2ed; display: flex; align-items: center; justify-content: space-between; }
.card-body { padding: 1.4rem; }
.provider-pill { display: flex; align-items: center; gap: .75rem; padding: .75rem 1rem; border-radius: 10px; border: 1px solid #f0f0f0; transition: all .15s; }
.provider-pill:hover { border-color: var(--pc, #e7e5e0); background: var(--pg, #fafafa); }
.dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.dot-green { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,.4); }
.dot-gray  { background: #d1d5db; }
.dot-amber { background: #f59e0b; box-shadow: 0 0 6px rgba(245,158,11,.3); }
.tenant-row { display: flex; align-items: center; gap: .85rem; padding: .75rem 0; border-bottom: 1px solid #f4f2ed; }
.tenant-row:last-child { border-bottom: none; }
.avatar { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .85rem; flex-shrink: 0; }
.bar-wrap { background: #f4f2ed; border-radius: 99px; height: 6px; overflow: hidden; }
.bar-fill { height: 100%; border-radius: 99px; background: var(--newton); }
</style>

<div style="max-width:1100px;margin:0 auto;padding:2rem 1.5rem">

  <!-- Header -->
  <div style="margin-bottom:2rem">
    <div style="font-family:'Geist Mono',monospace;font-size:.68rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--newton);margin-bottom:.4rem">NEWTON IA · SUPER ADMIN</div>
    <div style="display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:1rem">
      <h1 style="margin:0;font-size:1.8rem;font-weight:700;color:#18181b">Dashboard</h1>
      <div style="font-size:.82rem;color:#8b8a93"><?= date('l, d \d\e F \d\e Y') ?></div>
    </div>
  </div>

  <!-- Stats principais -->
  <div class="grid-4" style="margin-bottom:2rem">
    <div class="stat-card" style="--accent:#0ea5e9">
      <div class="stat-label">Workspaces</div>
      <div class="stat-val"><?= $stats['tenants'] ?></div>
      <div class="stat-sub"><?= $stats['tenants_active'] ?> ativos</div>
    </div>
    <div class="stat-card" style="--accent:#8b5cf6">
      <div class="stat-label">Agentes</div>
      <div class="stat-val"><?= $stats['agents'] ?></div>
      <div class="stat-sub"><?= $stats['agents_active'] ?> ativos</div>
    </div>
    <div class="stat-card" style="--accent:#0ea5e9">
      <div class="stat-label">Conversas</div>
      <div class="stat-val"><?= number_format($stats['conversations']) ?></div>
      <div class="stat-sub"><?= $stats['conv_open'] ?> abertas</div>
    </div>
    <div class="stat-card" style="--accent:#f59e0b">
      <div class="stat-label">Mensagens</div>
      <div class="stat-val"><?= number_format($stats['msg_today']) ?></div>
      <div class="stat-sub">Hoje · <?= number_format($stats['msg_week']) ?> esta semana</div>
    </div>
  </div>

  <div class="grid-2" style="margin-bottom:2rem">

    <!-- LLM Providers -->
    <div class="card">
      <div class="card-head">
        <div>
          <div class="section-title" style="margin:0 0 .2rem">Provedores de IA</div>
          <div style="font-size:.9rem;font-weight:600;color:#18181b">Status dos LLMs</div>
        </div>
        <a href="integrations.php" style="font-size:.78rem;color:var(--newton);text-decoration:none;padding:.35rem .75rem;border:1px solid #bae6fd;border-radius:7px;background:#f0f9ff">Configurar</a>
      </div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:.5rem">
        <?php foreach ($llm_status as $p => $info):
          $dotClass = $info['enabled'] && $info['configured'] ? 'dot-green' : ($info['configured'] ? 'dot-amber' : 'dot-gray');
          $label    = $info['enabled'] && $info['configured'] ? 'Ativo' : ($info['configured'] ? 'Configurado' : 'Sem API key');
          $labelClr = $info['enabled'] && $info['configured'] ? '#16a34a' : ($info['configured'] ? '#d97706' : '#94a3b8');
        ?>
        <div class="provider-pill" style="--pc:<?= $info['color'] ?>22;--pg:<?= $info['color'] ?>08">
          <div style="width:32px;height:32px;border-radius:8px;background:<?= $info['color'] ?>18;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;color:<?= $info['color'] ?>;font-family:'Geist Mono',monospace;flex-shrink:0"><?= $info['icon'] ?></div>
          <div style="flex:1">
            <div style="font-size:.85rem;font-weight:600;color:#18181b"><?= $info['label'] ?></div>
            <div style="font-size:.72rem;color:#8b8a93"><?= $info['desc'] ?></div>
          </div>
          <div style="display:flex;align-items:center;gap:.4rem;flex-shrink:0">
            <div class="dot <?= $dotClass ?>"></div>
            <span style="font-size:.72rem;color:<?= $labelClr ?>;font-weight:600"><?= $label ?></span>
          </div>
        </div>
        <?php endforeach ?>
      </div>
    </div>

    <!-- Tenants recentes -->
    <div class="card">
      <div class="card-head">
        <div>
          <div class="section-title" style="margin:0 0 .2rem">Workspaces</div>
          <div style="font-size:.9rem;font-weight:600;color:#18181b">Cadastros recentes</div>
        </div>
        <a href="tenants.php" style="font-size:.78rem;color:#8b8a93;text-decoration:none;padding:.35rem .75rem;border:1px solid #e7e5e0;border-radius:7px">Ver todos</a>
      </div>
      <div class="card-body" style="padding:1rem 1.4rem">
        <?php if (empty($recent_tenants)): ?>
        <div style="text-align:center;padding:1.5rem;color:#8b8a93;font-size:.875rem">Nenhum workspace ainda.</div>
        <?php else: ?>
        <?php foreach ($recent_tenants as $t):
          $initial = mb_strtoupper(mb_substr($t['brand_name'] ?: $t['name'], 0, 1));
          $color   = $t['brand_color'] ?: '#0ea5e9';
          $statusCfg = match($t['status']) {
            'active'    => ['bg'=>'#f0fdf4','c'=>'#16a34a','l'=>'Ativo'],
            'trial'     => ['bg'=>'#fef3c7','c'=>'#d97706','l'=>'Trial'],
            'suspended' => ['bg'=>'#fef2f2','c'=>'#dc2626','l'=>'Suspenso'],
            default     => ['bg'=>'#f8fafc','c'=>'#94a3b8','l'=>'Pendente'],
          };
        ?>
        <div class="tenant-row">
          <div class="avatar" style="background:<?= $color ?>18;color:<?= $color ?>"><?= $initial ?></div>
          <div style="flex:1;min-width:0">
            <div style="font-size:.85rem;font-weight:600;color:#18181b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($t['brand_name'] ?: $t['name']) ?></div>
            <div style="font-size:.75rem;color:#8b8a93"><?= htmlspecialchars($t['owner_email'] ?? '—') ?></div>
          </div>
          <span style="font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:99px;background:<?= $statusCfg['bg'] ?>;color:<?= $statusCfg['c'] ?>;flex-shrink:0"><?= $statusCfg['l'] ?></span>
        </div>
        <?php endforeach ?>
        <?php endif ?>
      </div>
    </div>
  </div>

  <!-- Atividade de mensagens (7 dias) -->
  <?php if (!empty($msg_chart)): ?>
  <div class="card" style="margin-bottom:2rem">
    <div class="card-head">
      <div class="section-title" style="margin:0">Mensagens por dia · últimos 7 dias</div>
    </div>
    <div class="card-body">
      <?php
      $maxVal = max(array_column($msg_chart, 'c'));
      $maxVal = max($maxVal, 1);
      ?>
      <div style="display:flex;align-items:flex-end;gap:.4rem;height:80px">
        <?php foreach ($msg_chart as $row):
          $pct = round(($row['c'] / $maxVal) * 100);
          $day = date('D', strtotime($row['d']));
        ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:.3rem;height:100%">
          <div style="flex:1;display:flex;align-items:flex-end;width:100%">
            <div title="<?= $row['c'] ?> msgs em <?= $row['d'] ?>" style="width:100%;height:<?= $pct ?>%;background:var(--newton);border-radius:4px 4px 0 0;min-height:4px;transition:height .3s;cursor:default"></div>
          </div>
          <div style="font-size:.65rem;color:#8b8a93;font-family:'Geist Mono',monospace"><?= $day ?></div>
        </div>
        <?php endforeach ?>
      </div>
    </div>
  </div>
  <?php endif ?>

  <!-- Quick actions -->
  <div style="display:flex;gap:.75rem;flex-wrap:wrap">
    <a href="tenants.php" style="flex:1;min-width:140px;padding:1rem 1.2rem;background:#fff;border:1px solid #e7e5e0;border-radius:12px;text-decoration:none;display:flex;align-items:center;gap:.75rem;transition:all .15s" onmouseover="this.style.borderColor='var(--newton)';this.style.background='#f0f9ff'" onmouseout="this.style.borderColor='#e7e5e0';this.style.background='#fff'">
      <svg width="20" height="20" fill="none" stroke="#0ea5e9" stroke-width="1.8" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      <span style="font-size:.85rem;font-weight:600;color:#18181b">Workspaces</span>
    </a>
    <a href="integrations.php" style="flex:1;min-width:140px;padding:1rem 1.2rem;background:#fff;border:1px solid #e7e5e0;border-radius:12px;text-decoration:none;display:flex;align-items:center;gap:.75rem;transition:all .15s" onmouseover="this.style.borderColor='var(--newton)';this.style.background='#f0f9ff'" onmouseout="this.style.borderColor='#e7e5e0';this.style.background='#fff'">
      <svg width="20" height="20" fill="none" stroke="#0ea5e9" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14"/></svg>
      <span style="font-size:.85rem;font-weight:600;color:#18181b">Integrações IA</span>
    </a>
    <a href="audit.php" style="flex:1;min-width:140px;padding:1rem 1.2rem;background:#fff;border:1px solid #e7e5e0;border-radius:12px;text-decoration:none;display:flex;align-items:center;gap:.75rem;transition:all .15s" onmouseover="this.style.borderColor='var(--newton)';this.style.background='#f0f9ff'" onmouseout="this.style.borderColor='#e7e5e0';this.style.background='#fff'">
      <svg width="20" height="20" fill="none" stroke="#0ea5e9" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
      <span style="font-size:.85rem;font-weight:600;color:#18181b">Audit Log</span>
    </a>
    <a href="plans.php" style="flex:1;min-width:140px;padding:1rem 1.2rem;background:#fff;border:1px solid #e7e5e0;border-radius:12px;text-decoration:none;display:flex;align-items:center;gap:.75rem;transition:all .15s" onmouseover="this.style.borderColor='var(--newton)';this.style.background='#f0f9ff'" onmouseout="this.style.borderColor='#e7e5e0';this.style.background='#fff'">
      <svg width="20" height="20" fill="none" stroke="#0ea5e9" stroke-width="1.8" viewBox="0 0 24 24"><path d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
      <span style="font-size:.85rem;font-weight:600;color:#18181b">Planos</span>
    </a>
  </div>

</div>
<?php });
