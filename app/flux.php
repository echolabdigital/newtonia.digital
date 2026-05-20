<?php
/**
 * Newton IA — FLUX: Leads + Campanhas
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_layout.php';

$tenant = require_tenant();
$tid    = (int) $tenant['id'];
$uid    = (int) auth_user_id();
$tab    = $_GET['tab'] ?? 'campaigns';

// ── AJAX actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_check()) { echo json_encode(['ok'=>false,'error'=>'Sessao expirada']); exit; }
    $a = $_POST['action'];

    if ($a === 'list_create') {
        $name = trim($_POST['name'] ?? '');
        if (!$name) { echo json_encode(['ok'=>false,'error'=>'nome obrigatorio']); exit; }
        $id = flux_list_create($tid, $name, 'manual', $uid);
        echo json_encode(['ok'=>true, 'id'=>$id]); exit;
    }
    if ($a === 'list_delete') {
        flux_list_delete((int)$_POST['list_id'], $tid);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($a === 'lead_add') {
        $listId = (int)($_POST['list_id'] ?? 0);
        if (!flux_list_get($listId, $tid)) { echo json_encode(['ok'=>false,'error'=>'lista nao encontrada']); exit; }
        $id = flux_lead_add($tid, $listId, $_POST);
        flux_list_refresh_count($listId);
        echo json_encode(['ok'=>(bool)$id, 'id'=>$id]); exit;
    }
    if ($a === 'scrape_google') {
        $listId = (int)($_POST['list_id'] ?? 0);
        $query  = trim($_POST['query'] ?? '');
        $max    = max(10, min(60, (int)($_POST['max'] ?? 30)));
        if (!flux_list_get($listId, $tid)) { echo json_encode(['ok'=>false,'error'=>'lista nao encontrada']); exit; }
        if (!$query) { echo json_encode(['ok'=>false,'error'=>'query obrigatoria']); exit; }
        $r = flux_scrape_google_maps($tid, $listId, $query, $max);
        echo json_encode($r); exit;
    }
    if ($a === 'campaign_create') {
        $id = flux_campaign_create($tid, $_POST, $uid);
        echo json_encode(['ok'=>true, 'id'=>$id]); exit;
    }
    if ($a === 'campaign_enqueue') {
        $count = flux_campaign_enqueue((int)$_POST['campaign_id'], $tid);
        echo json_encode(['ok'=>true, 'queued'=>$count]); exit;
    }
    if ($a === 'campaign_start') {
        $ok = flux_campaign_start((int)$_POST['campaign_id'], $tid);
        echo json_encode(['ok'=>$ok]); exit;
    }
    if ($a === 'campaign_pause') {
        flux_campaign_pause((int)$_POST['campaign_id'], $tid);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($a === 'campaign_dispatch_now') {
        // Dispara um lote manualmente (util pra testar antes do cron)
        $r = flux_campaign_dispatch((int)$_POST['campaign_id'], 3);
        echo json_encode(['ok'=>true, 'result'=>$r]); exit;
    }
    if ($a === 'campaign_delete') {
        flux_campaign_delete((int)$_POST['campaign_id'], $tid);
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false,'error'=>'acao desconhecida']); exit;
}

// ── Upload CSV ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csv_upload'])) {
    if (csrf_check() && isset($_FILES['csv']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $listId = (int)($_POST['list_id'] ?? 0);
        if (flux_list_get($listId, $tid)) {
            $result = flux_import_csv($tid, $listId, $_FILES['csv']['tmp_name']);
            $_SESSION['flash_csv'] = $result;
        }
    }
    header('Location: /app/flux.php?tab=leads&list=' . (int)($_POST['list_id'] ?? 0));
    exit;
}

$lists     = flux_lists($tid);
$campaigns = flux_campaigns($tid);
$agents    = db_all('SELECT id, name FROM agents WHERE tenant_id = ? AND status = "active" ORDER BY name', [$tid]);
$channels  = db_all('SELECT ac.id, ac.connected_phone, ac.status, a.name AS agent_name
                     FROM agent_channels ac JOIN agents a ON a.id = ac.agent_id
                     WHERE ac.tenant_id = ? ORDER BY ac.id DESC', [$tid]);
$listFilter = (int)($_GET['list'] ?? 0);
$leadsView  = $listFilter ? flux_leads($listFilter, $tid, 100) : [];
$listCur    = $listFilter ? flux_list_get($listFilter, $tid) : null;

app_layout('FLUX · Leads e Campanhas', 'flux', function() use ($tab, $lists, $campaigns, $agents, $channels, $leadsView, $listCur, $listFilter) {
?>
<style>
  .flux-head { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem; }
  .flux-head h1 { font-size:1.5rem; margin:0; }
  .flux-head .sub { color:var(--ink-3); font-size:.85rem; margin-top:.25rem; }

  .tabs { display:flex; gap:.25rem; border-bottom:1px solid var(--border); margin-bottom:1.5rem; }
  .tab { padding:.7rem 1.1rem; color:var(--ink-2); text-decoration:none; border-bottom:2px solid transparent; font-size:.85rem; font-weight:500; }
  .tab.active { color:#0ea5e9; border-bottom-color:#0ea5e9; font-weight:600; }
  .tab:hover { color:#0ea5e9; }

  .card { background:var(--white); border:1px solid var(--border); border-radius:12px; padding:1.25rem 1.4rem; margin-bottom:1.25rem; }
  .card-head { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1rem; flex-wrap:wrap; }
  .card h2 { font-size:1.05rem; margin:0; }

  .btn { display:inline-flex; align-items:center; gap:.4rem; padding:.55rem .95rem; border-radius:8px; border:none; font-weight:600; font-size:.82rem; cursor:pointer; text-decoration:none; }
  .btn-primary { background:#0ea5e9; color:#fff; }
  .btn-primary:hover { background:#0284c7; }
  .btn-ghost { background:transparent; color:var(--ink-2); border:1px solid var(--border); }
  .btn-danger { background:transparent; color:#dc2626; border:1px solid #fecaca; }
  .btn-warn { background:#f59e0b; color:#fff; }

  table { width:100%; border-collapse:collapse; font-size:.82rem; }
  th { text-align:left; padding:.6rem .5rem; color:var(--ink-3); font-weight:600; border-bottom:1px solid var(--border); font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; }
  td { padding:.7rem .5rem; border-bottom:1px solid var(--fog); vertical-align:middle; }
  .empty { text-align:center; padding:2.5rem; color:var(--ink-3); font-size:.85rem; }

  .pill { display:inline-block; padding:.2rem .55rem; border-radius:99px; font-size:.68rem; font-weight:600; font-family:'Geist Mono',monospace; }
  .pill-ok { background:#dcfce7; color:#15803d; }
  .pill-off { background:#fee2e2; color:#b91c1c; }
  .pill-info { background:#e0f2fe; color:#0369a1; }
  .pill-warn { background:#fef3c7; color:#92400e; }
  .pill-run { background:#fce7f3; color:#be185d; }

  .modal-bg { display:none; position:fixed; inset:0; background:rgba(10,10,15,.5); z-index:100; align-items:center; justify-content:center; padding:1rem; }
  .modal-bg.open { display:flex; }
  .modal { background:#fff; border-radius:12px; padding:1.5rem; max-width:680px; width:100%; max-height:90vh; overflow-y:auto; }
  .modal h3 { margin:0 0 .3rem; }
  .modal .sub { color:var(--ink-3); font-size:.82rem; margin-bottom:1rem; }
  .modal label { display:block; font-size:.78rem; font-weight:600; color:var(--ink-2); margin:.8rem 0 .35rem; }
  .modal input, .modal select, .modal textarea { width:100%; padding:.6rem .8rem; border:1px solid var(--border); border-radius:8px; font-size:.88rem; box-sizing:border-box; font-family:inherit; }
  .modal textarea { min-height:140px; resize:vertical; }
  .modal-actions { display:flex; gap:.6rem; margin-top:1.2rem; justify-content:flex-end; }
  .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:.8rem; }

  .progress { background:var(--fog); height:6px; border-radius:99px; overflow:hidden; margin:.3rem 0; }
  .progress > div { background:#0ea5e9; height:100%; transition:width .3s; }

  .stat-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:.6rem; }
  .stat-cell { background:var(--fog); border-radius:8px; padding:.6rem .8rem; }
  .stat-cell b { font-size:1.2rem; display:block; }
  .stat-cell span { font-size:.7rem; color:var(--ink-3); }

  .tpl-hint { background:#eff6ff; border-left:3px solid #0ea5e9; padding:.6rem .8rem; font-size:.75rem; color:#1e40af; border-radius:0 6px 6px 0; margin-top:.4rem; }
  .tpl-hint code { background:#dbeafe; padding:.05rem .3rem; border-radius:3px; }
</style>

<div class="flux-head">
  <div>
    <h1>⚡ FLUX</h1>
    <div class="sub">Extrai leads · Cria campanhas com IA personalizada · Orquestra o cerebro Newton com sistemas externos.</div>
  </div>
</div>

<div class="tabs">
  <a href="?tab=campaigns" class="tab <?= $tab==='campaigns'?'active':'' ?>">Campanhas</a>
  <a href="?tab=leads"     class="tab <?= $tab==='leads'?'active':'' ?>">Listas de leads</a>
</div>

<?php if ($tab === 'leads'): ?>
<!-- ============================================================
     LEADS
     ============================================================ -->

<?php if ($listCur): ?>
  <!-- ── Detalhes de uma lista ──────────────────────────────────── -->
  <div class="card">
    <div class="card-head">
      <div>
        <h2><?= e($listCur['name']) ?></h2>
        <div class="sub" style="font-size:.78rem;color:var(--ink-3);margin-top:.2rem">
          <?= (int)$listCur['lead_count'] ?> leads · fonte: <code><?= e($listCur['source']) ?></code>
        </div>
      </div>
      <div style="display:flex;gap:.4rem">
        <button class="btn btn-primary" onclick="openLead()">+ Adicionar lead</button>
        <button class="btn btn-warn" onclick="openScrape()">🌐 Buscar no Google Maps</button>
        <button class="btn btn-ghost" onclick="document.getElementById('csv-input').click()">📁 Importar CSV</button>
        <a href="?tab=leads" class="btn btn-ghost">← Listas</a>
      </div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="csv-form" style="display:none">
      <?= csrf_field() ?>
      <input type="hidden" name="csv_upload" value="1">
      <input type="hidden" name="list_id" value="<?= (int)$listCur['id'] ?>">
      <input type="file" id="csv-input" name="csv" accept=".csv" onchange="document.getElementById('csv-form').submit()">
    </form>

    <?php if (!empty($_SESSION['flash_csv'])): $f = $_SESSION['flash_csv']; unset($_SESSION['flash_csv']); ?>
      <div style="background:#dcfce7;border-left:3px solid #15803d;padding:.6rem .9rem;margin-bottom:1rem;border-radius:0 6px 6px 0;font-size:.82rem">
        ✓ CSV importado: <b><?= (int)$f['imported'] ?></b> leads adicionados · <b><?= (int)$f['skipped'] ?></b> ignorados (duplicados ou sem telefone)
      </div>
    <?php endif ?>

    <?php if (!$leadsView): ?>
      <div class="empty">Lista vazia. Adicione manualmente, importe um CSV ou busque no Google Maps.</div>
    <?php else: ?>
      <table>
        <thead><tr><th>Nome / Negocio</th><th>Telefone</th><th>Cidade</th><th>Status</th><th>Rating</th></tr></thead>
        <tbody>
          <?php foreach ($leadsView as $l): ?>
          <tr>
            <td><b><?= e($l['name'] ?? $l['business'] ?? '—') ?></b><?php if ($l['business'] && $l['name'] !== $l['business']): ?><br><span style="font-size:.72rem;color:var(--ink-3)"><?= e($l['business']) ?></span><?php endif ?></td>
            <td style="font-family:'Geist Mono',monospace;font-size:.78rem"><?= e($l['phone'] ?? '—') ?></td>
            <td><?= e(trim(($l['city'] ?? '') . ($l['state'] ? ' / ' . $l['state'] : ''))) ?: '—' ?></td>
            <td><span class="pill pill-info"><?= e($l['status']) ?></span></td>
            <td><?= $l['rating'] ? '⭐ ' . e($l['rating']) : '—' ?></td>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </div>
<?php else: ?>
  <!-- ── Index de listas ───────────────────────────────────────── -->
  <div class="card">
    <div class="card-head">
      <div>
        <h2>Listas de leads</h2>
        <div class="sub" style="font-size:.78rem;color:var(--ink-3)">Organize seus contatos por campanha. CSV, manual, Google Maps ou via API.</div>
      </div>
      <button class="btn btn-primary" onclick="openList()">+ Nova lista</button>
    </div>

    <?php if (!$lists): ?>
      <div class="empty">Nenhuma lista ainda. Crie uma para comecar.</div>
    <?php else: ?>
      <table>
        <thead><tr><th>Nome</th><th>Fonte</th><th>Leads</th><th>Criada</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($lists as $l): ?>
          <tr>
            <td><a href="?tab=leads&list=<?= (int)$l['id'] ?>" style="color:#0ea5e9;font-weight:600;text-decoration:none"><?= e($l['name']) ?></a></td>
            <td><span class="pill pill-info"><?= e($l['source']) ?></span></td>
            <td><b><?= (int)$l['lead_count'] ?></b></td>
            <td style="font-size:.78rem;color:var(--ink-3)"><?= e(date('d/m/Y', strtotime($l['created_at']))) ?></td>
            <td style="text-align:right"><button class="btn btn-danger" onclick="if(confirm('Deletar lista e todos os leads?')) deleteList(<?= (int)$l['id'] ?>)">×</button></td>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </div>
<?php endif ?>

<?php else: ?>
<!-- ============================================================
     CAMPANHAS
     ============================================================ -->
<div class="card">
  <div class="card-head">
    <div>
      <h2>Campanhas</h2>
      <div class="sub" style="font-size:.78rem;color:var(--ink-3)">Dispara mensagens em escala com IA personalizando cada uma pelo perfil do lead.</div>
    </div>
    <button class="btn btn-primary" onclick="openCampaign()" <?= !$lists || !$agents || !$channels ? 'disabled title="Precisa de uma lista, agente e canal conectado"' : '' ?>>+ Nova campanha</button>
  </div>

  <?php if (!$lists || !$agents): ?>
    <div class="empty">
      <?php if (!$agents): ?>Crie um <a href="agents.php">agente</a> primeiro.<?php endif ?>
      <?php if (!$lists):  ?> Crie uma <a href="?tab=leads">lista de leads</a> primeiro.<?php endif ?>
    </div>
  <?php elseif (!$campaigns): ?>
    <div class="empty">Nenhuma campanha ainda. Clique em <b>+ Nova campanha</b> para comecar.</div>
  <?php else: ?>
    <?php foreach ($campaigns as $c):
      $pct = $c['total'] > 0 ? round((($c['sent'] + $c['failed']) / $c['total']) * 100) : 0;
      $statusCfg = match($c['status']) {
        'draft'     => ['pill-info','rascunho'],
        'running'   => ['pill-run','rodando'],
        'paused'    => ['pill-warn','pausada'],
        'completed' => ['pill-ok','concluida'],
        'failed'    => ['pill-off','falhou'],
        default     => ['pill-info', $c['status']],
      };
    ?>
    <div style="border:1px solid var(--border);border-radius:10px;padding:1rem 1.25rem;margin-bottom:.8rem">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
        <div>
          <div style="font-weight:600;font-size:.95rem"><?= e($c['name']) ?> <span class="pill <?= $statusCfg[0] ?>"><?= $statusCfg[1] ?></span></div>
          <div style="font-size:.78rem;color:var(--ink-3);margin-top:.2rem">
            Agente: <b><?= e($c['agent_name']) ?></b> · Lista: <b><?= e($c['list_name']) ?></b> · Throttle: <?= (int)$c['throttle_per_min'] ?>/min · Cap: <?= (int)$c['daily_cap'] ?>/dia
          </div>
        </div>
        <div style="display:flex;gap:.4rem">
          <?php if ($c['status'] === 'draft'): ?>
            <button class="btn btn-primary" onclick="startCampaign(<?= (int)$c['id'] ?>)">▶ Iniciar</button>
          <?php elseif ($c['status'] === 'running'): ?>
            <button class="btn btn-warn" onclick="pauseCampaign(<?= (int)$c['id'] ?>)">⏸ Pausar</button>
            <button class="btn btn-ghost" onclick="dispatchNow(<?= (int)$c['id'] ?>)" title="Envia 3 agora (teste)">↻ Disparar 3</button>
          <?php elseif ($c['status'] === 'paused'): ?>
            <button class="btn btn-primary" onclick="startCampaign(<?= (int)$c['id'] ?>)">▶ Retomar</button>
          <?php endif ?>
          <button class="btn btn-danger" onclick="if(confirm('Deletar campanha?')) deleteCampaign(<?= (int)$c['id'] ?>)">×</button>
        </div>
      </div>
      <div class="progress" style="margin-top:.7rem"><div style="width:<?= $pct ?>%"></div></div>
      <div class="stat-grid" style="margin-top:.4rem">
        <div class="stat-cell"><b><?= (int)$c['total'] ?></b><span>total</span></div>
        <div class="stat-cell"><b style="color:#15803d"><?= (int)$c['sent'] ?></b><span>enviadas</span></div>
        <div class="stat-cell"><b style="color:#b91c1c"><?= (int)$c['failed'] ?></b><span>falharam</span></div>
        <div class="stat-cell"><b style="color:#7c3aed"><?= (int)$c['replied'] ?></b><span>responderam</span></div>
      </div>
    </div>
    <?php endforeach ?>
  <?php endif ?>
</div>

<div class="card" style="background:#fef3c7;border-color:#fde68a">
  <h2 style="color:#92400e">⚠ Anti-ban WhatsApp</h2>
  <div style="font-size:.82rem;line-height:1.55;color:#78350f">
    Campanhas no WhatsApp tem risco real de banimento. Boas praticas:
    <ul style="margin:.4rem 0 0;padding-left:1.2rem">
      <li>Use chip dedicado (nao seu telefone pessoal)</li>
      <li>Throttle &le; 10 msg/min e cap diario &le; 500 (default Newton)</li>
      <li>Mensagens unicas por lead (personalize=on) reduzem deteccao de spam</li>
      <li>Aqueca o numero por 7 dias com conversas naturais antes da 1a campanha</li>
      <li>Se receber muitos blocks (msgs falhando), pause e troque de chip</li>
    </ul>
  </div>
</div>
<?php endif ?>

<!-- ── MODAIS ─────────────────────────────────────────────────── -->
<div class="modal-bg" id="modal-list">
  <div class="modal">
    <h3>Nova lista de leads</h3>
    <div class="sub">Organize seus contatos. Depois adicione leads manualmente, via CSV ou Google Maps.</div>
    <label>Nome da lista</label>
    <input type="text" id="list-name" placeholder="Ex: Veterinarios Florianopolis">
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-list')">Cancelar</button>
      <button class="btn btn-primary" onclick="saveList()">Criar lista</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="modal-lead">
  <div class="modal">
    <h3>Adicionar lead</h3>
    <div class="grid-2">
      <div><label>Nome</label><input type="text" id="lead-name"></div>
      <div><label>Telefone (com DDD)</label><input type="text" id="lead-phone" placeholder="(48) 99999-9999"></div>
      <div><label>Email</label><input type="text" id="lead-email"></div>
      <div><label>Negocio</label><input type="text" id="lead-business" placeholder="Ex: Clinica Vet ABC"></div>
      <div><label>Cidade</label><input type="text" id="lead-city"></div>
      <div><label>Estado</label><input type="text" id="lead-state" placeholder="SC" maxlength="2"></div>
    </div>
    <label>Endereco</label>
    <input type="text" id="lead-address">
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-lead')">Cancelar</button>
      <button class="btn btn-primary" onclick="saveLead()">Adicionar</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="modal-scrape">
  <div class="modal">
    <h3>🌐 Buscar leads no Google Maps</h3>
    <div class="sub">Requer Google Places API key configurada em <a href="/admin/integrations.php">Integracoes</a>. Custo: ~$0.03 por lead (Google cobra).</div>
    <label>Query</label>
    <input type="text" id="scrape-query" placeholder="Ex: veterinarios em florianopolis">
    <label>Max resultados (10-60)</label>
    <input type="number" id="scrape-max" value="30" min="10" max="60">
    <div id="scrape-result" style="margin-top:1rem;font-size:.85rem"></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-scrape')">Fechar</button>
      <button class="btn btn-warn" id="scrape-btn" onclick="runScrape()">Buscar</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="modal-campaign">
  <div class="modal">
    <h3>Nova campanha</h3>
    <div class="sub">Cada lead recebe uma mensagem unica gerada pela IA com base no template + dados dele.</div>
    <label>Nome da campanha</label>
    <input type="text" id="camp-name" placeholder="Ex: Prospect vets Florianopolis - jan">
    <div class="grid-2">
      <div>
        <label>Agente</label>
        <select id="camp-agent"><?php foreach ($agents as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach ?></select>
      </div>
      <div>
        <label>Lista de leads</label>
        <select id="camp-list"><?php foreach ($lists as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?> (<?= (int)$l['lead_count'] ?>)</option><?php endforeach ?></select>
      </div>
    </div>
    <label>Canal WhatsApp</label>
    <select id="camp-channel">
      <option value="">— selecionar —</option>
      <?php foreach ($channels as $ch): ?>
        <option value="<?= (int)$ch['id'] ?>"><?= e($ch['agent_name']) ?><?= $ch['connected_phone'] ? ' · ' . e($ch['connected_phone']) : '' ?> (<?= e($ch['status']) ?>)</option>
      <?php endforeach ?>
    </select>
    <label>Template da mensagem</label>
    <textarea id="camp-template" placeholder="Oi {first_name}, vi o {business} aqui em {city} e..."></textarea>
    <div class="tpl-hint">
      Placeholders: <code>{first_name}</code> <code>{name}</code> <code>{business}</code> <code>{city}</code> <code>{state}</code><br>
      Com personalizacao IA ativa, a mensagem final sera reescrita pela IA usando este template + dados do lead.
    </div>
    <div class="grid-2">
      <div>
        <label>Throttle (msg/min)</label>
        <input type="number" id="camp-throttle" value="8" min="1" max="40">
      </div>
      <div>
        <label>Cap diario (max msgs/dia)</label>
        <input type="number" id="camp-cap" value="500" min="10" max="5000">
      </div>
    </div>
    <label><input type="checkbox" id="camp-personalize" checked> Personalizar cada mensagem com IA (recomendado)</label>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-campaign')">Cancelar</button>
      <button class="btn btn-primary" onclick="saveCampaign()">Criar campanha</button>
    </div>
  </div>
</div>

<script>
const csrf = <?= json_encode(csrf_token()) ?>;
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
async function post(action, data = {}) {
  const fd = new FormData();
  fd.append('action', action); fd.append('csrf_token', csrf);
  for (const [k, v] of Object.entries(data)) fd.append(k, v);
  const r = await fetch('flux.php?tab=<?= e($tab) ?>', { method: 'POST', body: fd });
  return await r.json();
}

function openList() { document.getElementById('list-name').value = ''; openModal('modal-list'); }
async function saveList() {
  const name = document.getElementById('list-name').value.trim();
  if (!name) return alert('Nome obrigatorio');
  const r = await post('list_create', { name });
  if (r.ok) location.href = '?tab=leads&list=' + r.id; else alert('Erro');
}
async function deleteList(id) { const r = await post('list_delete', { list_id: id }); if (r.ok) location.reload(); }

function openLead() { ['name','phone','email','business','city','state','address'].forEach(k => document.getElementById('lead-'+k).value = ''); openModal('modal-lead'); }
async function saveLead() {
  const data = { list_id: <?= (int)$listFilter ?> };
  ['name','phone','email','business','city','state','address'].forEach(k => data[k] = document.getElementById('lead-'+k).value);
  const r = await post('lead_add', data);
  if (r.ok) location.reload(); else alert('Erro: lead nao adicionado (talvez duplicado)');
}

function openScrape() { document.getElementById('scrape-query').value = ''; document.getElementById('scrape-result').textContent = ''; openModal('modal-scrape'); }
async function runScrape() {
  const btn = document.getElementById('scrape-btn');
  btn.disabled = true; btn.textContent = 'Buscando...';
  const r = await post('scrape_google', {
    list_id: <?= (int)$listFilter ?>,
    query:   document.getElementById('scrape-query').value,
    max:     document.getElementById('scrape-max').value,
  });
  btn.disabled = false; btn.textContent = 'Buscar';
  if (r.ok) {
    document.getElementById('scrape-result').innerHTML = '✓ ' + r.added + ' leads adicionados (' + r.pages + ' paginas)';
    setTimeout(() => location.reload(), 1500);
  } else {
    document.getElementById('scrape-result').innerHTML = '<span style="color:#b91c1c">' + (r.error || 'Erro') + '</span>';
  }
}

function openCampaign() { openModal('modal-campaign'); }
async function saveCampaign() {
  const data = {
    name:             document.getElementById('camp-name').value,
    agent_id:         document.getElementById('camp-agent').value,
    list_id:          document.getElementById('camp-list').value,
    channel_id:       document.getElementById('camp-channel').value,
    template:         document.getElementById('camp-template').value,
    throttle_per_min: document.getElementById('camp-throttle').value,
    daily_cap:        document.getElementById('camp-cap').value,
  };
  if (document.getElementById('camp-personalize').checked) data.personalize = '1';
  if (!data.name || !data.template) return alert('Nome e template sao obrigatorios');
  const r = await post('campaign_create', data);
  if (r.ok) location.reload(); else alert('Erro');
}
async function startCampaign(id) {
  await post('campaign_enqueue', { campaign_id: id });
  const r = await post('campaign_start', { campaign_id: id });
  if (r.ok) location.reload();
}
async function pauseCampaign(id) { const r = await post('campaign_pause', { campaign_id: id }); if (r.ok) location.reload(); }
async function dispatchNow(id) {
  const r = await post('campaign_dispatch_now', { campaign_id: id });
  alert(r.ok ? 'Lote enviado: ' + JSON.stringify(r.result) : 'Erro');
  location.reload();
}
async function deleteCampaign(id) { const r = await post('campaign_delete', { campaign_id: id }); if (r.ok) location.reload(); }
</script>
<?php
});
