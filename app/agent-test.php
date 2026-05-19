<?php
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/_layout.php';

$tid   = (int) $tenant['id'];
$id    = (int)($_GET['id'] ?? 0);
$agent = $id ? agent_get($id, $tid) : null;
if (!$agent) { header('Location: /app/agents.php'); exit; }

// API de chat (POST JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    header('Content-Type: application/json');
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $msg     = trim($body['message'] ?? '');
    $history = $body['history'] ?? [];
    if (!$msg) { echo json_encode(['error' => 'empty']); exit; }
    $reply = synapse_test($agent, $history, $msg);
    echo json_encode(['reply' => $reply ?? 'Erro ao processar resposta.']);
    exit;
}

app_layout('Testar · '.htmlspecialchars($agent['name']), 'agents', function() use ($agent) {
?>
<style>
#chat-wrap{max-width:680px;margin:0 auto;padding:1.5rem;height:calc(100vh - 120px);display:flex;flex-direction:column}
#chat-header{padding:1rem 1.25rem;background:#fff;border:1px solid #e7e5e0;border-radius:12px 12px 0 0;display:flex;align-items:center;gap:.75rem}
#chat-body{flex:1;overflow-y:auto;padding:1rem;background:#f8fafc;border-left:1px solid #e7e5e0;border-right:1px solid #e7e5e0;display:flex;flex-direction:column;gap:.75rem}
#chat-footer{padding:.85rem 1rem;background:#fff;border:1px solid #e7e5e0;border-radius:0 0 12px 12px;display:flex;gap:.6rem}
.msg{max-width:78%;padding:.65rem .9rem;border-radius:12px;font-size:.88rem;line-height:1.55;word-break:break-word}
.msg-user{align-self:flex-end;background:#0ea5e9;color:#fff;border-radius:12px 12px 2px 12px}
.msg-agent{align-self:flex-start;background:#fff;color:#18181b;border:1px solid #e7e5e0;border-radius:12px 12px 12px 2px}
.msg-typing{align-self:flex-start;padding:.65rem .9rem;background:#fff;border:1px solid #e7e5e0;border-radius:12px 12px 12px 2px;color:#8b8a93;font-size:.82rem;font-style:italic}
#chat-input{flex:1;padding:.65rem .85rem;border:1px solid #e7e5e0;border-radius:8px;font-size:.9rem;outline:none;font-family:inherit}
#chat-input:focus{border-color:#0ea5e9}
#chat-send{padding:.65rem 1.1rem;background:#0ea5e9;color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer}
#chat-send:hover{background:#0284c7}
#chat-send:disabled{background:#94a3b8;cursor:not-allowed}
</style>

<div id="chat-wrap">
  <div id="chat-header">
    <div style="width:36px;height:36px;background:#f0f9ff;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <svg width="18" height="18" fill="none" stroke="#0ea5e9" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
    </div>
    <div>
      <div style="font-weight:600;font-size:.9rem;color:#18181b"><?= htmlspecialchars($agent['name']) ?></div>
      <div style="font-size:.75rem;color:#0ea5e9;font-family:'Geist Mono',monospace"><?= htmlspecialchars($agent['model']) ?></div>
    </div>
    <div style="margin-left:auto;display:flex;gap:.5rem">
      <span style="font-size:.72rem;font-weight:600;padding:3px 10px;border-radius:99px;background:#f0fdf4;color:#16a34a">Modo Teste</span>
      <a href="agent-edit.php?id=<?= (int)$agent['id'] ?>" style="font-size:.78rem;color:#8b8a93;text-decoration:none;padding:3px 8px;border-radius:6px;border:1px solid #e7e5e0">Editar</a>
    </div>
  </div>

  <div id="chat-body">
    <div class="msg msg-agent">Ola! Sou o <strong><?= htmlspecialchars($agent['name']) ?></strong>. Como posso ajudar?</div>
  </div>

  <div id="chat-footer">
    <input id="chat-input" type="text" placeholder="Digite uma mensagem..." autocomplete="off">
    <button id="chat-send" onclick="sendMsg()">Enviar</button>
  </div>
</div>

<script>
const history = [];
const body    = document.getElementById('chat-body');
const input   = document.getElementById('chat-input');
const sendBtn = document.getElementById('chat-send');

input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(); } });

function addMsg(text, role) {
  const div = document.createElement('div');
  div.className = 'msg ' + (role === 'user' ? 'msg-user' : 'msg-agent');
  div.textContent = text;
  body.appendChild(div);
  body.scrollTop = body.scrollHeight;
  return div;
}

async function sendMsg() {
  const msg = input.value.trim();
  if (!msg) return;
  input.value = '';
  sendBtn.disabled = true;

  addMsg(msg, 'user');
  history.push({ role: 'user', content: msg });

  const typing = document.createElement('div');
  typing.className = 'msg-typing';
  typing.textContent = 'digitando...';
  body.appendChild(typing);
  body.scrollTop = body.scrollHeight;

  try {
    const res = await fetch('', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: msg, history: history.slice(0,-1) })
    });
    const data = await res.json();
    typing.remove();
    const reply = data.reply || 'Erro ao processar.';
    addMsg(reply, 'agent');
    history.push({ role: 'assistant', content: reply });
  } catch(e) {
    typing.remove();
    addMsg('Erro de conexao. Tente novamente.', 'agent');
  }

  sendBtn.disabled = false;
  input.focus();
}
</script>
<?php });
