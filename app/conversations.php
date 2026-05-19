<?php
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/_layout.php';

$tid      = (int) $tenant['id'];
$agentId  = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
$statusF  = $_GET['status'] ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 30;
$offset   = ($page - 1) * $perPage;

$where  = 'c.tenant_id = ?';
$params = [$tid];
if ($agentId) { $where .= ' AND c.agent_id = ?'; $params[] = $agentId; }
if ($statusF) { $where .= ' AND c.status = ?';   $params[] = $statusF; }

$total = (int) db_val("SELECT COUNT(*) FROM conversations c WHERE $where", $params);
$convs = db_all(
    "SELECT c.*, a.name AS agent_name
     FROM conversations c
     LEFT JOIN agents a ON a.id = c.agent_id
     WHERE $where
     ORDER BY c.last_message_at DESC, c.started_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

$agents = db_all('SELECT id, name FROM agents WHERE tenant_id = ? ORDER BY name', [$tid]);

app_layout('Conversas · SYNAPSE', 'conversations', function() use ($convs, $agents, $agentId, $statusF, $total, $page, $perPage) {
?>
<div style="max-width:960px;margin:0 auto;padding:2rem 1.5rem">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem">
    <div>
      <div style="font-family:'Geist Mono',monospace;font-size:.68rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#0ea5e9;margin-bottom:.3rem">SYNAPSE</div>
      <h1 style="margin:0;font-size:1.5rem;font-weight:600;color:#18181b">Conversas</h1>
    </div>
    <div style="font-size:.85rem;color:#8b8a93"><?= number_format($total) ?> conversa<?= $total !== 1 ? 's' : '' ?></div>
  </div>

  <!-- Filtros -->
  <form method="GET" style="display:flex;gap:.6rem;margin-bottom:1.5rem;flex-wrap:wrap">
    <select name="agent_id" style="padding:.55rem .85rem;border:1px solid #e7e5e0;border-radius:8px;font-size:.85rem;background:#fff;color:#18181b;outline:none" onchange="this.form.submit()">
      <option value="">Todos os agentes</option>
      <?php foreach ($agents as $a): ?>
      <option value="<?= (int)$a['id'] ?>" <?= $agentId===$a['id']?'selected':'' ?>><?= htmlspecialchars($a['name']) ?></option>
      <?php endforeach ?>
    </select>
    <select name="status" style="padding:.55rem .85rem;border:1px solid #e7e5e0;border-radius:8px;font-size:.85rem;background:#fff;color:#18181b;outline:none" onchange="this.form.submit()">
      <option value="">Todos os status</option>
      <option value="open"   <?= $statusF==='open'   ?'selected':'' ?>>Abertas</option>
      <option value="closed" <?= $statusF==='closed' ?'selected':'' ?>>Fechadas</option>
      <option value="paused" <?= $statusF==='paused' ?'selected':'' ?>>Pausadas</option>
    </select>
  </form>

  <?php if (empty($convs)): ?>
  <div style="text-align:center;padding:4rem 2rem;background:#fff;border:1px solid #e7e5e0;border-radius:12px">
    <div style="font-size:2rem;margin-bottom:.75rem">💬</div>
    <h3 style="margin:0 0 .4rem;color:#18181b;font-size:1rem">Nenhuma conversa ainda</h3>
    <p style="margin:0;color:#8b8a93;font-size:.875rem">As conversas aparecem aqui assim que alguem interagir com o agente via WhatsApp.</p>
  </div>
  <?php else: ?>

  <div style="background:#fff;border:1px solid #e7e5e0;border-radius:12px;overflow:hidden">
    <?php foreach ($convs as $i => $conv):
      $statusCfg = match($conv['status']) {
        'open'   => ['bg'=>'#f0fdf4','color'=>'#16a34a','label'=>'Aberta'],
        'closed' => ['bg'=>'#f8fafc','color'=>'#64748b','label'=>'Fechada'],
        'paused' => ['bg'=>'#fefce8','color'=>'#ca8a04','label'=>'Pausada'],
        default  => ['bg'=>'#f8fafc','color'=>'#94a3b8','label'=>$conv['status']],
      };
      $lastAt = $conv['last_message_at'] ?? $conv['started_at'];
      $lastFmt = $lastAt ? date('d/m H:i', strtotime($lastAt)) : '—';
    ?>
    <a href="conversation-view.php?id=<?= (int)$conv['id'] ?>" style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;text-decoration:none;color:inherit;border-bottom:<?= $i < count($convs)-1 ? '1px solid #f4f2ed' : 'none' ?>;transition:background .1s" onmouseover="this.style.background='#fafaf9'" onmouseout="this.style.background=''">

      <div style="width:40px;height:40px;background:#f0f9ff;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1rem">
        <?= mb_strtoupper(mb_substr($conv['contact_name'] ?: $conv['contact_phone'] ?: '?', 0, 1)) ?>
      </div>

      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.2rem">
          <span style="font-weight:600;font-size:.9rem;color:#18181b"><?= htmlspecialchars($conv['contact_name'] ?: $conv['contact_phone'] ?: 'Desconhecido') ?></span>
          <span style="font-size:.72rem;font-weight:600;padding:2px 7px;border-radius:99px;background:<?= $statusCfg['bg'] ?>;color:<?= $statusCfg['color'] ?>"><?= $statusCfg['label'] ?></span>
        </div>
        <div style="font-size:.8rem;color:#8b8a93;display:flex;gap:.75rem">
          <span><?= htmlspecialchars($conv['agent_name'] ?? '—') ?></span>
          <?php if ($conv['contact_phone']): ?><span style="font-family:'Geist Mono',monospace;font-size:.75rem"><?= htmlspecialchars($conv['contact_phone']) ?></span><?php endif ?>
          <span><?= (int)$conv['message_count'] ?> msgs</span>
        </div>
      </div>

      <div style="text-align:right;flex-shrink:0">
        <div style="font-size:.78rem;color:#8b8a93"><?= $lastFmt ?></div>
      </div>

      <svg width="14" height="14" fill="none" stroke="#d1d5db" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0"><path d="M9 18l6-6-6-6"/></svg>
    </a>
    <?php endforeach ?>
  </div>

  <?php if ($total > $perPage): ?>
  <div style="display:flex;gap:.5rem;justify-content:center;margin-top:1.5rem">
    <?php
    $pages = ceil($total / $perPage);
    $qs    = http_build_query(['agent_id' => $agentId ?: null, 'status' => $statusF ?: null]);
    for ($p = 1; $p <= $pages; $p++):
    ?>
    <a href="?page=<?= $p ?>&<?= $qs ?>" style="padding:.4rem .75rem;border-radius:6px;font-size:.82rem;text-decoration:none;<?= $p===$page ? 'background:#0ea5e9;color:#fff' : 'background:#fff;border:1px solid #e7e5e0;color:#3a3a40' ?>"><?= $p ?></a>
    <?php endfor ?>
  </div>
  <?php endif ?>

  <?php endif ?>
</div>
<?php });
