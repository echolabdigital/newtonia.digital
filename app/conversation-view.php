<?php
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/_layout.php';

$tid  = (int) $tenant['id'];
$id   = (int)($_GET['id'] ?? 0);
$conv = db_one('SELECT c.*, a.name AS agent_name FROM conversations c LEFT JOIN agents a ON a.id = c.agent_id WHERE c.id = ? AND c.tenant_id = ?', [$id, $tid]);
if (!$conv) { header('Location: /app/conversations.php'); exit; }

$messages = db_all('SELECT * FROM messages WHERE conversation_id = ? ORDER BY sent_at ASC', [$id]);

// Fechar conversa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'close') {
    csrf_check();
    db_q('UPDATE conversations SET status = "closed" WHERE id = ?', [$id]);
    $conv['status'] = 'closed';
}

app_layout('Conversa · '.htmlspecialchars($conv['contact_name'] ?: $conv['contact_phone']), 'conversations', function() use ($conv, $messages) {
?>
<div style="max-width:760px;margin:0 auto;padding:2rem 1.5rem">

  <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1.5rem;font-size:.82rem;color:#8b8a93">
    <a href="conversations.php" style="color:#8b8a93;text-decoration:none">Conversas</a>
    <span>&rsaquo;</span>
    <span style="color:#18181b"><?= htmlspecialchars($conv['contact_name'] ?: $conv['contact_phone'] ?: 'Desconhecido') ?></span>
  </div>

  <!-- Header da conversa -->
  <div style="background:#fff;border:1px solid #e7e5e0;border-radius:12px;padding:1.25rem 1.5rem;margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem">
    <div style="display:flex;align-items:center;gap:.85rem">
      <div style="width:44px;height:44px;background:#f0f9ff;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:600;color:#0ea5e9">
        <?= mb_strtoupper(mb_substr($conv['contact_name'] ?: $conv['contact_phone'] ?: '?', 0, 1)) ?>
      </div>
      <div>
        <div style="font-weight:600;font-size:.95rem;color:#18181b"><?= htmlspecialchars($conv['contact_name'] ?: 'Desconhecido') ?></div>
        <div style="font-size:.8rem;color:#8b8a93;display:flex;gap:.75rem;margin-top:.15rem">
          <?php if ($conv['contact_phone']): ?>
          <span style="font-family:'Geist Mono',monospace;font-size:.78rem"><?= htmlspecialchars($conv['contact_phone']) ?></span>
          <?php endif ?>
          <span>Agente: <?= htmlspecialchars($conv['agent_name'] ?? '—') ?></span>
          <span><?= (int)$conv['message_count'] ?> mensagens</span>
        </div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:.6rem">
      <?php
      $statusCfg = match($conv['status']) {
        'open'   => ['bg'=>'#f0fdf4','color'=>'#16a34a','label'=>'Aberta'],
        'closed' => ['bg'=>'#f8fafc','color'=>'#64748b','label'=>'Fechada'],
        'paused' => ['bg'=>'#fefce8','color'=>'#ca8a04','label'=>'Pausada'],
        default  => ['bg'=>'#f8fafc','color'=>'#94a3b8','label'=>$conv['status']],
      };
      ?>
      <span style="font-size:.78rem;font-weight:600;padding:4px 10px;border-radius:99px;background:<?= $statusCfg['bg'] ?>;color:<?= $statusCfg['color'] ?>"><?= $statusCfg['label'] ?></span>
      <?php if ($conv['status'] === 'open'): ?>
      <form method="POST" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="close">
        <button type="submit" style="padding:.45rem .9rem;font-size:.78rem;background:#f8fafc;border:1px solid #e7e5e0;border-radius:8px;color:#64748b;cursor:pointer">Fechar conversa</button>
      </form>
      <?php endif ?>
    </div>
  </div>

  <!-- Mensagens -->
  <div style="display:flex;flex-direction:column;gap:.6rem;margin-bottom:1.5rem">
    <?php if (empty($messages)): ?>
    <div style="text-align:center;padding:2rem;color:#8b8a93;font-size:.875rem">Nenhuma mensagem ainda.</div>
    <?php else: ?>
    <?php foreach ($messages as $msg):
      $isOut = $msg['direction'] === 'out';
      $time  = date('d/m H:i', strtotime($msg['sent_at']));
    ?>
    <div style="display:flex;flex-direction:column;align-items:<?= $isOut ? 'flex-end' : 'flex-start' ?>">
      <div style="max-width:72%;padding:.65rem .9rem;border-radius:<?= $isOut ? '12px 12px 2px 12px' : '12px 12px 12px 2px' ?>;background:<?= $isOut ? '#0ea5e9' : '#fff' ?>;color:<?= $isOut ? '#fff' : '#18181b' ?>;border:<?= $isOut ? 'none' : '1px solid #e7e5e0' ?>;font-size:.875rem;line-height:1.55;word-break:break-word">
        <?= nl2br(htmlspecialchars($msg['content'])) ?>
      </div>
      <div style="font-size:.7rem;color:#8b8a93;margin-top:.2rem;padding:0 .3rem"><?= $time ?> &middot; <?= $isOut ? 'Agente' : 'Contato' ?></div>
    </div>
    <?php endforeach ?>
    <?php endif ?>
  </div>

</div>
<?php });
