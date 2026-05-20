<?php
/**
 * Newton IA — PULSE: agenda + qualificacao SPIN
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_layout.php';

$tenant = require_tenant();
$tid    = (int) $tenant['id'];

// ── AJAX ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_check()) { echo json_encode(['ok'=>false,'error'=>'Sessao expirada']); exit; }
    $a = $_POST['action'];

    if ($a === 'create') {
        try { $id = pulse_create($tid, $_POST); echo json_encode(['ok'=>true,'id'=>$id]); }
        catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
        exit;
    }
    if ($a === 'status') { pulse_update_status((int)$_POST['id'], $tid, $_POST['status'] ?? ''); echo json_encode(['ok'=>true]); exit; }
    if ($a === 'delete') { pulse_delete((int)$_POST['id'], $tid); echo json_encode(['ok'=>true]); exit; }
    if ($a === 'slots')  {
        echo json_encode(['ok'=>true,'slots'=>pulse_slots_for_day((int)$_POST['agent_id'], $tid, $_POST['date'])]);
        exit;
    }
    echo json_encode(['ok'=>false,'error'=>'acao desconhecida']); exit;
}

$from   = $_GET['from'] ?? date('Y-m-d');
$to     = $_GET['to']   ?? date('Y-m-d', strtotime('+14 days'));
$appts  = pulse_list($tid, $from . ' 00:00:00', $to . ' 23:59:59');
$agents = db_all('SELECT id, name FROM agents WHERE tenant_id = ? AND status = "active" ORDER BY name', [$tid]);

// Estatisticas
$stats = db_one(
    "SELECT
        SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) AS sched,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS conf,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS done,
        SUM(CASE WHEN status = 'no_show'   THEN 1 ELSE 0 END) AS nos,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS canc
     FROM appointments WHERE tenant_id = ? AND starts_at >= ? AND starts_at <= ?",
    [$tid, $from.' 00:00:00', $to.' 23:59:59']
) ?: [];

app_layout('PULSE · Agenda', 'pulse', function() use ($appts, $agents, $stats, $from, $to) {
?>
<style>
  .pulse-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem; }
  .pulse-head h1 { font-size:1.5rem; margin:0; }
  .pulse-head .sub { color:var(--ink-3); font-size:.85rem; margin-top:.25rem; }

  .stats { display:grid; grid-template-columns:repeat(5,1fr); gap:.6rem; margin-bottom:1.25rem; }
  .stat { background:var(--white); border:1px solid var(--border); border-radius:10px; padding:.8rem 1rem; }
  .stat b { font-size:1.3rem; display:block; }
  .stat span { font-size:.7rem; color:var(--ink-3); text-transform:uppercase; letter-spacing:.04em; }

  .card { background:var(--white); border:1px solid var(--border); border-radius:12px; padding:1.25rem 1.4rem; margin-bottom:1.25rem; }
  .card-head { display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:1rem; flex-wrap:wrap; }
  .card h2 { font-size:1.05rem; margin:0; }

  .btn { display:inline-flex; align-items:center; gap:.4rem; padding:.55rem .95rem; border-radius:8px; border:none; font-weight:600; font-size:.82rem; cursor:pointer; }
  .btn-primary { background:#0ea5e9; color:#fff; }
  .btn-ghost   { background:transparent; border:1px solid var(--border); color:var(--ink-2); }
  .btn-danger  { background:transparent; border:1px solid #fecaca; color:#dc2626; }

  .appt { border:1px solid var(--border); border-radius:10px; padding:1rem 1.2rem; margin-bottom:.7rem; display:flex; gap:1rem; align-items:flex-start; flex-wrap:wrap; }
  .appt .time { width:90px; flex-shrink:0; }
  .appt .time b { font-size:1.1rem; }
  .appt .time span { font-size:.72rem; color:var(--ink-3); display:block; }
  .appt .info { flex:1; min-width:200px; }
  .appt .info b { font-size:.95rem; }
  .appt .info .meta { font-size:.78rem; color:var(--ink-3); margin-top:.2rem; }
  .appt .actions { display:flex; gap:.3rem; }

  .pill { display:inline-block; padding:.15rem .55rem; border-radius:99px; font-size:.66rem; font-weight:600; }
  .pill-sched { background:#e0f2fe; color:#0369a1; }
  .pill-conf  { background:#dcfce7; color:#15803d; }
  .pill-done  { background:#f1f5f9; color:#475569; }
  .pill-no    { background:#fee2e2; color:#b91c1c; }
  .pill-canc  { background:#fef3c7; color:#92400e; }

  .modal-bg { display:none; position:fixed; inset:0; background:rgba(10,10,15,.5); z-index:100; align-items:center; justify-content:center; padding:1rem; }
  .modal-bg.open { display:flex; }
  .modal { background:#fff; border-radius:12px; padding:1.5rem; max-width:560px; width:100%; max-height:90vh; overflow-y:auto; }
  .modal h3 { margin:0 0 .3rem; }
  .modal label { display:block; font-size:.78rem; font-weight:600; color:var(--ink-2); margin:.8rem 0 .35rem; }
  .modal input, .modal select, .modal textarea { width:100%; padding:.6rem .8rem; border:1px solid var(--border); border-radius:8px; font-size:.88rem; box-sizing:border-box; font-family:inherit; }
  .modal textarea { min-height:80px; resize:vertical; }
  .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:.7rem; }
  .modal-actions { display:flex; gap:.6rem; margin-top:1.2rem; justify-content:flex-end; }

  .empty { text-align:center; padding:2.5rem; color:var(--ink-3); font-size:.85rem; }

  .day-group { font-size:.78rem; font-weight:700; color:var(--ink-3); text-transform:uppercase; letter-spacing:.05em; margin:1rem 0 .5rem; }
</style>

<div class="pulse-head">
  <div>
    <h1>📅 PULSE</h1>
    <div class="sub">Agenda 24/7 com lembretes automaticos. Qualificacao SPIN Selling integrada no SYNAPSE.</div>
  </div>
  <button class="btn btn-primary" onclick="openCreate()">+ Novo agendamento</button>
</div>

<div class="stats">
  <div class="stat"><b style="color:#0369a1"><?= (int)($stats['sched'] ?? 0) ?></b><span>agendado</span></div>
  <div class="stat"><b style="color:#15803d"><?= (int)($stats['conf'] ?? 0) ?></b><span>confirmado</span></div>
  <div class="stat"><b style="color:#475569"><?= (int)($stats['done'] ?? 0) ?></b><span>realizado</span></div>
  <div class="stat"><b style="color:#b91c1c"><?= (int)($stats['nos']  ?? 0) ?></b><span>no-show</span></div>
  <div class="stat"><b style="color:#92400e"><?= (int)($stats['canc'] ?? 0) ?></b><span>cancelado</span></div>
</div>

<div class="card">
  <div class="card-head">
    <div>
      <h2>Agenda</h2>
      <div class="sub" style="font-size:.78rem;color:var(--ink-3)">De <?= e($from) ?> ate <?= e($to) ?> · lembretes 24h e 1h antes via WhatsApp</div>
    </div>
    <form method="GET" style="display:flex;gap:.4rem;align-items:center;font-size:.8rem">
      <input type="date" name="from" value="<?= e($from) ?>" style="padding:.4rem .55rem;border:1px solid var(--border);border-radius:6px">
      até
      <input type="date" name="to"   value="<?= e($to) ?>" style="padding:.4rem .55rem;border:1px solid var(--border);border-radius:6px">
      <button class="btn btn-ghost" type="submit">Filtrar</button>
    </form>
  </div>

  <?php if (!$appts): ?>
    <div class="empty">Nenhum agendamento neste periodo.</div>
  <?php else:
    $lastDay = '';
    foreach ($appts as $a):
      $day = date('Y-m-d', strtotime($a['starts_at']));
      if ($day !== $lastDay):
        $lastDay = $day; ?>
        <div class="day-group"><?= e(strftime('%A · %d de %B', strtotime($day))) ?: e(date('l · d \d\e M', strtotime($day))) ?></div>
      <?php endif;
      $statusCfg = match($a['status']) {
        'scheduled'    => ['pill-sched','agendado'],
        'confirmed'    => ['pill-conf','confirmado'],
        'rescheduled'  => ['pill-canc','reagendado'],
        'cancelled'    => ['pill-canc','cancelado'],
        'no_show'      => ['pill-no','no-show'],
        'completed'    => ['pill-done','realizado'],
        default        => ['pill-sched',$a['status']],
      };
    ?>
    <div class="appt">
      <div class="time">
        <b><?= e(date('H:i', strtotime($a['starts_at']))) ?></b>
        <span><?= e(date('H:i', strtotime($a['ends_at']))) ?></span>
      </div>
      <div class="info">
        <b><?= e($a['title']) ?></b> <span class="pill <?= $statusCfg[0] ?>"><?= $statusCfg[1] ?></span>
        <?php if ($a['reminded_24h']): ?><span class="pill pill-conf">lembrete 24h ✓</span><?php endif ?>
        <?php if ($a['reminded_1h']):  ?><span class="pill pill-conf">lembrete 1h ✓</span><?php endif ?>
        <div class="meta">
          <?= e($a['contact_name'] ?: 'Sem nome') ?>
          <?php if ($a['contact_phone']): ?> · <?= e($a['contact_phone']) ?><?php endif ?>
          · agente <b><?= e($a['agent_name'] ?? '—') ?></b>
          <?php if ($a['meeting_link']): ?> · <a href="<?= e($a['meeting_link']) ?>" target="_blank" style="color:#0ea5e9">link</a><?php endif ?>
        </div>
        <?php if ($a['notes']): ?><div class="meta" style="margin-top:.3rem;font-style:italic"><?= e($a['notes']) ?></div><?php endif ?>
      </div>
      <div class="actions">
        <?php if ($a['status'] === 'scheduled'): ?>
          <button class="btn btn-ghost" onclick="setStatus(<?= (int)$a['id'] ?>,'confirmed')">✓ Confirmar</button>
        <?php endif ?>
        <?php if (!in_array($a['status'], ['completed','cancelled','no_show'])): ?>
          <button class="btn btn-ghost" onclick="setStatus(<?= (int)$a['id'] ?>,'completed')">Realizado</button>
          <button class="btn btn-ghost" onclick="setStatus(<?= (int)$a['id'] ?>,'no_show')">No-show</button>
          <button class="btn btn-ghost" onclick="setStatus(<?= (int)$a['id'] ?>,'cancelled')">Cancelar</button>
        <?php endif ?>
        <button class="btn btn-danger" onclick="if(confirm('Deletar?')) del(<?= (int)$a['id'] ?>)">×</button>
      </div>
    </div>
    <?php endforeach ?>
  <?php endif ?>
</div>

<div class="card" style="background:#eff6ff;border-color:#bfdbfe">
  <h2 style="color:#1e40af">💡 Como integrar com agendamento automatico</h2>
  <div style="font-size:.82rem;line-height:1.55;color:#1e3a8a">
    Hoje voce cria appointments manualmente. Em breve o agente vai detectar intencao de agendamento e propor slots automaticamente.
    <ul style="margin:.5rem 0 0;padding-left:1.2rem">
      <li>Configure os horarios do agente em <a href="agents.php">Agentes</a> > editar</li>
      <li>Use a API <code>POST /api/v1/pulse/book</code> (em breve) para criar via HERMES/Make/n8n</li>
      <li>Lembretes saem automaticamente 24h e 1h antes via WhatsApp (cron a cada 5min)</li>
    </ul>
  </div>
</div>

<!-- ── MODAL CREATE ─────────────────────────────────────────────── -->
<div class="modal-bg" id="modal-create">
  <div class="modal">
    <h3>Novo agendamento</h3>
    <label>Titulo</label>
    <input type="text" id="ap-title" placeholder="Ex: Demo Newton IA — Joao da Silva">
    <div class="grid-2">
      <div>
        <label>Data</label>
        <input type="date" id="ap-date" value="<?= date('Y-m-d') ?>" onchange="loadSlots()">
      </div>
      <div>
        <label>Horario</label>
        <select id="ap-time"><option value="">(escolha data e agente)</option></select>
      </div>
    </div>
    <label>Agente</label>
    <select id="ap-agent" onchange="loadSlots()">
      <option value="">— selecionar —</option>
      <?php foreach ($agents as $ag): ?><option value="<?= (int)$ag['id'] ?>"><?= e($ag['name']) ?></option><?php endforeach ?>
    </select>
    <div class="grid-2">
      <div><label>Nome do contato</label><input type="text" id="ap-name"></div>
      <div><label>Telefone</label><input type="text" id="ap-phone" placeholder="(48) 99999-9999"></div>
    </div>
    <div class="grid-2">
      <div><label>Email</label><input type="text" id="ap-email"></div>
      <div><label>Tipo</label>
        <select id="ap-kind">
          <option value="video">Video</option>
          <option value="phone">Telefone</option>
          <option value="in_person">Presencial</option>
        </select>
      </div>
    </div>
    <label>Link da reuniao / endereco</label>
    <input type="text" id="ap-link" placeholder="https://meet.google.com/...">
    <label>Notas</label>
    <textarea id="ap-notes"></textarea>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal()">Cancelar</button>
      <button class="btn btn-primary" onclick="save()">Criar</button>
    </div>
  </div>
</div>

<script>
const csrf = <?= json_encode(csrf_token()) ?>;
async function post(action, data = {}) {
  const fd = new FormData(); fd.append('action', action); fd.append('csrf_token', csrf);
  for (const [k, v] of Object.entries(data)) fd.append(k, v);
  return (await fetch('pulse.php', { method:'POST', body:fd })).json();
}
function openCreate() { document.getElementById('modal-create').classList.add('open'); }
function closeModal() { document.getElementById('modal-create').classList.remove('open'); }

async function loadSlots() {
  const agent = document.getElementById('ap-agent').value;
  const date  = document.getElementById('ap-date').value;
  const sel   = document.getElementById('ap-time');
  if (!agent || !date) { sel.innerHTML = '<option>(escolha agente e data)</option>'; return; }
  const r = await post('slots', { agent_id: agent, date });
  if (!r.ok || !r.slots.length) { sel.innerHTML = '<option>(sem slots livres)</option>'; return; }
  sel.innerHTML = r.slots.map(s => `<option value="${s.iso}">${s.start} - ${s.end}</option>`).join('');
}

async function save() {
  const time = document.getElementById('ap-time').value;
  if (!time) return alert('Escolha um horario');
  const data = {
    title:         document.getElementById('ap-title').value,
    starts_at:     time,
    agent_id:      document.getElementById('ap-agent').value,
    contact_name:  document.getElementById('ap-name').value,
    contact_phone: document.getElementById('ap-phone').value,
    contact_email: document.getElementById('ap-email').value,
    meeting_kind:  document.getElementById('ap-kind').value,
    meeting_link:  document.getElementById('ap-link').value,
    notes:         document.getElementById('ap-notes').value,
  };
  if (!data.title) return alert('Informe um titulo');
  const r = await post('create', data);
  if (r.ok) location.reload(); else alert('Erro: ' + (r.error || ''));
}

async function setStatus(id, status) { const r = await post('status', { id, status }); if (r.ok) location.reload(); }
async function del(id) { const r = await post('delete', { id }); if (r.ok) location.reload(); }
</script>
<?php
});
