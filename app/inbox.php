<?php
/**
 * Inbox — Handoff humano
 * Mostra conversas abertas e pausadas. Permite responder como humano,
 * pausar o bot (status=paused) e retomá-lo (status=open).
 */
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/_layout.php';

$tid     = (int) $tenant['id'];
$activeId = (int)($_GET['id'] ?? 0);

// Filtro de status
$statusF = $_GET['status'] ?? 'open,paused';
$statuses = array_filter(explode(',', $statusF), fn($s) => in_array($s, ['open','paused','closed']));
if (empty($statuses)) $statuses = ['open','paused'];
$placeholders = implode(',', array_fill(0, count($statuses), '?'));

// Lista de conversas
$convList = db_all(
    "SELECT c.*, a.name AS agent_name,
            (SELECT content FROM messages WHERE conversation_id = c.id ORDER BY sent_at DESC LIMIT 1) AS last_msg,
            (SELECT sent_at FROM messages WHERE conversation_id = c.id ORDER BY sent_at DESC LIMIT 1) AS last_msg_at
     FROM conversations c
     LEFT JOIN agents a ON a.id = c.agent_id
     WHERE c.tenant_id = ? AND c.status IN ($placeholders)
     ORDER BY COALESCE(last_msg_at, c.started_at) DESC
     LIMIT 60",
    array_merge([$tid], $statuses)
);

// Conversa ativa
$activeConv  = null;
$activeMessages = [];
if ($activeId) {
    $activeConv = db_one('SELECT c.*, a.name AS agent_name FROM conversations c LEFT JOIN agents a ON a.id = c.agent_id WHERE c.id = ? AND c.tenant_id = ?', [$activeId, $tid]);
    if ($activeConv) {
        $activeMessages = db_all('SELECT * FROM messages WHERE conversation_id = ? ORDER BY sent_at ASC', [$activeId]);
    }
}
if (!$activeConv && !empty($convList)) {
    $activeConv     = $convList[0];
    $activeId       = (int)$activeConv['id'];
    $activeMessages = db_all('SELECT * FROM messages WHERE conversation_id = ? ORDER BY sent_at ASC', [$activeId]);
}

// AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    csrf_check();

    $action = $_POST['action'] ?? '';
    $convId = (int)($_POST['conv_id'] ?? 0);

    $conv = $convId ? db_one('SELECT * FROM conversations WHERE id = ? AND tenant_id = ?', [$convId, $tid]) : null;
    if (!$conv) { echo json_encode(['ok'=>false,'error'=>'Conversa não encontrada']); exit; }

    if ($action === 'pause') {
        db_q('UPDATE conversations SET status = "paused" WHERE id = ?', [$convId]);
        echo json_encode(['ok'=>true,'status'=>'paused']);
        exit;
    }

    if ($action === 'resume') {
        db_q('UPDATE conversations SET status = "open" WHERE id = ?', [$convId]);
        echo json_encode(['ok'=>true,'status'=>'open']);
        exit;
    }

    if ($action === 'close') {
        db_q('UPDATE conversations SET status = "closed" WHERE id = ?', [$convId]);
        echo json_encode(['ok'=>true,'status'=>'closed']);
        exit;
    }

    if ($action === 'reply') {
        $text = trim($_POST['text'] ?? '');
        if (!$text) { echo json_encode(['ok'=>false,'error'=>'Mensagem vazia']); exit; }

        // Salva mensagem
        db_q('INSERT INTO messages (conversation_id, direction, content, type, sent_by_human, sent_at) VALUES (?, "out", ?, "text", 1, NOW())', [$convId, $text]);
        db_q('UPDATE conversations SET last_message_at = NOW(), message_count = message_count + 1 WHERE id = ?', [$convId]);

        // Envia via Z-API
        $channel = db_one('SELECT * FROM agent_channels WHERE agent_id = ? AND status = "connected" LIMIT 1', [(int)$conv['agent_id']]);
        $sent = false;
        if ($channel && $conv['contact_phone']) {
            $cfg = zapi_from_channel($channel);
            if ($cfg['instance'] && $cfg['token'] && $cfg['client_token']) {
                zapi_send_text($cfg['instance'], $cfg['token'], $cfg['client_token'], $conv['contact_phone'], $text);
                $sent = true;
            }
        }

        echo json_encode([
            'ok'      => true,
            'sent'    => $sent,
            'msg_id'  => (int) db()->lastInsertId(),
            'content' => $text,
            'time'    => date('d/m H:i'),
        ]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Ação inválida']);
    exit;
}

function ago_inbox(string $dt): string {
    $d = time() - strtotime($dt);
    if ($d < 60)      return 'agora';
    if ($d < 3600)    return floor($d/60) . 'min';
    if ($d < 86400)   return floor($d/3600) . 'h';
    if ($d < 86400*7) return floor($d/86400) . 'd';
    return date('d/m', strtotime($dt));
}

app_layout('Inbox · Handoff', 'inbox', function() use ($convList, $activeConv, $activeMessages, $activeId, $tid, $statusF) {
?>
<style>
.inbox-wrap   { display: flex; height: calc(100vh - 64px); overflow: hidden; max-width: 100%; }
.inbox-list   { width: 300px; min-width: 260px; border-right: 1px solid #e7e5e0; overflow-y: auto; background: #fff; flex-shrink: 0; display: flex; flex-direction: column; }
.inbox-thread { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #f8f7f4; }
.inbox-filters { padding: .6rem .8rem; border-bottom: 1px solid #e7e5e0; display: flex; gap: .4rem; flex-wrap: wrap; }
.filter-btn { font-family: 'Geist Mono', monospace; font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; padding: 4px 10px; border-radius: 99px; border: 1.5px solid #e7e5e0; background: #fff; color: #8b8a93; cursor: pointer; text-decoration: none; transition: all .15s; }
.filter-btn.active, .filter-btn:hover { background: #0ea5e9; border-color: #0ea5e9; color: #fff; }
.inbox-section { font-family: 'Geist Mono', monospace; font-size: .58rem; font-weight: 700; text-transform: uppercase; letter-spacing: .12em; color: #b0adb8; padding: .5rem .8rem .3rem; }
.conv-item { display: flex; align-items: flex-start; gap: .6rem; padding: .75rem .8rem; border-bottom: 1px solid #f5f3ef; cursor: pointer; transition: background .1s; text-decoration: none; color: inherit; }
.conv-item:hover { background: #f8f7f4; }
.conv-item.active { background: #f0f9ff; border-right: 2px solid #0ea5e9; }
.conv-avatar { width: 36px; height: 36px; border-radius: 9px; background: #f0f9ff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: .85rem; color: #0ea5e9; flex-shrink: 0; margin-top: 1px; }
.conv-avatar.paused { background: #fefce8; color: #ca8a04; }
.conv-item-info { flex: 1; min-width: 0; }
.conv-item-name { font-weight: 600; font-size: .82rem; color: #18181b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.conv-item-prev { font-size: .74rem; color: #8b8a93; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.conv-item-meta { display: flex; align-items: center; justify-content: space-between; margin-top: 3px; }
.conv-item-time { font-family: 'Geist Mono', monospace; font-size: .62rem; color: #c0bdc8; }
.s-badge { font-size: .58rem; font-weight: 700; padding: 1px 6px; border-radius: 99px; }
.s-open   { background: #f0fdf4; color: #16a34a; }
.s-paused { background: #fefce8; color: #ca8a04; }
.s-closed { background: #f8fafc; color: #94a3b8; }
/* Thread */
.thread-header { padding: .875rem 1.1rem; background: #fff; border-bottom: 1px solid #e7e5e0; display: flex; align-items: center; gap: .75rem; }
.thread-name { font-weight: 600; font-size: .95rem; color: #18181b; flex: 1; min-width: 0; }
.thread-sub  { font-size: .76rem; color: #8b8a93; font-family: 'Geist Mono', monospace; }
.thread-actions { display: flex; gap: .4rem; flex-shrink: 0; }
.t-btn { padding: .4rem .8rem; font-size: .75rem; font-weight: 600; border-radius: 8px; border: 1.5px solid #e7e5e0; background: #fff; color: #374151; cursor: pointer; font-family: 'Geist Mono', monospace; transition: all .15s; white-space: nowrap; }
.t-btn:hover { border-color: #0ea5e9; color: #0ea5e9; }
.t-btn.danger:hover { border-color: #ef4444; color: #ef4444; }
.t-btn.primary { background: #0ea5e9; border-color: #0ea5e9; color: #fff; }
.t-btn.primary:hover { background: #0284c7; }
.msgs-scroll { flex: 1; overflow-y: auto; padding: 1rem 1.1rem; display: flex; flex-direction: column; gap: .55rem; }
.msg-bubble { display: flex; flex-direction: column; }
.msg-bubble.out { align-items: flex-end; }
.msg-bubble.in  { align-items: flex-start; }
.msg-text { max-width: 72%; padding: .6rem .85rem; border-radius: 12px; font-size: .875rem; line-height: 1.55; word-break: break-word; }
.msg-bubble.out .msg-text { background: #0ea5e9; color: #fff; border-radius: 12px 12px 2px 12px; }
.msg-bubble.in  .msg-text { background: #fff; color: #18181b; border: 1px solid #e7e5e0; border-radius: 12px 12px 12px 2px; }
.msg-bubble.out.human .msg-text { background: #7c3aed; }
.msg-meta { font-size: .67rem; color: #c0bdc8; margin-top: 2px; padding: 0 .3rem; }
.reply-bar { padding: .875rem 1rem; background: #fff; border-top: 1px solid #e7e5e0; display: flex; gap: .6rem; align-items: flex-end; }
.reply-bar textarea { flex: 1; resize: none; border: 1.5px solid #e7e5e0; border-radius: 10px; padding: .6rem .8rem; font-size: .875rem; font-family: inherit; line-height: 1.4; max-height: 120px; outline: none; transition: border-color .15s; }
.reply-bar textarea:focus { border-color: #0ea5e9; }
.reply-btn { padding: .6rem 1.1rem; background: #0ea5e9; color: #fff; border: none; border-radius: 10px; font-size: .85rem; font-weight: 600; cursor: pointer; font-family: 'Geist Mono', monospace; transition: background .15s; white-space: nowrap; }
.reply-btn:hover { background: #0284c7; }
.reply-btn:disabled { opacity: .55; cursor: not-allowed; }
.no-conv { flex: 1; display: flex; align-items: center; justify-content: center; color: #8b8a93; font-size: .875rem; flex-direction: column; gap: .5rem; }
.paused-banner { background: #fefce8; border-bottom: 1px solid #fde68a; padding: .55rem 1.1rem; font-size: .8rem; color: #92400e; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
@media (max-width: 700px) {
  .inbox-list { width: 100%; border-right: none; }
  .inbox-thread { display: none; }
  .inbox-thread.show { display: flex; position: fixed; inset: 0; z-index: 200; }
}
</style>

<div class="inbox-wrap">
  <!-- Lista -->
  <div class="inbox-list">
    <div class="inbox-filters">
      <a href="inbox.php?status=open,paused" class="filter-btn <?= $statusF === 'open,paused' ? 'active' : '' ?>">Ativas</a>
      <a href="inbox.php?status=paused" class="filter-btn <?= $statusF === 'paused' ? 'active' : '' ?>">Pausadas</a>
      <a href="inbox.php?status=open"   class="filter-btn <?= $statusF === 'open' ? 'active' : '' ?>">Bot ativo</a>
      <a href="inbox.php?status=closed" class="filter-btn <?= $statusF === 'closed' ? 'active' : '' ?>">Fechadas</a>
    </div>

    <?php if (empty($convList)): ?>
    <div style="padding:2rem 1rem;text-align:center;color:#8b8a93;font-size:.84rem">
      Nenhuma conversa neste filtro.
    </div>
    <?php else: ?>
    <?php foreach ($convList as $c):
      $name = $c['contact_name'] ?: $c['contact_phone'] ?: '?';
      $init = mb_strtoupper(mb_substr($name, 0, 1));
      $ts   = $c['last_msg_at'] ?? $c['started_at'];
      $isPaused = $c['status'] === 'paused';
    ?>
    <a class="conv-item <?= (int)$c['id'] === $activeId ? 'active' : '' ?>"
       href="inbox.php?id=<?= (int)$c['id'] ?>&status=<?= urlencode($statusF) ?>">
      <div class="conv-avatar <?= $isPaused ? 'paused' : '' ?>"><?= e($init) ?></div>
      <div class="conv-item-info">
        <div class="conv-item-name"><?= e(mb_strimwidth($name, 0, 22, '…')) ?></div>
        <div class="conv-item-prev"><?= e(mb_strimwidth($c['last_msg'] ?? '…', 0, 38, '…')) ?></div>
        <div class="conv-item-meta">
          <span class="conv-item-time"><?= ago_inbox($ts) ?></span>
          <span class="s-badge s-<?= e($c['status']) ?>"><?= match($c['status']) { 'open'=>'Bot','paused'=>'Humano','closed'=>'Fechada',default=>$c['status'] } ?></span>
        </div>
      </div>
    </a>
    <?php endforeach ?>
    <?php endif ?>
  </div>

  <!-- Thread -->
  <div class="inbox-thread" id="inboxThread">
    <?php if ($activeConv): ?>

    <?php if ($activeConv['status'] === 'paused'): ?>
    <div class="paused-banner" id="pausedBanner">
      <span>🙋 Bot pausado — você está no controle desta conversa.</span>
      <button class="t-btn" onclick="resumeBot()">Retomar bot</button>
    </div>
    <?php endif ?>

    <div class="thread-header">
      <div style="min-width:0">
        <div class="thread-name"><?= e($activeConv['contact_name'] ?: $activeConv['contact_phone'] ?: 'Desconhecido') ?></div>
        <div class="thread-sub"><?= e($activeConv['contact_phone'] ?? '') ?> · <?= e($activeConv['agent_name'] ?? '—') ?></div>
      </div>
      <div class="thread-actions">
        <?php if ($activeConv['status'] === 'open'): ?>
        <button class="t-btn primary" onclick="pauseBot()">Pausar bot</button>
        <?php elseif ($activeConv['status'] === 'paused'): ?>
        <button class="t-btn primary" onclick="resumeBot()">Retomar bot</button>
        <?php endif ?>
        <?php if ($activeConv['status'] !== 'closed'): ?>
        <button class="t-btn danger" onclick="closeConv()">Fechar</button>
        <?php endif ?>
      </div>
    </div>

    <div class="msgs-scroll" id="msgsScroll">
      <?php if (empty($activeMessages)): ?>
      <div style="text-align:center;color:#8b8a93;font-size:.84rem;margin-top:2rem">Sem mensagens ainda.</div>
      <?php else: ?>
      <?php foreach ($activeMessages as $m):
        $isOut    = $m['direction'] === 'out';
        $isHuman  = $isOut && !empty($m['sent_by_human']);
        $timeStr  = date('d/m H:i', strtotime($m['sent_at']));
      ?>
      <div class="msg-bubble <?= $isOut ? 'out' : 'in' ?> <?= $isHuman ? 'human' : '' ?>">
        <div class="msg-text"><?= nl2br(e($m['content'])) ?></div>
        <div class="msg-meta"><?= $timeStr ?> · <?= $isHuman ? 'Humano' : ($isOut ? 'Bot' : 'Contato') ?></div>
      </div>
      <?php endforeach ?>
      <?php endif ?>
    </div>

    <?php if ($activeConv['status'] !== 'closed'): ?>
    <div class="reply-bar">
      <textarea id="replyText" rows="2" placeholder="Digite sua mensagem como humano…" onkeydown="handleKey(event)"></textarea>
      <button class="reply-btn" id="replyBtn" onclick="sendReply()">Enviar</button>
    </div>
    <?php else: ?>
    <div style="padding:.875rem 1rem;background:#fff;border-top:1px solid #e7e5e0;text-align:center;font-size:.82rem;color:#8b8a93">
      Conversa fechada.
    </div>
    <?php endif ?>

    <script>
    const CONV_ID   = <?= (int)$activeConv['id'] ?>;
    const CSRF_TOKEN = '<?= csrf_token() ?>';

    function scrollBottom() {
      const el = document.getElementById('msgsScroll');
      if (el) el.scrollTop = el.scrollHeight;
    }
    scrollBottom();

    function handleKey(e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendReply(); }
    }

    async function doAction(action, extra = {}) {
      const fd = new FormData();
      fd.append('action', action);
      fd.append('conv_id', CONV_ID);
      fd.append('_csrf', CSRF_TOKEN);
      for (const [k, v] of Object.entries(extra)) fd.append(k, v);
      const r = await fetch('', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      return r.json();
    }

    async function sendReply() {
      const ta  = document.getElementById('replyText');
      const btn = document.getElementById('replyBtn');
      const text = ta.value.trim();
      if (!text) return;
      btn.disabled = true;
      const res = await doAction('reply', { text });
      btn.disabled = false;
      if (res.ok) {
        ta.value = '';
        appendMsg(text, res.time, true);
        scrollBottom();
      } else {
        alert('Erro: ' + (res.error || 'falha ao enviar'));
      }
    }

    function appendMsg(content, time, isOut) {
      const scroll = document.getElementById('msgsScroll');
      const d = document.createElement('div');
      d.className = 'msg-bubble ' + (isOut ? 'out human' : 'in');
      d.innerHTML = `<div class="msg-text">${escHtml(content)}</div><div class="msg-meta">${time} · ${isOut ? 'Humano' : 'Contato'}</div>`;
      scroll.appendChild(d);
    }

    function escHtml(s) {
      return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
    }

    async function pauseBot() {
      const res = await doAction('pause');
      if (res.ok) location.reload();
    }

    async function resumeBot() {
      const res = await doAction('resume');
      if (res.ok) location.reload();
    }

    async function closeConv() {
      if (!confirm('Fechar esta conversa?')) return;
      const res = await doAction('close');
      if (res.ok) location.href = 'inbox.php';
    }
    </script>

    <?php else: ?>
    <div class="no-conv">
      <div style="font-size:2rem">💬</div>
      <div>Selecione uma conversa</div>
    </div>
    <?php endif ?>
  </div>
</div>
<?php
});
