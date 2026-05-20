<?php
/**
 * Newton IA — API Keys & Webhooks
 * Gera/revoga chaves de API REST e configura webhooks de saida.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_layout.php';

$tenant = require_tenant();
$tid    = (int) $tenant['id'];
$uid    = (int) auth_user_id();

// ── AJAX actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_check()) { echo json_encode(['ok'=>false,'error'=>'Sessao expirada. Recarregue.']); exit; }

    $action = $_POST['action'];

    if ($action === 'create_key') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { echo json_encode(['ok'=>false,'error'=>'Nome obrigatorio']); exit; }
        if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);

        $scopes = $_POST['scopes'] ?? ['chat:read','chat:write','agents:read','conversations:read'];
        if (!is_array($scopes)) $scopes = explode(',', (string)$scopes);
        $allowed = ['chat:read','chat:write','agents:read','agents:write','conversations:read','conversations:write'];
        $scopes  = array_values(array_intersect($scopes, $allowed));
        if (!$scopes) $scopes = ['chat:read','chat:write'];

        $result = api_key_generate($tid, $name, $scopes, $uid);
        echo json_encode(['ok'=>true, 'key'=>$result['key'], 'id'=>$result['id'], 'prefix'=>$result['prefix']]);
        exit;
    }

    if ($action === 'revoke_key') {
        $id = (int)($_POST['id'] ?? 0);
        $ok = api_key_revoke($id, $tid);
        echo json_encode(['ok'=>$ok]);
        exit;
    }

    if ($action === 'create_webhook') {
        $name   = trim($_POST['name'] ?? '');
        $url    = trim($_POST['url'] ?? '');
        $events = $_POST['events'] ?? [];
        if (!is_array($events)) $events = explode(',', (string)$events);
        $allowed = ['message.received','message.sent','conversation.started','conversation.ended','handoff.requested'];
        $events  = array_values(array_intersect($events, $allowed));

        if ($name === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            echo json_encode(['ok'=>false,'error'=>'Nome e URL HTTPS validos sao obrigatorios']); exit;
        }
        if (!$events) { echo json_encode(['ok'=>false,'error'=>'Selecione ao menos 1 evento']); exit; }

        $secret = bin2hex(random_bytes(24));
        db_insert('outbound_webhooks', [
            'tenant_id' => $tid,
            'name'      => mb_substr($name, 0, 120),
            'url'       => $url,
            'events'    => implode(',', $events),
            'secret'    => $secret,
            'active'    => 1,
        ]);
        echo json_encode(['ok'=>true, 'secret'=>$secret]);
        exit;
    }

    if ($action === 'toggle_webhook') {
        $id = (int)($_POST['id'] ?? 0);
        db_q('UPDATE outbound_webhooks SET active = 1 - active WHERE id = ? AND tenant_id = ?', [$id, $tid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'delete_webhook') {
        $id = (int)($_POST['id'] ?? 0);
        db_q('DELETE FROM outbound_webhooks WHERE id = ? AND tenant_id = ?', [$id, $tid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'acao desconhecida']);
    exit;
}

$keys     = api_key_list($tid);
$webhooks = db_all('SELECT * FROM outbound_webhooks WHERE tenant_id = ? ORDER BY id DESC', [$tid]);
$recent   = db_all(
    'SELECT endpoint, method, status_code, latency_ms, created_at, error
     FROM api_request_logs WHERE tenant_id = ? ORDER BY id DESC LIMIT 20', [$tid]
);

app_layout('API & Webhooks', 'api-keys', function() use ($keys, $webhooks, $recent) {
?>
<style>
  .page-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem; }
  .page-head h1 { font-size:1.5rem; margin:0; }
  .page-head p { color:var(--ink-2); font-size:.85rem; margin:.25rem 0 0; }

  .card { background:var(--white); border:1px solid var(--border); border-radius:12px; padding:1.25rem 1.4rem; margin-bottom:1.25rem; }
  .card-head { display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:1rem; flex-wrap:wrap; }
  .card h2 { font-size:1.05rem; margin:0; }
  .card .sub { color:var(--ink-3); font-size:.78rem; margin-top:.2rem; }

  .btn { display:inline-flex; align-items:center; gap:.4rem; padding:.55rem .95rem; border-radius:8px; border:none; font-weight:600; font-size:.82rem; cursor:pointer; }
  .btn-primary { background:#0ea5e9; color:#fff; }
  .btn-primary:hover { background:#0284c7; }
  .btn-ghost { background:transparent; color:var(--ink-2); border:1px solid var(--border); }
  .btn-danger { background:transparent; color:#dc2626; border:1px solid #fecaca; }
  .btn-danger:hover { background:#fef2f2; }

  table { width:100%; border-collapse:collapse; font-size:.82rem; }
  th { text-align:left; padding:.6rem .5rem; color:var(--ink-3); font-weight:600; border-bottom:1px solid var(--border); font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; }
  td { padding:.7rem .5rem; border-bottom:1px solid var(--fog); vertical-align:middle; }
  tr:last-child td { border-bottom:none; }
  .empty { text-align:center; padding:2rem; color:var(--ink-3); font-size:.85rem; }

  .pill { display:inline-block; padding:.2rem .55rem; border-radius:99px; font-size:.68rem; font-weight:600; font-family:'Geist Mono',monospace; }
  .pill-ok { background:#dcfce7; color:#15803d; }
  .pill-off { background:#fee2e2; color:#b91c1c; }
  .pill-info { background:#e0f2fe; color:#0369a1; }

  .key-prefix { font-family:'Geist Mono',monospace; font-size:.78rem; color:var(--ink-2); }
  .reveal-key { font-family:'Geist Mono',monospace; background:#0a0a0f; color:#0ea5e9; padding:1rem; border-radius:8px; word-break:break-all; font-size:.85rem; display:flex; justify-content:space-between; gap:1rem; align-items:center; }
  .reveal-key button { background:#0ea5e9; color:#fff; border:none; padding:.4rem .8rem; border-radius:6px; font-size:.72rem; cursor:pointer; font-weight:600; }

  .modal-bg { display:none; position:fixed; inset:0; background:rgba(10,10,15,.5); z-index:100; align-items:center; justify-content:center; padding:1rem; }
  .modal-bg.open { display:flex; }
  .modal { background:#fff; border-radius:12px; padding:1.5rem; max-width:520px; width:100%; }
  .modal h3 { margin:0 0 .3rem; font-size:1.1rem; }
  .modal .sub { color:var(--ink-3); font-size:.82rem; margin-bottom:1rem; }
  .modal label { display:block; font-size:.78rem; font-weight:600; color:var(--ink-2); margin:.8rem 0 .35rem; }
  .modal input[type=text], .modal input[type=url] { width:100%; padding:.6rem .8rem; border:1px solid var(--border); border-radius:8px; font-size:.88rem; font-family:inherit; box-sizing:border-box; }
  .modal .scope-grid, .modal .event-grid { display:grid; grid-template-columns:1fr 1fr; gap:.4rem .8rem; margin-top:.3rem; }
  .modal .scope-grid label, .modal .event-grid label { font-weight:500; font-size:.82rem; display:flex; align-items:center; gap:.4rem; margin:0; cursor:pointer; }
  .modal-actions { display:flex; gap:.6rem; margin-top:1.2rem; justify-content:flex-end; }

  .code-block { background:#0a0a0f; color:#e2e8f0; padding:1rem 1.2rem; border-radius:8px; font-family:'Geist Mono',monospace; font-size:.78rem; overflow-x:auto; white-space:pre; line-height:1.6; }
  .code-block .k { color:#0ea5e9; }
  .code-block .s { color:#fbbf24; }
  .code-block .c { color:#64748b; }

  .docs-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-top:1rem; }
  @media (max-width: 720px) { .docs-grid { grid-template-columns:1fr; } }

  .endpoint { padding:.6rem .8rem; background:var(--fog); border-radius:6px; font-family:'Geist Mono',monospace; font-size:.78rem; margin-bottom:.4rem; }
  .endpoint .method { display:inline-block; padding:.1rem .4rem; border-radius:4px; font-size:.7rem; font-weight:700; margin-right:.5rem; }
  .endpoint .method.get  { background:#dcfce7; color:#15803d; }
  .endpoint .method.post { background:#dbeafe; color:#1d4ed8; }
</style>

<div class="page-head">
  <div>
    <h1>API & Webhooks</h1>
    <p>Conecte HERMES, Make, n8n ou qualquer sistema externo ao seu cerebro Newton IA.</p>
  </div>
</div>

<!-- ── API KEYS ────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-head">
    <div>
      <h2>API Keys</h2>
      <div class="sub">Chaves para autenticar chamadas em <code>/api/v1/*</code>. Cada chave pertence a este workspace.</div>
    </div>
    <button class="btn btn-primary" onclick="openCreateKey()">+ Nova chave</button>
  </div>

  <?php if (!$keys): ?>
    <div class="empty">Nenhuma chave criada ainda. Clique em <b>+ Nova chave</b> para comecar.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>Nome</th><th>Chave</th><th>Scopes</th><th>Ultimo uso</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($keys as $k):
          $revoked = !empty($k['revoked_at']);
          $scopes  = explode(',', $k['scopes'] ?? '');
        ?>
        <tr style="<?= $revoked ? 'opacity:.45' : '' ?>">
          <td><b><?= e($k['name']) ?></b></td>
          <td><span class="key-prefix"><?= e($k['key_prefix']) ?>•••••••••••••••</span></td>
          <td><?php foreach ($scopes as $s): ?><span class="pill pill-info" style="margin-right:.2rem"><?= e(trim($s)) ?></span><?php endforeach; ?></td>
          <td><?= $k['last_used_at'] ? e(date('d/m/Y H:i', strtotime($k['last_used_at']))) : '<span style="color:var(--ink-3)">nunca</span>' ?></td>
          <td><?= $revoked ? '<span class="pill pill-off">revogada</span>' : '<span class="pill pill-ok">ativa</span>' ?></td>
          <td style="text-align:right">
            <?php if (!$revoked): ?>
              <button class="btn btn-danger" onclick="revokeKey(<?= (int)$k['id'] ?>)">Revogar</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- ── WEBHOOKS ─────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-head">
    <div>
      <h2>Webhooks de saida</h2>
      <div class="sub">Newton notifica voce em uma URL externa quando eventos acontecem. Use com Make, n8n, HERMES ou Zapier.</div>
    </div>
    <button class="btn btn-primary" onclick="openCreateWebhook()">+ Novo webhook</button>
  </div>

  <?php if (!$webhooks): ?>
    <div class="empty">Nenhum webhook configurado. Crie um para que Newton notifique seu sistema em tempo real.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>Nome</th><th>URL</th><th>Eventos</th><th>Ultimo disparo</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($webhooks as $w): ?>
        <tr>
          <td><b><?= e($w['name']) ?></b></td>
          <td style="font-family:'Geist Mono',monospace;font-size:.75rem;color:var(--ink-2);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($w['url']) ?></td>
          <td><?php foreach (explode(',', $w['events']) as $ev): ?><span class="pill pill-info" style="margin-right:.2rem"><?= e($ev) ?></span><?php endforeach; ?></td>
          <td><?= $w['last_fired_at'] ? e(date('d/m H:i', strtotime($w['last_fired_at']))) . ' <span class="pill ' . ((int)$w['last_status']>=200&&(int)$w['last_status']<300?'pill-ok':'pill-off') . '">'.(int)$w['last_status'].'</span>' : '<span style="color:var(--ink-3)">nunca</span>' ?></td>
          <td><?= $w['active'] ? '<span class="pill pill-ok">ativo</span>' : '<span class="pill pill-off">desligado</span>' ?></td>
          <td style="text-align:right;white-space:nowrap">
            <button class="btn btn-ghost" onclick="toggleWebhook(<?= (int)$w['id'] ?>)"><?= $w['active'] ? 'Desligar' : 'Ligar' ?></button>
            <button class="btn btn-danger" onclick="deleteWebhook(<?= (int)$w['id'] ?>)">Remover</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- ── DOCUMENTACAO ─────────────────────────────────────────────── -->
<div class="card">
  <h2>Quick start</h2>
  <div class="sub" style="margin-bottom:1rem">Base URL: <code><?= e(APP_URL) ?>/api/v1</code> · Autenticacao: <code>Authorization: Bearer nai_...</code></div>

  <div style="margin-bottom:1rem">
    <div class="endpoint"><span class="method post">POST</span>/api/v1/chat <span style="color:var(--ink-3)">— envia mensagem, recebe resposta da IA</span></div>
    <div class="endpoint"><span class="method get">GET</span>/api/v1/agents <span style="color:var(--ink-3)">— lista agentes</span></div>
    <div class="endpoint"><span class="method get">GET</span>/api/v1/conversations <span style="color:var(--ink-3)">— historico de conversas</span></div>
  </div>

  <div class="docs-grid">
    <div>
      <b style="font-size:.82rem">Exemplo: enviar mensagem</b>
<pre class="code-block"><span class="c"># cURL</span>
curl -X POST <?= e(APP_URL) ?>/api/v1/chat \
  -H <span class="s">"Authorization: Bearer nai_..."</span> \
  -H <span class="s">"Content-Type: application/json"</span> \
  -d <span class="s">'{
    "agent_id": 1,
    "message": "Olá, quero um orçamento",
    "contact": {"phone": "+5547999998888", "name": "Maria"}
  }'</span></pre>
    </div>
    <div>
      <b style="font-size:.82rem">Resposta</b>
<pre class="code-block">{
  <span class="k">"ok"</span>: true,
  <span class="k">"reply"</span>: <span class="s">"Claro Maria, posso te ajudar..."</span>,
  <span class="k">"conversation_id"</span>: 42,
  <span class="k">"message_id"</span>: 1337,
  <span class="k">"provider"</span>: <span class="s">"groq"</span>,
  <span class="k">"model"</span>: <span class="s">"llama-3.3-70b-versatile"</span>,
  <span class="k">"latency_ms"</span>: 412
}</pre>
    </div>
  </div>
</div>

<!-- ── LOG RECENTE ──────────────────────────────────────────────── -->
<?php if ($recent): ?>
<div class="card">
  <h2>Atividade recente</h2>
  <div class="sub" style="margin-bottom:1rem">Ultimas 20 requisicoes em <code>/api/v1/*</code></div>
  <table>
    <thead><tr><th>Quando</th><th>Endpoint</th><th>Metodo</th><th>Status</th><th>Latencia</th><th>Erro</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td style="font-size:.75rem;color:var(--ink-3)"><?= e(date('d/m H:i:s', strtotime($r['created_at']))) ?></td>
        <td style="font-family:'Geist Mono',monospace;font-size:.78rem"><?= e($r['endpoint']) ?></td>
        <td><span class="pill pill-info"><?= e($r['method']) ?></span></td>
        <td><?php $c=(int)$r['status_code']; $cls = $c>=200&&$c<300?'pill-ok':'pill-off'; ?><span class="pill <?= $cls ?>"><?= $c ?></span></td>
        <td style="font-size:.78rem"><?= e($r['latency_ms'] ?? 0) ?>ms</td>
        <td style="font-size:.75rem;color:#b91c1c"><?= e($r['error'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ── MODAL: criar key ─────────────────────────────────────────── -->
<div class="modal-bg" id="modal-create-key">
  <div class="modal">
    <h3>Nova API Key</h3>
    <div class="sub">A chave so e exibida 1 vez. Copie e guarde em local seguro.</div>
    <label>Nome (identificacao interna)</label>
    <input type="text" id="key-name" placeholder="Ex: HERMES integration, n8n flow, etc.">
    <label>Permissoes (scopes)</label>
    <div class="scope-grid">
      <label><input type="checkbox" value="chat:read" checked> chat:read</label>
      <label><input type="checkbox" value="chat:write" checked> chat:write</label>
      <label><input type="checkbox" value="agents:read" checked> agents:read</label>
      <label><input type="checkbox" value="agents:write"> agents:write</label>
      <label><input type="checkbox" value="conversations:read" checked> conversations:read</label>
      <label><input type="checkbox" value="conversations:write"> conversations:write</label>
    </div>
    <div id="key-result" style="margin-top:1rem;display:none"></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-create-key')">Fechar</button>
      <button class="btn btn-primary" id="key-create-btn" onclick="createKey()">Gerar chave</button>
    </div>
  </div>
</div>

<!-- ── MODAL: criar webhook ─────────────────────────────────────── -->
<div class="modal-bg" id="modal-create-wh">
  <div class="modal">
    <h3>Novo Webhook</h3>
    <div class="sub">Newton vai chamar essa URL via POST quando os eventos selecionados acontecerem. Inclui header <code>X-Newton-Signature</code> com HMAC-SHA256.</div>
    <label>Nome</label>
    <input type="text" id="wh-name" placeholder="Ex: Make.com — leads veterinarios">
    <label>URL (HTTPS)</label>
    <input type="url" id="wh-url" placeholder="https://hook.eu1.make.com/...">
    <label>Eventos</label>
    <div class="event-grid">
      <label><input type="checkbox" value="message.received" checked> message.received</label>
      <label><input type="checkbox" value="message.sent" checked> message.sent</label>
      <label><input type="checkbox" value="conversation.started"> conversation.started</label>
      <label><input type="checkbox" value="conversation.ended"> conversation.ended</label>
      <label><input type="checkbox" value="handoff.requested"> handoff.requested</label>
    </div>
    <div id="wh-result" style="margin-top:1rem;display:none"></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('modal-create-wh')">Fechar</button>
      <button class="btn btn-primary" onclick="createWebhook()">Criar webhook</button>
    </div>
  </div>
</div>

<script>
const csrf = <?= json_encode(csrf_token()) ?>;

function openCreateKey()     { document.getElementById('modal-create-key').classList.add('open'); document.getElementById('key-result').style.display='none'; document.getElementById('key-create-btn').style.display=''; document.getElementById('key-name').value=''; }
function openCreateWebhook() { document.getElementById('modal-create-wh').classList.add('open'); document.getElementById('wh-result').style.display='none'; }
function closeModal(id)      { document.getElementById(id).classList.remove('open'); if (id === 'modal-create-key' || id === 'modal-create-wh') location.reload(); }

async function post(action, data = {}) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf_token', csrf);
  for (const [k, v] of Object.entries(data)) {
    if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
    else fd.append(k, v);
  }
  const r = await fetch('api-keys.php', { method: 'POST', body: fd });
  return await r.json();
}

async function createKey() {
  const name   = document.getElementById('key-name').value.trim();
  if (!name) { alert('Informe um nome'); return; }
  const scopes = [...document.querySelectorAll('#modal-create-key .scope-grid input:checked')].map(c => c.value);
  const r = await post('create_key', { name, scopes });
  if (!r.ok) { alert('Erro: ' + (r.error || 'desconhecido')); return; }

  document.getElementById('key-create-btn').style.display = 'none';
  const box = document.getElementById('key-result');
  box.innerHTML = `
    <b style="font-size:.85rem;display:block;margin-bottom:.4rem">Sua chave (copie agora — nao sera exibida novamente):</b>
    <div class="reveal-key">
      <span id="raw-key">${r.key}</span>
      <button onclick="navigator.clipboard.writeText('${r.key}'); this.textContent='Copiado ✓'">Copiar</button>
    </div>`;
  box.style.display = 'block';
}

async function revokeKey(id) {
  if (!confirm('Revogar essa chave? Sistemas que a usam pararao de funcionar imediatamente.')) return;
  const r = await post('revoke_key', { id });
  if (r.ok) location.reload(); else alert('Erro');
}

async function createWebhook() {
  const name   = document.getElementById('wh-name').value.trim();
  const url    = document.getElementById('wh-url').value.trim();
  const events = [...document.querySelectorAll('#modal-create-wh .event-grid input:checked')].map(c => c.value);
  if (!name || !url) { alert('Preencha nome e URL'); return; }
  if (!events.length) { alert('Selecione ao menos 1 evento'); return; }

  const r = await post('create_webhook', { name, url, events });
  if (!r.ok) { alert('Erro: ' + (r.error || 'desconhecido')); return; }

  const box = document.getElementById('wh-result');
  box.innerHTML = `
    <b style="font-size:.85rem;display:block;margin-bottom:.4rem">Webhook criado. Use este secret para validar HMAC:</b>
    <div class="reveal-key">
      <span>${r.secret}</span>
      <button onclick="navigator.clipboard.writeText('${r.secret}'); this.textContent='Copiado ✓'">Copiar</button>
    </div>`;
  box.style.display = 'block';
}

async function toggleWebhook(id) { const r = await post('toggle_webhook', { id }); if (r.ok) location.reload(); }
async function deleteWebhook(id) { if (!confirm('Remover webhook?')) return; const r = await post('delete_webhook', { id }); if (r.ok) location.reload(); }
</script>
<?php
});
