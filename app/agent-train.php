<?php
/**
 * Newton IA — Treino do agente: Knowledge Base + Keyword Triggers
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_layout.php';

$tenant = require_tenant();
$tid    = (int) $tenant['id'];
$id     = (int) ($_GET['id'] ?? 0);
$agent  = $id ? agent_get($id, $tid) : null;
if (!$agent) { header('Location: /app/agents.php'); exit; }

// ── AJAX actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_check()) { echo json_encode(['ok'=>false,'error'=>'Sessao expirada']); exit; }
    $a = $_POST['action'];

    if ($a === 'kb_save') {
        $kbId = (int)($_POST['kb_id'] ?? 0);
        $data = [
            'title'       => $_POST['title']   ?? '',
            'content'     => $_POST['content'] ?? '',
            'source_type' => $_POST['source_type'] ?? 'text',
            'source_url'  => $_POST['source_url']  ?? null,
            'enabled'     => !empty($_POST['enabled']),
        ];
        if (!trim($data['title']))   { echo json_encode(['ok'=>false,'error'=>'titulo obrigatorio']); exit; }
        if (!trim($data['content'])) { echo json_encode(['ok'=>false,'error'=>'conteudo obrigatorio']); exit; }
        $newId = kb_save($tid, $id, $data, $kbId ?: null);
        echo json_encode(['ok'=>true, 'id'=>$newId]); exit;
    }
    if ($a === 'kb_delete') {
        kb_delete((int)$_POST['kb_id'], $tid);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($a === 'kw_save') {
        $kwId = (int)($_POST['kw_id'] ?? 0);
        $data = [
            'keyword'     => $_POST['keyword']    ?? '',
            'match_type'  => $_POST['match_type'] ?? 'contains',
            'action'      => $_POST['action_kw']  ?? 'handoff',
            'action_data' => $_POST['action_data']?? '',
            'direction'   => $_POST['direction']  ?? 'in',
            'active'      => !empty($_POST['active']),
        ];
        if (!trim($data['keyword'])) { echo json_encode(['ok'=>false,'error'=>'keyword obrigatoria']); exit; }
        $newId = kw_save($tid, $id, $data, $kwId ?: null);
        echo json_encode(['ok'=>true, 'id'=>$newId]); exit;
    }
    if ($a === 'kw_delete') {
        kw_delete((int)$_POST['kw_id'], $tid);
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false,'error'=>'acao desconhecida']); exit;
}

$kbItems = kb_list($id, $tid);
$kwItems = kw_list($id, $tid);
$kbTotal = array_sum(array_column($kbItems, 'char_count'));

app_layout('Treino · ' . $agent['name'], 'agents', function() use ($agent, $kbItems, $kwItems, $kbTotal) {
?>
<style>
  .train-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem; }
  .breadcrumb { font-size:.78rem; color:var(--ink-3); margin-bottom:.5rem; }
  .breadcrumb a { color:#0ea5e9; text-decoration:none; }
  .train-head h1 { font-size:1.4rem; margin:0; }
  .card { background:var(--white); border:1px solid var(--border); border-radius:12px; padding:1.25rem 1.4rem; margin-bottom:1.25rem; }
  .card-head { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1rem; flex-wrap:wrap; }
  .card h2 { font-size:1.05rem; margin:0 0 .25rem; }
  .card .sub { color:var(--ink-3); font-size:.78rem; }

  .btn { display:inline-flex; align-items:center; gap:.4rem; padding:.55rem .95rem; border-radius:8px; border:none; font-weight:600; font-size:.82rem; cursor:pointer; }
  .btn-primary { background:#0ea5e9; color:#fff; }
  .btn-primary:hover { background:#0284c7; }
  .btn-ghost { background:transparent; color:var(--ink-2); border:1px solid var(--border); }
  .btn-danger { background:transparent; color:#dc2626; border:1px solid #fecaca; }

  .item-row { display:flex; gap:1rem; align-items:flex-start; padding:.85rem 0; border-bottom:1px solid var(--fog); }
  .item-row:last-child { border-bottom:none; }
  .item-row .meta { flex:1; min-width:0; }
  .item-row .title { font-weight:600; font-size:.92rem; margin-bottom:.2rem; }
  .item-row .preview { color:var(--ink-3); font-size:.78rem; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
  .item-row .actions { display:flex; gap:.4rem; flex-shrink:0; }

  .pill { display:inline-block; padding:.15rem .5rem; border-radius:99px; font-size:.66rem; font-weight:600; font-family:'Geist Mono',monospace; margin-right:.3rem; }
  .pill-ok { background:#dcfce7; color:#15803d; }
  .pill-off { background:#fee2e2; color:#b91c1c; }
  .pill-info { background:#e0f2fe; color:#0369a1; }
  .pill-warn { background:#fef3c7; color:#92400e; }

  .empty { text-align:center; padding:2rem; color:var(--ink-3); font-size:.85rem; }

  .modal-bg { display:none; position:fixed; inset:0; background:rgba(10,10,15,.5); z-index:100; align-items:center; justify-content:center; padding:1rem; }
  .modal-bg.open { display:flex; }
  .modal { background:#fff; border-radius:12px; padding:1.5rem; max-width:640px; width:100%; max-height:90vh; overflow-y:auto; }
  .modal h3 { margin:0 0 .3rem; font-size:1.1rem; }
  .modal .sub { color:var(--ink-3); font-size:.82rem; margin-bottom:1rem; }
  .modal label { display:block; font-size:.78rem; font-weight:600; color:var(--ink-2); margin:.8rem 0 .35rem; }
  .modal input, .modal select, .modal textarea { width:100%; padding:.6rem .8rem; border:1px solid var(--border); border-radius:8px; font-size:.88rem; font-family:inherit; box-sizing:border-box; }
  .modal textarea { min-height:180px; resize:vertical; font-family:'Geist Mono',monospace; font-size:.82rem; line-height:1.55; }
  .modal-actions { display:flex; gap:.6rem; margin-top:1.2rem; justify-content:flex-end; }
  .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:.8rem; }

  .stat-bar { display:flex; gap:1.5rem; align-items:center; padding:.6rem 0; }
  .stat { display:flex; flex-direction:column; }
  .stat b { font-size:1.1rem; color:var(--ink); }
  .stat span { font-size:.7rem; color:var(--ink-3); }
</style>

<div class="breadcrumb">
  <a href="agents.php">Agentes</a> ›
  <a href="agent-edit.php?id=<?= (int)$agent['id'] ?>"><?= e($agent['name']) ?></a> ›
  Treino
</div>

<div class="train-head">
  <div>
    <h1>🧠 Treino do agente</h1>
    <div class="sub" style="color:var(--ink-3);font-size:.82rem">Ensine seu agente sobre seu negocio e configure gatilhos automaticos.</div>
  </div>
  <a href="agent-edit.php?id=<?= (int)$agent['id'] ?>" class="btn btn-ghost">← Voltar ao agente</a>
</div>

<!-- ── KNOWLEDGE BASE ───────────────────────────────────────────── -->
<div class="card">
  <div class="card-head">
    <div>
      <h2>Base de conhecimento</h2>
      <div class="sub">Snippets de texto sobre seu negocio. O agente vai usar isso pra responder duvidas factuais (precos, horarios, politicas, FAQ).</div>
    </div>
    <button class="btn btn-primary" onclick="openKb()">+ Adicionar snippet</button>
  </div>

  <div class="stat-bar">
    <div class="stat"><b><?= count($kbItems) ?></b><span>snippets</span></div>
    <div class="stat"><b><?= number_format($kbTotal, 0, ',', '.') ?></b><span>caracteres</span></div>
    <div class="stat"><b><?= count(array_filter($kbItems, fn($k)=>$k['enabled'])) ?></b><span>ativos</span></div>
  </div>

  <?php if (!$kbItems): ?>
    <div class="empty">
      Nenhum conhecimento adicionado.<br>
      <small>Comece adicionando informacoes basicas: precos, horarios de atendimento, politicas, FAQ.</small>
    </div>
  <?php else: ?>
    <?php foreach ($kbItems as $k): ?>
      <div class="item-row">
        <div class="meta">
          <div class="title">
            <?= e($k['title']) ?>
            <?= $k['enabled'] ? '<span class="pill pill-ok">ativo</span>' : '<span class="pill pill-off">desligado</span>' ?>
            <span class="pill pill-info"><?= e($k['source_type']) ?></span>
            <span class="pill pill-info"><?= number_format((int)$k['char_count'], 0, ',', '.') ?> chars</span>
          </div>
          <div class="preview" id="kb-prev-<?= (int)$k['id'] ?>">carregando...</div>
        </div>
        <div class="actions">
          <button class="btn btn-ghost" onclick="editKb(<?= (int)$k['id'] ?>)">Editar</button>
          <button class="btn btn-danger" onclick="deleteKb(<?= (int)$k['id'] ?>)">×</button>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ── KEYWORD TRIGGERS ─────────────────────────────────────────── -->
<div class="card">
  <div class="card-head">
    <div>
      <h2>Gatilhos por palavra-chave</h2>
      <div class="sub">Detecta termos nas mensagens e dispara acoes: transferir pra humano, marcar com tag, chamar webhook.</div>
    </div>
    <button class="btn btn-primary" onclick="openKw()">+ Novo gatilho</button>
  </div>

  <?php if (!$kwItems): ?>
    <div class="empty">
      Nenhum gatilho configurado.<br>
      <small>Exemplos: "cancelar" → handoff humano · "reclamacao" → tag urgente · "boleto vencido" → webhook</small>
    </div>
  <?php else: ?>
    <?php foreach ($kwItems as $k): ?>
      <div class="item-row">
        <div class="meta">
          <div class="title">
            <code style="background:#f1f5f9;padding:.1rem .4rem;border-radius:4px;font-size:.85rem"><?= e($k['keyword']) ?></code>
            <span class="pill pill-info"><?= e($k['match_type']) ?></span>
            <span class="pill pill-warn">→ <?= e($k['action']) ?><?= $k['action_data'] ? ': ' . e($k['action_data']) : '' ?></span>
            <span class="pill pill-info">dir: <?= e($k['direction']) ?></span>
            <?= $k['active'] ? '<span class="pill pill-ok">ativo</span>' : '<span class="pill pill-off">desligado</span>' ?>
          </div>
          <div class="preview"><?= (int)$k['hit_count'] ?> acionamento(s)</div>
        </div>
        <div class="actions">
          <button class="btn btn-ghost" onclick="editKw(<?= (int)$k['id'] ?>)">Editar</button>
          <button class="btn btn-danger" onclick="deleteKw(<?= (int)$k['id'] ?>)">×</button>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ── MODAL KB ─────────────────────────────────────────────────── -->
<div class="modal-bg" id="modal-kb">
  <div class="modal">
    <h3 id="modal-kb-title">Adicionar snippet</h3>
    <div class="sub">O agente vai usar este conteudo como fonte de verdade pra responder duvidas factuais.</div>
    <input type="hidden" id="kb-id" value="">
    <label>Titulo (identifica o snippet)</label>
    <input type="text" id="kb-title" placeholder="Ex: Politica de devolucao, Horarios, FAQ pagamento">
    <label>Conteudo</label>
    <textarea id="kb-content" placeholder="Cole aqui o texto que o agente deve saber..."></textarea>
    <div class="grid-2">
      <div>
        <label>Tipo</label>
        <select id="kb-type">
          <option value="text">Texto</option>
          <option value="url">URL de origem (so referencia)</option>
          <option value="file">Arquivo (so referencia)</option>
        </select>
      </div>
      <div>
        <label>URL de origem (opcional)</label>
        <input type="text" id="kb-url" placeholder="https://...">
      </div>
    </div>
    <label style="margin-top:.8rem"><input type="checkbox" id="kb-enabled" checked> Ativo (incluir no prompt do agente)</label>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-kb')">Cancelar</button>
      <button class="btn btn-primary" onclick="saveKb()">Salvar</button>
    </div>
  </div>
</div>

<!-- ── MODAL KW ─────────────────────────────────────────────────── -->
<div class="modal-bg" id="modal-kw">
  <div class="modal">
    <h3 id="modal-kw-title">Novo gatilho</h3>
    <div class="sub">Quando essa palavra/expressao for detectada, executa a acao escolhida.</div>
    <input type="hidden" id="kw-id" value="">
    <label>Palavra-chave ou expressao</label>
    <input type="text" id="kw-keyword" placeholder="Ex: cancelar, reclamacao, urgente, boleto vencido">
    <div class="grid-2">
      <div>
        <label>Modo de match</label>
        <select id="kw-match">
          <option value="contains">Contem</option>
          <option value="exact">Exato</option>
          <option value="starts_with">Comeca com</option>
          <option value="regex">Regex (avancado)</option>
        </select>
      </div>
      <div>
        <label>Direcao da mensagem</label>
        <select id="kw-direction">
          <option value="in">Inbound (cliente disse)</option>
          <option value="out">Outbound (agente disse)</option>
          <option value="any">Ambos</option>
        </select>
      </div>
    </div>
    <label>Acao</label>
    <select id="kw-action">
      <option value="handoff">Transferir pra humano (handoff)</option>
      <option value="pause">Pausar conversa</option>
      <option value="tag">Adicionar tag</option>
      <option value="webhook">Chamar webhook</option>
    </select>
    <label>Dado adicional <span class="sub">(nome da tag se acao=tag, URL se acao=webhook)</span></label>
    <input type="text" id="kw-data" placeholder="Ex: urgente, https://hook.eu1.make.com/...">
    <label style="margin-top:.8rem"><input type="checkbox" id="kw-active" checked> Ativo</label>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-kw')">Cancelar</button>
      <button class="btn btn-primary" onclick="saveKw()">Salvar</button>
    </div>
  </div>
</div>

<script>
const csrf = <?= json_encode(csrf_token()) ?>;
const kbData = <?= json_encode(array_column($kbItems, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
const kwData = <?= json_encode(array_column($kwItems, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;

// Preview do KB
Object.values(kbData).forEach(k => {
  const el = document.getElementById('kb-prev-' + k.id);
  if (el && k.content) el.textContent = k.content.substring(0, 160).replace(/\s+/g, ' ');
});

async function post(action, data = {}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf_token', csrf);
  for (const [k, v] of Object.entries(data)) fd.append(k, v);
  const r = await fetch('agent-train.php?id=<?= (int)$agent['id'] ?>', { method: 'POST', body: fd });
  return await r.json();
}

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// KB
function openKb() {
  document.getElementById('modal-kb-title').textContent = 'Adicionar snippet';
  document.getElementById('kb-id').value = '';
  document.getElementById('kb-title').value = '';
  document.getElementById('kb-content').value = '';
  document.getElementById('kb-type').value = 'text';
  document.getElementById('kb-url').value = '';
  document.getElementById('kb-enabled').checked = true;
  openModal('modal-kb');
}
function editKb(id) {
  const k = kbData[id]; if (!k) return;
  document.getElementById('modal-kb-title').textContent = 'Editar snippet';
  document.getElementById('kb-id').value = id;
  document.getElementById('kb-title').value = k.title;
  document.getElementById('kb-content').value = k.content || '';
  document.getElementById('kb-type').value = k.source_type;
  document.getElementById('kb-url').value = k.source_url || '';
  document.getElementById('kb-enabled').checked = !!parseInt(k.enabled);
  openModal('modal-kb');
}
async function saveKb() {
  const data = {
    kb_id:       document.getElementById('kb-id').value,
    title:       document.getElementById('kb-title').value,
    content:     document.getElementById('kb-content').value,
    source_type: document.getElementById('kb-type').value,
    source_url:  document.getElementById('kb-url').value,
  };
  if (document.getElementById('kb-enabled').checked) data.enabled = '1';
  const r = await post('kb_save', data);
  if (r.ok) location.reload(); else alert('Erro: ' + (r.error || 'desconhecido'));
}
async function deleteKb(id) {
  if (!confirm('Remover este snippet do conhecimento do agente?')) return;
  const r = await post('kb_delete', { kb_id: id });
  if (r.ok) location.reload();
}

// KW
function openKw() {
  document.getElementById('modal-kw-title').textContent = 'Novo gatilho';
  document.getElementById('kw-id').value = '';
  document.getElementById('kw-keyword').value = '';
  document.getElementById('kw-match').value = 'contains';
  document.getElementById('kw-direction').value = 'in';
  document.getElementById('kw-action').value = 'handoff';
  document.getElementById('kw-data').value = '';
  document.getElementById('kw-active').checked = true;
  openModal('modal-kw');
}
function editKw(id) {
  const k = kwData[id]; if (!k) return;
  document.getElementById('modal-kw-title').textContent = 'Editar gatilho';
  document.getElementById('kw-id').value = id;
  document.getElementById('kw-keyword').value = k.keyword;
  document.getElementById('kw-match').value = k.match_type;
  document.getElementById('kw-direction').value = k.direction;
  document.getElementById('kw-action').value = k.action;
  document.getElementById('kw-data').value = k.action_data || '';
  document.getElementById('kw-active').checked = !!parseInt(k.active);
  openModal('modal-kw');
}
async function saveKw() {
  const data = {
    kw_id:       document.getElementById('kw-id').value,
    keyword:     document.getElementById('kw-keyword').value,
    match_type:  document.getElementById('kw-match').value,
    direction:   document.getElementById('kw-direction').value,
    action_kw:   document.getElementById('kw-action').value,
    action_data: document.getElementById('kw-data').value,
  };
  if (document.getElementById('kw-active').checked) data.active = '1';
  const r = await post('kw_save', data);
  if (r.ok) location.reload(); else alert('Erro: ' + (r.error || 'desconhecido'));
}
async function deleteKw(id) {
  if (!confirm('Remover este gatilho?')) return;
  const r = await post('kw_delete', { kw_id: id });
  if (r.ok) location.reload();
}
</script>
<?php
});
