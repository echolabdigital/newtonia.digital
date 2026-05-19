<?php
/**
 * Layout do painel TENANT (white-label).
 * Aplica cor primária e nome do tenant via tenant_brand().
 *
 * Uso:
 *   app_layout('Título', 'crm', function() { ?> conteúdo HTML <?php });
 */

function app_layout(string $title, string $active, callable $body): void {
    $brand  = tenant_brand();
    $tenant = tenant_current();

    // Newton IA — módulos SYNAPSE
    $items = [
        ['k'=>'overview', 'label'=>'Dashboard', 'href'=>'index.php',
            'icon'=>'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],

        ['type'=>'section', 'label'=>'SYNAPSE'],

        ['k'=>'agents', 'label'=>'Agentes', 'href'=>'agents.php',
            'icon'=>'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2-2v10a2 2 0 002 2z',
            'sub'=>'Seus agentes de IA',
            'color'=>'#0ea5e9'],

        ['k'=>'conversations', 'label'=>'Conversas', 'href'=>'conversations.php',
            'icon'=>'M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z',
            'sub'=>'Histórico · WhatsApp',
            'color'=>'#38bdf8'],

        ['type'=>'section', 'label'=>'CONFIG'],

        ['k'=>'config', 'label'=>'Configurações', 'href'=>'configuracoes.php',
            'icon'=>'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z'],

        ['k'=>'billing', 'label'=>'Plano', 'href'=>'billing.php',
            'icon'=>'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
    ];

    $userName   = e(auth_user_name() ?: $brand['name']);
    $initial    = strtoupper(mb_substr($brand['name'], 0, 1));
    $brandColor = e($brand['color']);
    $isSuper    = auth_is_super();

    // ── Onboarding redirect ──────────────────────────────────────────────────
    // Se o tenant tem plano ativo (status=active) e o usuário nunca completou
    // o onboarding, redireciona uma vez — exceto se já está na página.
    $current_page = basename($_SERVER['PHP_SELF'] ?? '');
    if ($current_page !== 'onboarding.php' && !$isSuper) {
        $uid_ob = (int) auth_user_id();
        if ($uid_ob > 0) {
            $ob_done = user_pref_get($uid_ob, 'onboarding_completed', '0');
            if ($ob_done !== '1') {
                $t_status = $tenant['status'] ?? '';
                // Só redireciona se tenant está ativo (pagou)
                if ($t_status === 'active') {
                    header('Location: /app/onboarding.php');
                    exit;
                }
            }
        }
    }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title><?= e($title) ?> — Newton IA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<!-- Echo_Lab Tech — fonte oficial Geist (primary + mono) -->
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@100;200;300;400;500;600;700;800;900&family=Geist+Mono:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
  /* Echo_Lab Tech — palette oficial */
  --indigo:    #3d3dff;  /* Indigo Elétrico — primary, master (95% das aplicações) */
  --coral:     #be123c;  /* Coral Sinal — alertas, CTAs, estados ativos */
  --hermes:    #0ea5e9;  /* Cor do produto NEWTON IA (sky blue) */

  /* Neutros LIGHT (warm grays — não puro preto) */
  --ink:       #18181b;  /* texto body */
  --mute:      #8b8a93;  /* texto secundário */
  --line:      #e7e5e0;  /* bordas */
  --paper:     #fafaf7;  /* card surface */
  --bone:      #f6f4ef;  /* página bg */

  /* Alias --cr aponta para HERMES (a cor do PRODUTO).
     Indigo Elétrico permanece como token da marca Echo_Lab (usado pontualmente). */
  --cr:        var(--hermes);
  --cr-glow:   rgba(14, 165, 233, 0.08);
  --ink-2:     #3a3a40;
  --ink-3:     var(--mute);
  --fog:       var(--bone);
  --white:     #fff;
  --border:    var(--line);

  /* Tipografia — escala Echo_Lab (14 níveis) */
  --fs-display-hero:  88px;
  --fs-display-lg:    56px;
  --fs-display-md:    40px;
  --fs-heading-lg:    28px;
  --fs-heading-md:    22px;
  --fs-heading-sm:    18px;
  --fs-body-lg:       16px;
  --fs-body-md:       14px;
  --fs-body-sm:       13px;
  --fs-mono:          13px;

  /* Tenant override (se houver branding white-label) */
  --tenant-cr:        <?= $brandColor ?>;
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Geist', system-ui, -apple-system, sans-serif; background: var(--bone); color: var(--ink); height: 100vh; display: flex; overflow: hidden; -webkit-font-smoothing: antialiased; font-feature-settings: 'cv11', 'ss01', 'ss03'; letter-spacing: -0.01em; }
code, pre, .mono, [data-mono] { font-family: 'Geist Mono', 'JetBrains Mono', Consolas, monospace; }

/* SIDEBAR DISCRETA — colapsada por default (apenas ícones), expande no hover */
.sidebar {
    width: 60px; background: var(--white); border-right: 1px solid var(--border);
    display: flex; flex-direction: column; padding: .9rem .6rem; flex-shrink: 0;
    transition: width .22s ease;
    overflow: hidden;
    z-index: 30;
}
.sidebar:hover { width: 220px; box-shadow: 4px 0 24px rgba(0,0,0,.06); }
.sidebar.pinned { width: 220px; }

.brand { display: flex; align-items: center; gap: .65rem; text-decoration: none; margin-bottom: 1.2rem; padding: 0 .25rem; }
/* Ícone echo_lab carrega a cor do produto que ele representa = HERMES (verde esmeralda) */
.logo-box { width: 36px; height: 36px; border-radius: 9px; background: var(--hermes); display: flex; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; transition: background .15s; box-shadow: 0 1px 3px rgba(14, 165, 233, .25); }
.logo-box:hover { background: #0ea371; }
.logo-text { font-family: 'Geist Mono', monospace; font-weight: 700; font-size: .95rem; color: var(--ink); letter-spacing: -0.01em; line-height: 1.1; white-space: nowrap; opacity: 0; transition: opacity .15s; }
.logo-b2b  { font-family: 'Geist Mono', monospace; font-weight: 600; font-size: .76em; color: var(--hermes); letter-spacing: 0; margin-left: 1px; }
/* Botão "Conheça →" abaixo da brand — só aparece com sidebar expandida */
.logo-about { display: inline-block; margin-left: 48px; margin-top: -8px; margin-bottom: 12px; font-family: 'Geist Mono', monospace; font-size: .62rem; color: var(--mute); text-decoration: none; letter-spacing: .04em; opacity: 0; transition: opacity .15s, color .12s; padding: 3px 7px; border-radius: 4px; }
.sidebar:hover .logo-about, .sidebar.pinned .logo-about { opacity: 1; }
.logo-about:hover { color: var(--hermes); background: rgba(16,185,129,.08); }
.logo-sub  { font-family: 'Geist Mono', monospace; font-size: .56rem; color: var(--hermes); font-weight: 600; letter-spacing: .08em; text-transform: uppercase; margin-top: .15rem; white-space: nowrap; opacity: 0; transition: opacity .15s; }
.sidebar:hover .logo-text, .sidebar:hover .logo-sub, .sidebar.pinned .logo-text, .sidebar.pinned .logo-sub { opacity: 1; }

.nav-menu  { display: flex; flex-direction: column; gap: .2rem; flex: 1; overflow-y: auto; overflow-x: hidden; min-height: 0; }
.nav-menu::-webkit-scrollbar { width: 4px; }
.nav-menu::-webkit-scrollbar-thumb { background: var(--line); border-radius: 99px; }
.nav-item  { display: flex; align-items: center; gap: .75rem; padding: .6rem .65rem; border-radius: 8px; color: var(--ink-2); text-decoration: none; font-size: .8rem; font-weight: 500; transition: all .15s; position: relative; white-space: nowrap; }
.nav-item:hover { background: var(--bone); color: var(--ink); }
.nav-item.active { background: var(--cr-glow); color: var(--cr); }

/* Itens com cor de produto: ícone sempre na cor do módulo (variação verde HERMES) */
.nav-item.has-color svg { color: var(--item-color); }
.nav-item.has-color:hover { background: color-mix(in srgb, var(--item-color) 8%, var(--bone)); }
.nav-item.has-color.active { background: color-mix(in srgb, var(--item-color) 12%, transparent); color: var(--item-color); }
.nav-item.has-color.active::before { background: var(--item-color); }
.nav-item.has-color.active .nav-sub { color: var(--item-color); }

/* Faixa lateral indicando item ativo */
.nav-item.active::before { content: ''; position: absolute; left: -2px; top: 8px; bottom: 8px; width: 3px; background: var(--hermes); border-radius: 99px; }

.nav-item.soon { opacity: .55; cursor: not-allowed; }
.nav-item.soon:hover { background: transparent; color: var(--ink-2); }
.nav-item.soon.has-color:hover { background: transparent; }
.nav-item .badge-soon { margin-left: auto; font-family: 'Geist Mono', monospace; font-size: .56rem; font-weight: 600; background: var(--mute); color: #fff; padding: 2px 6px; border-radius: 4px; letter-spacing: .04em; opacity: 0; transition: opacity .15s; text-transform: uppercase; }
.sidebar:hover .badge-soon, .sidebar.pinned .badge-soon { opacity: 1; }
.nav-item svg { width: 18px; height: 18px; flex-shrink: 0; stroke-width: 1.8; }
.nav-item span:not(.badge-soon) { opacity: 0; transition: opacity .15s; }
.sidebar:hover .nav-item span, .sidebar.pinned .nav-item span { opacity: 1; }

/* Sub-label dos módulos HERMES (Prospecção, Gestão comercial, etc.) */
.nav-label    { display: flex; flex-direction: column; line-height: 1.15; min-width: 0; }
.nav-main     { font-weight: 500; font-size: .82rem; letter-spacing: -0.01em; }
.nav-sub      { font-family: 'Geist Mono', monospace; font-size: .58rem; color: var(--mute); font-weight: 500; margin-top: 2px; opacity: 0; transition: opacity .15s; letter-spacing: .02em; }
.sidebar:hover .nav-sub, .sidebar.pinned .nav-sub { opacity: .85; }
.nav-item.active .nav-main { font-weight: 600; }
.nav-item.active .nav-sub  { color: var(--hermes); opacity: 1; }

/* Section headers: HERMES / CONFIG (estilo terminal Geist Mono) */
.nav-section  { font-family: 'Geist Mono', monospace; padding: .9rem .65rem .35rem; font-size: .58rem; font-weight: 600; color: var(--mute); letter-spacing: .14em; text-transform: uppercase; opacity: 0; transition: opacity .15s; white-space: nowrap; }
.nav-section::before { content: '// '; opacity: .5; }
.sidebar:hover .nav-section, .sidebar.pinned .nav-section { opacity: 1; }

.sidebar-toggle { background: none; border: none; padding: .4rem .65rem; color: var(--ink-3); cursor: pointer; font-size: 1rem; display: flex; align-items: center; justify-content: center; border-radius: 8px; margin-top: .4rem; }
.sidebar-toggle:hover { background: var(--fog); color: var(--ink); }

.sidebar-footer { border-top: 1px solid var(--border); padding-top: .6rem; margin-top: auto; flex-shrink: 0; background: var(--white); }
.user-profile { display: flex; align-items: center; gap: .65rem; padding: .35rem; }
/* Logout sempre visível: ícone quando sidebar colapsada, texto quando expandida */
.logout-row { display: flex; align-items: center; gap: 8px; padding: 8px 9px; border-radius: 8px; color: var(--ink-3); text-decoration: none; margin-top: 4px; transition: all .15s; }
.logout-row:hover { background: var(--coral); color: #fff; }
.logout-row svg { width: 16px; height: 16px; flex-shrink: 0; stroke-width: 2; }
.logout-row .logout-text { font-size: .8rem; font-weight: 600; opacity: 0; transition: opacity .15s; white-space: nowrap; }
.sidebar:hover .logout-row .logout-text, .sidebar.pinned .logout-row .logout-text { opacity: 1; }
.avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--ink); color: #fff; display: flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 700; flex-shrink: 0; }
.user-info { display: flex; flex-direction: column; min-width: 0; opacity: 0; transition: opacity .15s; }
.sidebar:hover .user-info, .sidebar.pinned .user-info { opacity: 1; }
.user-name { font-size: .78rem; font-weight: 700; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-plan { font-family: 'Geist Mono', monospace; font-size: .62rem; color: var(--cr); font-weight: 600; text-transform: uppercase; letter-spacing: .08em; }
.logout-btn { display: block; text-align: center; margin-top: .4rem; padding: .35rem; font-size: .7rem; color: var(--ink-3); text-decoration: none; font-weight: 600; border-radius: 6px; opacity: 0; transition: opacity .15s; }
.sidebar:hover .logout-btn, .sidebar.pinned .logout-btn { opacity: 1; }
.logout-btn:hover { color: var(--cr); background: var(--fog); }

/* HEADER MAIS DISCRETO */
.main   { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.header { height: 44px; background: var(--white); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 1.2rem; flex-shrink: 0; }
.header-title { font-size: .92rem; font-weight: 700; color: var(--ink-2); }
.header-actions { display: flex; align-items: center; gap: .6rem; }
.viewport { flex: 1; padding: 1.2rem 1.4rem; overflow-y: auto; background: var(--fog); }

.super-bar { background: var(--ink); color: #fff; padding: .35rem 1.2rem; font-size: .68rem; font-weight: 500; display: flex; justify-content: space-between; align-items: center; }
.super-bar a { color: #fff; opacity: .7; text-decoration: none; }
.super-bar a:hover { opacity: 1; text-decoration: underline; }

.btn-action { padding: .6rem 1.2rem; background: var(--ink); color: #fff; border: none; border-radius: 8px; font-family: inherit; font-size: .8rem; font-weight: 600; cursor: pointer; transition: background .2s; display: inline-flex; align-items: center; gap: .5rem; text-decoration: none; }
.btn-action:hover { background: var(--cr); }
.btn-action.secondary { background: var(--white); color: var(--ink-2); border: 1px solid var(--border); }
.btn-action.secondary:hover { background: var(--fog); color: var(--ink); }

.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; }
.stat-card { background: var(--white); border: 1px solid var(--border); border-radius: 16px; padding: 1.5rem; }
.stat-icon { width: 40px; height: 40px; border-radius: 10px; background: var(--cr-glow); color: var(--cr); display: flex; align-items: center; justify-content: center; margin-bottom: 1rem; }
.stat-val { font-size: 2rem; font-weight: 800; line-height: 1; margin-bottom: .25rem; }
.stat-label { font-size: .8rem; font-weight: 500; color: var(--ink-3); }

.panel { background: var(--white); border: 1px solid var(--border); border-radius: 16px; padding: 1.5rem; margin-bottom: 1.5rem; }
.panel h2 { font-size: 1rem; font-weight: 700; margin-bottom: 1rem; }
</style>
</head>
<body>

<?php
// Itens pra bottom nav: até 5 primeiros não-soon, não-section
$bottomItems = array_slice(
    array_values(array_filter($filtered, fn($i) => ($i['type'] ?? '') !== 'section' && empty($i['soon']))),
    0, 5
);
?>

<!-- ── Mobile Top Bar ─────────────────────────────────────────── -->
<div class="mob-topbar">
  <button class="mob-hamburger" onclick="mobDrawerOpen()" aria-label="Menu">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>
  <div class="mob-brand-logo">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
      <path d="M5 7l5 5-5 5"/><line x1="13" y1="17" x2="20" y2="17"/>
    </svg>
  </div>
  <span class="mob-brand-name">HERMES<span>.b2b</span></span>
</div>

<!-- ── Overlay ────────────────────────────────────────────────── -->
<div class="mob-overlay" id="mob-overlay" onclick="mobDrawerClose()"></div>

<!-- ── Drawer (clone da sidebar, sempre expandido) ────────────── -->
<div class="mob-drawer" id="mob-drawer">
  <a href="index.php" class="brand">
    <div class="logo-box">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 7l5 5-5 5"/><line x1="13" y1="17" x2="20" y2="17"/>
      </svg>
    </div>
    <div>
      <div class="logo-text">HERMES<span class="logo-b2b">.b2b</span></div>
      <div class="logo-sub">echo_lab · comercial</div>
    </div>
  </a>
  <nav class="nav-menu">
    <?php foreach ($filtered as $i): ?>
      <?php if (($i['type'] ?? '') === 'section'): ?>
        <div class="nav-section"><span><?= e($i['label']) ?></span></div>
      <?php else: ?>
        <?php $ic = $i['color'] ?? ''; $soon = !empty($i['soon']); ?>
        <a href="<?= $soon ? '#' : $i['href'] ?>"
           class="nav-item <?= $active===$i['k']?'active':'' ?> <?= $soon?'soon':'' ?> <?= $ic?'has-color':'' ?>"
           <?= $ic ? 'style="--item-color:'.e($ic).';"' : '' ?>
           <?= $soon ? 'onclick="event.preventDefault();"' : 'onclick="mobDrawerClose()"' ?>>
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="<?= $i['icon'] ?>"/></svg>
          <span class="nav-label">
            <span class="nav-main"><?= e($i['label']) ?></span>
            <?php if (!empty($i['sub'])): ?><span class="nav-sub"><?= e($i['sub']) ?></span><?php endif; ?>
          </span>
          <?php if ($soon): ?><span class="badge-soon">Em breve</span><?php endif; ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <div class="user-profile">
      <div class="avatar"><?= strtoupper(mb_substr(auth_user_name() ?: 'U', 0, 1)) ?></div>
      <div class="user-info">
        <span class="user-name"><?= e(auth_user_name() ?: auth_user_email()) ?></span>
        <span class="user-plan"><?= e(tenant_role() ?: 'usuário') ?></span>
      </div>
    </div>
    <a href="billing.php" class="logout-btn">💳 Plano e cobranças</a>
    <a href="/logout.php" class="logout-row">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      <span class="logout-text">Sair</span>
    </a>
  </div>
</div>

<aside class="sidebar">
  <a href="index.php" class="brand">
    <!-- Identidade do PRODUTO: HERMES (echo_lab) — não vem de tenant_brand -->
    <div class="logo-box" title="HERMES.b2b · hermesb2b.co">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 7l5 5-5 5"/>
        <line x1="13" y1="17" x2="20" y2="17"/>
      </svg>
    </div>
    <div>
      <div class="logo-text">HERMES<span class="logo-b2b">.b2b</span></div>
      <div class="logo-sub">echo_lab · comercial</div>
    </div>
  </a>
  <a href="sobre.php" class="logo-about" title="Conheça o HERMES.b2b">Conheça →</a>

  <nav class="nav-menu">
    <?php
    // Super-admin vê tudo (incluindo "Em breve" pra testar).
    // Usuário comum: itens com 'soon'=>true ficam ocultos — sidebar só mostra o que funciona.
    $visibleItems = array_filter($items, function($i) use ($isSuper) {
        if (($i['type'] ?? '') === 'section') return true; // seções avaliadas abaixo
        return $isSuper || empty($i['soon']);
    });
    // Remove seções que ficaram sem nenhum item após o filtro
    $filtered = [];
    foreach (array_values($visibleItems) as $idx => $i) {
        if (($i['type'] ?? '') === 'section') {
            // Olha adiante: existe algum item (não-seção) depois?
            $rest = array_slice(array_values($visibleItems), $idx + 1);
            $hasNext = false;
            foreach ($rest as $r) {
                if (($r['type'] ?? '') !== 'section') { $hasNext = true; break; }
                break; // outra seção antes de qualquer item → sem itens
            }
            if ($hasNext) $filtered[] = $i;
        } else {
            $filtered[] = $i;
        }
    }
    ?>
    <?php foreach ($filtered as $i): ?>
      <?php if (($i['type'] ?? '') === 'section'): ?>
        <div class="nav-section"><span><?= e($i['label']) ?></span></div>
      <?php else: ?>
        <?php $itemColor = $i['color'] ?? ''; $isSoon = !empty($i['soon']); ?>
        <a href="<?= $isSoon ? '#' : $i['href'] ?>"
           class="nav-item <?= $active===$i['k']?'active':'' ?> <?= $isSoon?'soon':'' ?> <?= $itemColor?'has-color':'' ?>"
           <?= $itemColor ? 'style="--item-color: ' . e($itemColor) . ';"' : '' ?>
           <?= $isSoon ? 'onclick="event.preventDefault();"' : '' ?>>
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="<?= $i['icon'] ?>"/></svg>
          <span class="nav-label">
            <span class="nav-main"><?= e($i['label']) ?></span>
            <?php if (!empty($i['sub'])): ?><span class="nav-sub"><?= e($i['sub']) ?></span><?php endif; ?>
          </span>
          <?php if ($isSoon): ?><span class="badge-soon">Em breve</span><?php endif; ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="user-profile">
      <div class="avatar"><?= strtoupper(mb_substr(auth_user_name() ?: 'U', 0, 1)) ?></div>
      <div class="user-info">
        <span class="user-name"><?= e(auth_user_name() ?: auth_user_email()) ?></span>
        <span class="user-plan"><?= e(tenant_role() ?: 'usuário') ?></span>
      </div>
    </div>
    <a href="billing.php" class="logout-btn">💳 Plano e cobranças</a>
    <a href="/privacy.php" class="logout-btn" style="opacity:.6;font-size:.65rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;display:block">🔒 Privacidade</a>
    <a href="/logout.php" class="logout-row" title="Sair">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      <span class="logout-text">Sair</span>
    </a>
  </div>
</aside>

<main class="main">
  <?php if ($isSuper): ?>
    <?php $imp = $_SESSION['impersonating'] ?? null; ?>
    <div class="super-bar" style="<?= $imp ? 'background:#1e40af;' : '' ?>">
      <?php if ($imp): ?>
        <span>👁 Impersonando: <strong><?= e($imp['tenant_name']) ?></strong> · Você continua como super-admin</span>
        <a href="/admin/impersonate.php?exit=1" style="background:#fff;color:#1e40af;padding:3px 12px;border-radius:5px;font-weight:600;text-decoration:none">✕ Sair da conta</a>
      <?php else: ?>
        <span>🔧 Você está visualizando como super-admin · Tenant: <strong><?= e($tenant['name']) ?></strong></span>
        <a href="/admin/">← Voltar pro Super Admin</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <header class="header">
    <div class="header-title"><?= e($title) ?></div>
    <div class="header-actions" id="header-actions"></div>
  </header>
  <div class="viewport">
    <?= flash_render() ?>
    <?php $body(); ?>
  </div>
</main>

<!-- ── Mobile Bottom Nav ─────────────────────────────────────────────────── -->
<nav class="mob-bottomnav">
  <?php foreach ($bottomItems as $i): ?>
    <?php $ic = $i['color'] ?? ''; ?>
    <a href="<?= $i['href'] ?>"
       class="mob-nav-btn <?= $active===$i['k']?'active':'' ?>"
       <?= $ic && $active===$i['k'] ? 'style="color:'.e($ic).';"' : '' ?>>
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="<?= $i['icon'] ?>"/></svg>
      <?= e($i['label']) ?>
    </a>
  <?php endforeach; ?>
  <!-- Mais: abre o drawer -->
  <button class="mob-nav-btn" onclick="mobDrawerOpen()" aria-label="Mais">
    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>
    </svg>
    Mais
  </button>
</nav>

<script>
function mobDrawerOpen() {
  document.getElementById('mob-drawer').classList.add('open');
  document.getElementById('mob-overlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function mobDrawerClose() {
  document.getElementById('mob-drawer').classList.remove('open');
  document.getElementById('mob-overlay').classList.remove('open');
  document.body.style.overflow = '';
}
// Fecha com swipe pra esquerda
(function() {
  let startX = 0;
  const drawer = document.getElementById('mob-drawer');
  drawer.addEventListener('touchstart', e => { startX = e.touches[0].clientX; }, { passive: true });
  drawer.addEventListener('touchend', e => {
    if (startX - e.changedTouches[0].clientX > 60) mobDrawerClose();
  }, { passive: true });
})();
</script>

<!-- ───────────────────────────────────────────────────────────────────────────
     MAIL LAB · Compose Modal global (acessível de qualquer card via openMailCompose)
     Phase 0: usa mailto: (abre cliente de e-mail do usuário).
     Phase 1: vai usar OAuth Google/MS Graph com envio via API + tracking.
     ─────────────────────────────────────────────────────────────────────────── -->
<style>
.mc-bg { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:400; align-items:flex-end; justify-content:flex-end; padding:0 24px 24px 0; }
.mc-bg.open { display:flex; }
.mc-box { background:#fff; border-radius:12px 12px 0 0; width:560px; max-width:100%; max-height:80vh; display:flex; flex-direction:column; box-shadow:0 -10px 40px rgba(0,0,0,.18); overflow:hidden; border:1px solid var(--line); border-bottom:none; }
.mc-head { background:#404040; color:#fff; padding:9px 14px; display:flex; align-items:center; justify-content:space-between; font-size:.84rem; font-weight:500; }
.mc-head .mc-title { display:flex; align-items:center; gap:8px; }
.mc-head .mc-badge { font-family:'Geist Mono',monospace; font-size:.56rem; background:#0d9488; color:#fff; padding:2px 7px; border-radius:4px; letter-spacing:.06em; text-transform:uppercase; font-weight:600; }
.mc-head .mc-close { background:transparent; border:none; color:#fff; cursor:pointer; opacity:.8; font-size:1.2rem; padding:0 4px; line-height:1; }
.mc-head .mc-close:hover { opacity:1; }
.mc-fields { padding:0 14px; border-bottom:1px solid var(--line); }
.mc-row { display:flex; align-items:center; padding:8px 0; border-bottom:1px solid var(--line); gap:8px; }
.mc-row:last-child { border-bottom:none; }
.mc-row label { font-family:'Geist Mono',monospace; font-size:.66rem; text-transform:uppercase; letter-spacing:.06em; color:var(--mute); font-weight:600; min-width:50px; }
.mc-row input { flex:1; border:none; outline:none; font-size:.88rem; font-family:inherit; padding:4px 0; background:transparent; color:var(--ink); }
.mc-body { flex:1; padding:14px; min-height:200px; overflow-y:auto; }
.mc-body textarea { width:100%; min-height:220px; border:none; outline:none; font-family:inherit; font-size:.92rem; line-height:1.55; resize:none; color:var(--ink); }
.mc-foot { padding:10px 14px; border-top:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; background:var(--bone); }
.mc-btn-send { background:var(--hermes); color:#fff; border:none; padding:9px 18px; border-radius:7px; font-size:.86rem; font-weight:600; cursor:pointer; font-family:inherit; display:inline-flex; align-items:center; gap:6px; }
.mc-btn-send:hover { background:#0ea371; }
.mc-btn-send:disabled { opacity:.5; cursor:not-allowed; }
.mc-hint { font-size:.72rem; color:var(--mute); }
.mc-hint a { color:var(--cr); text-decoration:none; }
.mc-hint .badge-soon { font-family:'Geist Mono',monospace; font-size:.56rem; background:#fef3c7; color:#92400e; padding:1px 6px; border-radius:4px; letter-spacing:.04em; font-weight:600; margin-left:4px; }
/* ═══════════════════════════════════════════════════════════════
   MOBILE NAV — ≤768px
   Sidebar desktop → oculta
   Mobile: top bar + drawer + bottom nav
   ═══════════════════════════════════════════════════════════════ */
@media (max-width: 768px) {
  body { flex-direction: column; overflow: hidden; }

  /* Oculta sidebar desktop */
  .sidebar { display: none !important; }

  /* ── Top bar ── */
  .mob-topbar {
    display: flex; align-items: center; gap: 10px;
    height: 52px; padding: 0 16px;
    background: var(--white); border-bottom: 1px solid var(--border);
    flex-shrink: 0; z-index: 50;
  }
  .mob-hamburger {
    background: none; border: none; cursor: pointer;
    color: var(--ink); padding: 6px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    -webkit-tap-highlight-color: transparent;
  }
  .mob-hamburger:hover { background: var(--bone); }
  .mob-brand-logo {
    width: 30px; height: 30px; border-radius: 8px;
    background: var(--hermes); display: flex; align-items: center;
    justify-content: center; color: #fff; flex-shrink: 0;
  }
  .mob-brand-name {
    font-family: 'Geist Mono', monospace; font-weight: 700;
    font-size: .88rem; color: var(--ink); letter-spacing: -0.01em;
  }
  .mob-brand-name span { color: var(--hermes); }

  /* ── Overlay ── */
  .mob-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.4); z-index: 190;
    -webkit-tap-highlight-color: transparent;
  }
  .mob-overlay.open { display: block; }

  /* ── Drawer ── */
  .mob-drawer {
    position: fixed; top: 0; left: -290px; width: 280px;
    height: 100dvh; background: var(--white);
    z-index: 200; transition: left .25s cubic-bezier(.4,0,.2,1);
    display: flex; flex-direction: column;
    padding: 1rem .65rem; overflow-y: auto;
    box-shadow: none;
  }
  .mob-drawer.open {
    left: 0;
    box-shadow: 6px 0 32px rgba(0,0,0,.14);
  }
  .mob-drawer .brand { opacity: 1; margin-bottom: 1.4rem; }
  .mob-drawer .logo-text,
  .mob-drawer .logo-sub,
  .mob-drawer .nav-item span,
  .mob-drawer .nav-section,
  .mob-drawer .nav-sub,
  .mob-drawer .badge-soon,
  .mob-drawer .user-info,
  .mob-drawer .logout-text,
  .mob-drawer .logout-btn { opacity: 1 !important; }
  .mob-drawer .nav-item { white-space: normal; }
  .mob-drawer .sidebar-footer { border-top: 1px solid var(--border); padding-top: .6rem; margin-top: auto; }

  /* ── Bottom nav ── */
  .mob-bottomnav {
    display: flex; background: var(--white);
    border-top: 1px solid var(--border);
    flex-shrink: 0; z-index: 50;
    padding-bottom: env(safe-area-inset-bottom);
  }
  .mob-nav-btn {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 3px;
    padding: 8px 4px; text-decoration: none;
    color: var(--ink-3); font-size: .58rem; font-weight: 600;
    font-family: 'Geist Mono', monospace; letter-spacing: .02em;
    transition: color .15s; -webkit-tap-highlight-color: transparent;
    background: none; border: none; cursor: pointer;
    position: relative;
  }
  .mob-nav-btn.active { color: var(--hermes); }
  .mob-nav-btn.active::before {
    content: ''; position: absolute; top: 0; left: 20%; right: 20%;
    height: 2px; background: var(--hermes); border-radius: 0 0 3px 3px;
  }
  .mob-nav-btn svg { width: 22px; height: 22px; stroke-width: 1.7; }

  /* Main: ocupa tudo */
  .main { flex: 1; min-width: 0; min-height: 0; overflow: hidden; }
  .header { padding: 0 1rem; }
  .viewport { padding: .9rem 1rem; }

  /* Grids ficam 1 coluna */
  .stats-grid { grid-template-columns: 1fr !important; gap: .8rem; }
}

/* Oculta elementos mobile no desktop */
@media (min-width: 769px) {
  .mob-topbar, .mob-drawer, .mob-overlay, .mob-bottomnav { display: none !important; }
}

@media (max-width: 640px) {
  .mc-bg { padding:0; align-items:stretch; justify-content:stretch; }
  .mc-box { width:100%; max-height:100vh; border-radius:0; }
}
</style>
<div class="mc-bg" id="mc-bg" onclick="if(event.target.id==='mc-bg')closeMailCompose()">
  <div class="mc-box">
    <div class="mc-head">
      <div class="mc-title">
        ✉ Novo e-mail <span class="mc-badge">Mail Lab</span>
      </div>
      <button class="mc-close" onclick="closeMailCompose()" title="Fechar (Esc)">×</button>
    </div>
    <div class="mc-fields">
      <div class="mc-row">
        <label>Para</label>
        <input type="email" id="mc-to" placeholder="destinatario@empresa.com">
      </div>
      <div class="mc-row">
        <label>Assunto</label>
        <input type="text" id="mc-subject" placeholder="Sobre uma oportunidade…">
      </div>
    </div>
    <div class="mc-body">
      <textarea id="mc-body" placeholder="Olá,&#10;&#10;Sou da [sua empresa] e gostaria de apresentar…&#10;&#10;Posso te mostrar como funciona?&#10;&#10;Abraço,"></textarea>
    </div>
    <div class="mc-foot">
      <div class="mc-hint">
        Envio via cliente padrão <span class="badge-soon">PHASE 0</span><br>
        <small>API integrada chegando com Mail Lab</small>
      </div>
      <button class="mc-btn-send" id="mc-send" onclick="sendMailCompose()">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        Enviar
      </button>
    </div>
  </div>
</div>
<script>
// MAIL LAB — abertura programática (chamado de cards Pipeline/Radar)
// prefill: { to, subject, body, name (lead name) }
function openMailCompose(prefill) {
    prefill = prefill || {};
    document.getElementById('mc-to').value      = prefill.to      || '';
    document.getElementById('mc-subject').value = prefill.subject || (prefill.name ? 'Sobre ' + prefill.name : '');
    document.getElementById('mc-body').value    = prefill.body    || '';
    document.getElementById('mc-bg').classList.add('open');
    setTimeout(() => {
        const target = prefill.to ? 'mc-subject' : 'mc-to';
        document.getElementById(target).focus();
    }, 80);
}
function closeMailCompose() { document.getElementById('mc-bg').classList.remove('open'); }

function sendMailCompose() {
    const to   = document.getElementById('mc-to').value.trim();
    const subj = document.getElementById('mc-subject').value.trim();
    const body = document.getElementById('mc-body').value.trim();
    if (!to) { alert('Informe o destinatário'); return; }
    // Phase 0: mailto: — abre cliente padrão do SO
    // Phase 1: vai POST pra cnpj-api.php?action=mail_send com OAuth Google/MS
    const href = 'mailto:' + encodeURIComponent(to)
        + '?subject=' + encodeURIComponent(subj)
        + '&body='    + encodeURIComponent(body);
    window.location.href = href;
    setTimeout(closeMailCompose, 200);
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.getElementById('mc-bg').classList.contains('open')) closeMailCompose();
    // Ctrl+Enter envia
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && document.getElementById('mc-bg').classList.contains('open')) {
        e.preventDefault();
        sendMailCompose();
    }
});
</script>

</body>
</html>
<?php
}
