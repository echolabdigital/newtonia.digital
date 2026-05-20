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

// Transferir pra humano (gera resumo IA + dispara webhook handoff.requested)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'handoff') {
    csrf_check();
    synapse_trigger_handoff($tid, $conv, 'manual');
    header('Location: /app/conversation-view.php?id=' . $id);
    exit;
}

// Regenerar resumo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'summarize') {
    csrf_check();
    conv_summarize($id);
    header('Location: /app/conversation-view.php?id=' . $id);
    exit;
}

// Qualificacao SPIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'spin') {
    csrf_check();
    pulse_qualify_spin($id);
    header('Location: /app/conversation-view.php?id=' . $id);
    exit;
}

$summary = conv_summary_get($id);
$tags    = conv_tags_get($id);
$spin    = pulse_spin_get($id);

app_layout('Conversa · '.htmlspecialchars($conv['contact_name'] ?: $conv['contact_phone']), 'conversations', function() use ($conv, $messages, $summary, $tags, $spin) {
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
        'human'  => ['bg'=>'#faf5ff','color'=>'#7c3aed','label'=>'Com humano'],
        default  => ['bg'=>'#f8fafc','color'=>'#94a3b8','label'=>$conv['status']],
      };
      ?>
      <span style="font-size:.78rem;font-weight:600;padding:4px 10px;border-radius:99px;background:<?= $statusCfg['bg'] ?>;color:<?= $statusCfg['color'] ?>"><?= $statusCfg['label'] ?></span>
      <?php if ($conv['status'] !== 'closed' && $conv['status'] !== 'human'): ?>
      <form method="POST" style="display:inline" onsubmit="return confirm('Transferir pra humano? Newton vai gerar um resumo da conversa e enviar via webhook.')">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="handoff">
        <button type="submit" style="padding:.45rem .9rem;font-size:.78rem;background:#7c3aed;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer">→ Pra humano</button>
      </form>
      <a href="inbox.php?id=<?= (int)$conv['id'] ?>" style="padding:.45rem .9rem;font-size:.78rem;background:#0ea5e9;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:.35rem">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
        Assumir conversa
      </a>
      <?php endif ?>
      <?php if ($conv['status'] === 'open'): ?>
      <form method="POST" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="close">
        <button type="submit" style="padding:.45rem .9rem;font-size:.78rem;background:#f8fafc;border:1px solid #e7e5e0;border-radius:8px;color:#64748b;cursor:pointer">Fechar conversa</button>
      </form>
      <?php endif ?>
    </div>
  </div>

  <!-- Resumo IA + Tags -->
  <?php
    $sentCfg = [
      'positive' => ['bg'=>'#dcfce7','color'=>'#15803d','label'=>'😊 Positivo'],
      'neutral'  => ['bg'=>'#e0f2fe','color'=>'#0369a1','label'=>'😐 Neutro'],
      'negative' => ['bg'=>'#fee2e2','color'=>'#b91c1c','label'=>'😞 Negativo'],
      'urgent'   => ['bg'=>'#fef3c7','color'=>'#92400e','label'=>'⚠ Urgente'],
    ];
  ?>
  <?php if ($summary || $tags): ?>
  <div style="background:linear-gradient(135deg,#f0f9ff,#eff6ff);border:1px solid #bae6fd;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.5rem">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.5rem;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:.5rem">
        <span style="font-size:.72rem;font-weight:700;color:#0369a1;letter-spacing:.05em">🧠 RESUMO IA</span>
        <?php if ($summary):
          $sc = $sentCfg[$summary['sentiment']] ?? $sentCfg['neutral']; ?>
          <span style="font-size:.7rem;padding:.15rem .55rem;border-radius:99px;background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;font-weight:600"><?= $sc['label'] ?></span>
        <?php endif ?>
        <?php foreach ($tags as $tag): ?>
          <span style="font-size:.66rem;padding:.15rem .5rem;border-radius:99px;background:#fef3c7;color:#92400e;font-weight:600;font-family:'Geist Mono',monospace">#<?= htmlspecialchars($tag) ?></span>
        <?php endforeach ?>
      </div>
      <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="_action" value="summarize"><button type="submit" style="font-size:.7rem;background:transparent;border:1px solid #bae6fd;color:#0369a1;padding:.25rem .65rem;border-radius:6px;cursor:pointer">↻ Atualizar resumo</button></form>
    </div>
    <?php if ($summary): ?>
      <div style="font-size:.88rem;line-height:1.55;color:#18181b;margin-bottom:.55rem"><?= htmlspecialchars($summary['summary']) ?></div>
      <?php if (!empty($summary['intent'])): ?>
        <div style="font-size:.78rem;color:#475569"><b>Intencao:</b> <?= htmlspecialchars($summary['intent']) ?></div>
      <?php endif ?>
      <?php if (!empty($summary['next_step'])): ?>
        <div style="font-size:.78rem;color:#475569;margin-top:.2rem"><b>Proximo passo:</b> <?= htmlspecialchars($summary['next_step']) ?></div>
      <?php endif ?>
    <?php else: ?>
      <div style="font-size:.82rem;color:#64748b">Resumo nao gerado. Use o botao "Atualizar resumo" para criar.</div>
    <?php endif ?>
  </div>
  <?php endif ?>

  <!-- SPIN Qualification -->
  <?php
    $tempCfg = [
      'cold' => ['#dbeafe','#1d4ed8','❄ Cold'],
      'warm' => ['#fef3c7','#92400e','🌤 Warm'],
      'hot'  => ['#fee2e2','#b91c1c','🔥 Hot'],
    ];
  ?>
  <div style="background:linear-gradient(135deg,#fdf4ff,#fef3c7);border:1px solid #fde68a;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.5rem">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.5rem;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:.5rem">
        <span style="font-size:.72rem;font-weight:700;color:#92400e;letter-spacing:.05em">🎯 SPIN SELLING</span>
        <?php if ($spin):
          $tc = $tempCfg[$spin['temperature']] ?? $tempCfg['cold']; ?>
          <span style="font-size:.7rem;padding:.15rem .55rem;border-radius:99px;background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;font-weight:600"><?= $tc[2] ?></span>
          <span style="font-size:.78rem;color:#475569"><b>Score:</b> <?= (int)$spin['score'] ?>/100</span>
        <?php endif ?>
      </div>
      <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="_action" value="spin"><button type="submit" style="font-size:.7rem;background:transparent;border:1px solid #fde68a;color:#92400e;padding:.25rem .65rem;border-radius:6px;cursor:pointer">↻ <?= $spin ? 'Atualizar' : 'Qualificar agora' ?></button></form>
    </div>
    <?php if ($spin): ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem .9rem;font-size:.78rem;line-height:1.5;color:#18181b">
        <div><b>Situacao:</b> <?= htmlspecialchars($spin['situation'] ?: '—') ?></div>
        <div><b>Problema:</b> <?= htmlspecialchars($spin['problem'] ?: '—') ?></div>
        <div><b>Implicacao:</b> <?= htmlspecialchars($spin['implication'] ?: '—') ?></div>
        <div><b>Need-payoff:</b> <?= htmlspecialchars($spin['need_payoff'] ?: '—') ?></div>
      </div>
      <?php if ($spin['next_step']): ?>
        <div style="font-size:.82rem;color:#7c2d12;margin-top:.5rem;font-weight:600">→ Proximo passo: <?= htmlspecialchars($spin['next_step']) ?></div>
      <?php endif ?>
    <?php else: ?>
      <div style="font-size:.82rem;color:#78350f">Clique em <b>Qualificar agora</b> para a IA analisar essa conversa pelo metodo SPIN Selling (Situation, Problem, Implication, Need-payoff).</div>
    <?php endif ?>
  </div>

  <!-- Mensagens -->
  <div style="display:flex;flex-direction:column;gap:.6rem;margin-bottom:1.5rem">
    <?php if (empty($messages)): ?>
    <div style="text-align:center;padding:2rem;color:#8b8a93;font-size:.875rem">Nenhuma mensagem ainda.</div>
    <?php else: ?>
    <?php foreach ($messages as $msg):
      $isOut    = $msg['direction'] === 'out';
      $isHuman  = $isOut && !empty($msg['sent_by_human']);
      $bg       = $isOut ? ($isHuman ? '#7c3aed' : '#0ea5e9') : '#fff';
      $time     = date('d/m H:i', strtotime($msg['sent_at']));
      $sender   = $isOut ? ($isHuman ? 'Humano' : 'Agente') : 'Contato';
    ?>
    <div style="display:flex;flex-direction:column;align-items:<?= $isOut ? 'flex-end' : 'flex-start' ?>">
      <div style="max-width:72%;padding:.65rem .9rem;border-radius:<?= $isOut ? '12px 12px 2px 12px' : '12px 12px 12px 2px' ?>;background:<?= $bg ?>;color:<?= $isOut ? '#fff' : '#18181b' ?>;border:<?= $isOut ? 'none' : '1px solid #e7e5e0' ?>;font-size:.875rem;line-height:1.55;word-break:break-word">
        <?= nl2br(htmlspecialchars($msg['content'])) ?>
      </div>
      <div style="font-size:.7rem;color:#8b8a93;margin-top:.2rem;padding:0 .3rem"><?= $time ?> &middot; <?= $sender ?></div>
    </div>
    <?php endforeach ?>
    <?php endif ?>
  </div>

</div>
<?php });
