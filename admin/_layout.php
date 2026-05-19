<?php
/**
 * Newton IA — Admin layout wrapper
 * Usage: admin_layout(string $title, string $active, callable $body)
 *
 * Side effect: o require_once dispara plans_ensure_hermes_schema_disabled___() pra garantir
 * que todas as colunas da tabela `plans` existam ANTES de qualquer query admin.
 */

// Migration eager: roda imediatamente ao incluir este arquivo
@require_once __DIR__ . '/../core/plans.php';
if (function_exists('plans_ensure_hermes_schema_disabled___')) {
    try { plans_ensure_hermes_schema_disabled___(); } catch (\Throwable $e) { error_log('[admin migration] ' . $e->getMessage()); }
}

function admin_layout(string $title, string $active, callable $body): void
{
    $nav = [
        ['k' => 'overview',    'label' => 'Visão Geral',    'href' => 'index.php',        'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        ['type'=>'sec', 'label'=>'Comercial'],
        ['k' => 'tenants',     'label' => 'Tenants',        'href' => 'tenants.php',      'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
        ['k' => 'plans',       'label' => 'Planos',         'href' => 'plans.php',        'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
        ['k' => 'cnpj-limits', 'label' => 'Limites CNPJ',   'href' => 'cnpj-limits.php',  'icon' => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4'],
        ['type'=>'sec', 'label'=>'Integrações'],
        ['k' => 'integrations','label' => 'APIs · Webhooks','href' => 'integrations.php', 'icon' => 'M13 10V3L4 14h7v7l9-11h-7z'],
        ['k' => 'zapi',        'label' => 'Z-API Pool',     'href' => '#',                'icon' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z', 'soon'=>true],
        ['type'=>'sec', 'label'=>'Sistema'],
        ['k' => 'audit',       'label' => 'Auditoria',      'href' => 'audit.php',        'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-2.962z'],
    ];

    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title><?= htmlspecialchars($title) ?> · Newton IA · Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --indigo:#3d3dff; --hermes:#0ea5e9; --coral:#be123c;
    --ink:#18181b; --mute:#8b8a93; --line:#e7e5e0; --paper:#fafaf7; --bone:#f6f4ef;
    --cr: var(--hermes); --cr-glow: rgba(14,165,233,.08);
    --ink-3: var(--mute);
    --sidebar-w: 230px;
  }
  body  { font-family: 'Geist', system-ui, -apple-system, sans-serif; background: var(--bone); color: var(--ink); display: flex; min-height: 100vh; -webkit-font-smoothing: antialiased; letter-spacing: -0.01em; }
  code, pre, .mono { font-family: 'Geist Mono', monospace; }

  /* Sidebar HERMES — branco com accent verde (alinhado ao app/_layout.php) */
  .adm-side { width: var(--sidebar-w); background: #fff; color: var(--ink-2, #3a3a40); border-right: 1px solid var(--line); display: flex; flex-direction: column; padding: 18px 0; flex-shrink: 0; }
  .adm-logo { padding: 0 20px 18px; border-bottom: 1px solid var(--line); margin-bottom: 14px; display: flex; align-items: center; gap: 10px; }
  .adm-logo-box { width: 36px; height: 36px; border-radius: 9px; background: var(--hermes); color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 1px 3px rgba(14,165,233,.25); transition: background .15s; }
  .adm-logo-box:hover { background: #0ea371; }
  .adm-logo-text { font-family: 'Geist Mono', monospace; font-weight: 700; font-size: .95rem; color: var(--ink); line-height: 1.1; letter-spacing: -0.01em; }
  .adm-logo-text .b2b { color: var(--hermes); font-size: .76em; font-weight: 600; }
  .adm-logo-text small { display: block; font-family: 'Geist Mono', monospace; font-size: .58rem; font-weight: 600; color: var(--hermes); margin-top: 3px; letter-spacing: .08em; text-transform: uppercase; }
  nav a { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: .85rem; color: var(--ink-2, #3a3a40); text-decoration: none; transition: all .15s; font-weight: 500; position: relative; }
  nav a:hover   { background: var(--bone); color: var(--ink); }
  nav a.active  { background: var(--cr-glow); color: var(--hermes); font-weight: 600; }
  nav a.active::before { content: ''; position: absolute; left: 0; top: 8px; bottom: 8px; width: 3px; background: var(--hermes); border-radius: 0 99px 99px 0; }
  nav svg { width: 17px; height: 17px; flex-shrink: 0; stroke-width: 1.8; }
  nav .sec { padding: 14px 20px 6px; font-family: 'Geist Mono', monospace; font-size: .58rem; color: var(--mute); text-transform: uppercase; letter-spacing: .12em; font-weight: 600; }
  nav .sec::before { content: '// '; opacity: .5; }
  nav a.soon { opacity: .5; cursor: not-allowed; }
  nav a.soon:hover { background: transparent; color: var(--mute); }
  nav .nav-soon { font-family: 'Geist Mono', monospace; font-size: .54rem; padding: 2px 6px; background: var(--bone); color: var(--mute); border: 1px solid var(--line); border-radius: 4px; letter-spacing: .04em; font-weight: 600; text-transform: uppercase; }

  /* Main */
  .adm-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
  .adm-header { background: #fff; border-bottom: 1px solid var(--line); padding: 14px 28px; display: flex; align-items: center; justify-content: space-between; }
  .adm-header h1 { font-size: 1rem; font-weight: 600; color: var(--ink); }
  .adm-header a { font-size: .82rem; color: var(--mute); text-decoration: none; }
  .adm-header a:hover { color: var(--hermes); }
  .adm-content { padding: 26px 28px; overflow-y: auto; flex: 1; }

  /* Componentes utilitários compartilhados (panel, table, badge, etc.) */
  .panel { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 20px; margin-bottom: 16px; }
  .panel h2 { font-size: 1rem; font-weight: 700; margin-bottom: 12px; color: var(--ink); }
  table { width: 100%; border-collapse: collapse; }
  table th { padding: 10px 12px; text-align: left; font-family: 'Geist Mono', monospace; font-size: .64rem; font-weight: 600; color: var(--mute); text-transform: uppercase; letter-spacing: .06em; border-bottom: 1px solid var(--line); background: var(--bone); }
  table td { padding: 11px 12px; border-bottom: 1px solid var(--line); font-size: .85rem; }
  table tr:last-child td { border-bottom: none; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-family: 'Geist Mono', monospace; font-size: .6rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
  .badge-active { background: #dcfce7; color: #166534; }
  .badge-pending { background: #fef3c7; color: #92400e; }
  .badge-suspended, .badge-cancelled { background: #f3f4f6; color: var(--mute); }
  .btn-action { padding: 8px 14px; background: var(--hermes); color: #fff; border: none; border-radius: 7px; font-size: .82rem; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-family: inherit; transition: background .15s; }
  .btn-action:hover { background: #0ea371; color: #fff; }
  .btn-action.secondary { background: #fff; color: var(--ink); border: 1px solid var(--line); }
  .btn-action.secondary:hover { background: var(--bone); border-color: var(--mute); color: var(--ink); }
  label { font-family: 'Geist Mono', monospace; font-size: .64rem; color: var(--mute); text-transform: uppercase; letter-spacing: .06em; font-weight: 600; display: block; margin-bottom: 4px; }
  input[type=text], input[type=number], input[type=email], select, textarea { padding: 8px 12px; border: 1px solid var(--line); border-radius: 7px; font-size: .86rem; font-family: inherit; background: #fff; color: var(--ink); width: 100%; }
  input:focus, select:focus, textarea:focus { outline: none; border-color: var(--hermes); box-shadow: 0 0 0 3px var(--cr-glow); }
  .field { display: flex; flex-direction: column; gap: 4px; }
  .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
  .row-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 14px; }
  @media (max-width: 880px) { .row-2, .row-3 { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<aside class="adm-side">
  <a href="index.php" class="adm-logo" style="text-decoration:none">
    <div class="adm-logo-box">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 7l5 5-5 5"/><line x1="13" y1="17" x2="20" y2="17"/>
      </svg>
    </div>
    <div class="adm-logo-text">Newton IA<small>Super Admin · echo_lab</small></div>
  </a>
  <nav>
    <?php foreach ($nav as $item): ?>
      <?php if (($item['type'] ?? '') === 'sec'): ?>
        <div class="sec"><?= htmlspecialchars($item['label']) ?></div>
      <?php else:
        $isSoon = !empty($item['soon']);
      ?>
        <a href="<?= htmlspecialchars($item['href']) ?>"
           class="<?= $active === $item['k'] ? 'active' : '' ?> <?= $isSoon ? 'soon' : '' ?>"
           <?= $isSoon ? 'onclick="event.preventDefault();return false"' : '' ?>>
          <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="<?= $item['icon'] ?>"/>
          </svg>
          <span style="flex:1"><?= htmlspecialchars($item['label']) ?></span>
          <?php if ($isSoon): ?><span class="nav-soon">Em breve</span><?php endif; ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
</aside>

<div class="adm-main">
  <header class="adm-header">
    <h1><?= htmlspecialchars($title) ?></h1>
    <a href="/logout.php" style="font-size:.82rem;color:#6b7280;text-decoration:none">Sair</a>
  </header>
  <div class="adm-content">
    <?php $body(); ?>
  </div>
</div>

</body>
</html>
    <?php
}
