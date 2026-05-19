<?php
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/_layout.php';

$tid    = (int) $tenant['id'];
$agents = agent_list($tid);

app_layout('Agentes · SYNAPSE', 'agents', function() use ($agents, $tid) { ?>
<div style="max-width:900px;margin:0 auto;padding:2rem 1.5rem">

  <!-- Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem">
    <div>
      <div style="font-family:'Geist Mono',monospace;font-size:.7rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#0ea5e9;margin-bottom:.4rem">SYNAPSE</div>
      <h1 style="margin:0;font-size:1.6rem;font-weight:600;color:#18181b">Agentes de IA</h1>
      <p style="margin:.3rem 0 0;color:#8b8a93;font-size:.85rem">Crie e gerencie seus agentes conversacionais</p>
    </div>
    <a href="agent-edit.php" style="display:inline-flex;align-items:center;gap:.5rem;background:#0ea5e9;color:#fff;padding:.65rem 1.2rem;border-radius:8px;font-size:.85rem;font-weight:600;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#0284c7'" onmouseout="this.style.background='#0ea5e9'">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
      Novo Agente
    </a>
  </div>

  <?php if (empty($agents)): ?>
  <!-- Empty state -->
  <div style="text-align:center;padding:4rem 2rem;background:#fff;border:1px solid #e7e5e0;border-radius:12px">
    <div style="width:64px;height:64px;background:#f0f9ff;border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 1.2rem">
      <svg width="28" height="28" fill="none" stroke="#0ea5e9" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
    </div>
    <h3 style="margin:0 0 .5rem;font-size:1.1rem;color:#18181b">Nenhum agente criado</h3>
    <p style="margin:0 0 1.5rem;color:#8b8a93;font-size:.9rem">Crie seu primeiro agente e conecte ao WhatsApp em minutos.</p>
    <a href="agent-edit.php" style="display:inline-flex;align-items:center;gap:.5rem;background:#0ea5e9;color:#fff;padding:.65rem 1.4rem;border-radius:8px;font-size:.85rem;font-weight:600;text-decoration:none">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
      Criar primeiro agente
    </a>
  </div>

  <?php else: ?>
  <!-- Agent cards -->
  <div style="display:grid;gap:1rem">
    <?php foreach ($agents as $a):
      $statusColor = match($a['status']) {
        'active'   => ['bg'=>'#f0fdf4','text'=>'#16a34a','dot'=>'#22c55e','label'=>'Ativo'],
        'inactive' => ['bg'=>'#fef2f2','text'=>'#dc2626','dot'=>'#ef4444','label'=>'Inativo'],
        default    => ['bg'=>'#f8fafc','text'=>'#64748b','dot'=>'#94a3b8','label'=>'Rascunho'],
      };
      $channel = agent_channel_get((int)$a['id']);
      $chStatus = $channel ? $channel['status'] : 'none';
      $chPhone  = $channel['connected_phone'] ?? null;
    ?>
    <div style="background:#fff;border:1px solid #e7e5e0;border-radius:12px;padding:1.25rem 1.5rem;display:flex;align-items:center;gap:1.2rem;transition:box-shadow .15s" onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,.06)'" onmouseout="this.style.boxShadow='none'">

      <!-- Ícone -->
      <div style="width:44px;height:44px;background:#f0f9ff;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <svg width="20" height="20" fill="none" stroke="#0ea5e9" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
      </div>

      <!-- Info -->
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.25rem">
          <span style="font-weight:600;font-size:.95rem;color:#18181b"><?= e($a['name']) ?></span>
          <span style="font-size:.7rem;font-weight:600;padding:2px 8px;border-radius:99px;background:<?= $statusColor['bg'] ?>;color:<?= $statusColor['text'] ?>">
            <?= $statusColor['label'] ?>
          </span>
        </div>
        <div style="font-size:.8rem;color:#8b8a93;display:flex;gap:1rem;flex-wrap:wrap">
          <span><?= (int)$a['conv_total'] ?> conversas</span>
          <span><?= (int)$a['conv_open'] ?> abertas</span>
          <span style="font-family:'Geist Mono',monospace;font-size:.72rem"><?= e($a['model']) ?></span>
          <?php if ($chStatus === 'connected' && $chPhone): ?>
          <span style="color:#0ea5e9">📱 <?= e($chPhone) ?></span>
          <?php elseif ($channel): ?>
          <span style="color:#f59e0b">⚠ Canal desconectado</span>
          <?php else: ?>
          <span style="color:#94a3b8">Sem canal</span>
          <?php endif ?>
        </div>
      </div>

      <!-- Ações -->
      <div style="display:flex;gap:.5rem;flex-shrink:0">
        <a href="agent-test.php?id=<?= (int)$a['id'] ?>" title="Testar" style="display:flex;align-items:center;justify-content:center;width:36px;height:36px;background:#f0f9ff;border-radius:8px;color:#0ea5e9;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#e0f2fe'" onmouseout="this.style.background='#f0f9ff'">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M8 12h8M8 8h8M8 16h5"/><rect x="3" y="3" width="18" height="18" rx="3"/></svg>
        </a>
        <a href="conversations.php?agent_id=<?= (int)$a['id'] ?>" title="Conversas" style="display:flex;align-items:center;justify-content:center;width:36px;height:36px;background:#f8fafc;border-radius:8px;color:#64748b;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#f8fafc'">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
        </a>
        <a href="agent-edit.php?id=<?= (int)$a['id'] ?>" title="Editar" style="display:flex;align-items:center;justify-content:center;width:36px;height:36px;background:#f8fafc;border-radius:8px;color:#64748b;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#f8fafc'">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </a>
      </div>
    </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>
<?php });
