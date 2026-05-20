<?php
require_once __DIR__ . '/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['tenant_id'])) {
    if (tenant_set((int) $_POST['tenant_id'])) {
        header('Location: /app/');
        exit;
    }
}

if (auth_is_super()) {
    $tenants = db_all("SELECT * FROM tenants WHERE status IN ('active','trial','pending') ORDER BY name");
} else {
    $tenants = db_all(
        "SELECT t.* FROM tenants t
         JOIN tenant_users tu ON tu.tenant_id = t.id
         WHERE tu.user_id = ? AND t.status IN ('active','trial','pending')
         ORDER BY t.name",
        [auth_user_id()]
    );
}

if (count($tenants) === 1) {
    tenant_set((int) $tenants[0]['id']);
    header('Location: /app/');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>Selecionar conta — Newton IA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --newton:#0ea5e9; --newton-2:#0284c7;
    --ink:#0a0a0f; --mute:#8b8a93; --line:#e4e4dd; --bg:#fafaf7;
    --font-display:'Geist',system-ui,sans-serif; --font-mono:'Geist Mono','SF Mono',monospace;
    --radius:10px; --radius-sm:6px;
  }
  body { font-family: var(--font-display); background: var(--bg); color: var(--ink); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; -webkit-font-smoothing: antialiased; letter-spacing: -0.01em; }

  .wrap { width: 100%; max-width: 420px; }

  .brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 22px; text-decoration: none; color: inherit; }
  .brand-icon { width: 44px; height: 44px; border-radius: 12px; background: var(--newton); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 12px rgba(14,165,233,.3); }
  .brand-name { font-family: var(--font-mono); font-weight: 700; font-size: 1.15rem; letter-spacing: -0.01em; }
  .brand-name .ia { color: var(--newton); }

  .card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.04); }
  .card h1 { font-size: 1.2rem; font-weight: 600; margin-bottom: 6px; letter-spacing: -0.02em; }
  .card .sub { color: var(--mute); font-size: .88rem; margin-bottom: 22px; }

  .tenant-btn { display: flex; align-items: center; gap: 12px; width: 100%; padding: 14px 16px; margin-bottom: 10px;
                background: var(--bg); border: 1.5px solid var(--line); border-radius: var(--radius);
                font-size: .95rem; font-weight: 500; color: var(--ink); cursor: pointer; text-align: left;
                transition: border-color .15s, background .15s; font-family: inherit; }
  .tenant-btn:hover { border-color: var(--newton); background: rgba(14,165,233,.04); color: var(--ink); }

  .dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }

  .empty { color: #dc2626; font-size: .9rem; padding: 12px 0; }
  .logout-link { display: block; margin-top: 20px; font-size: .8rem; color: var(--mute); text-decoration: none; }
  .logout-link:hover { color: var(--newton); }
</style>
</head>
<body>
<div class="wrap">

  <a class="brand" href="/login.php">
    <div class="brand-icon">
      <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="2" fill="currentColor" stroke="none"/>
        <ellipse cx="12" cy="12" rx="9" ry="3.5"/>
        <ellipse cx="12" cy="12" rx="9" ry="3.5" transform="rotate(60 12 12)"/>
        <ellipse cx="12" cy="12" rx="9" ry="3.5" transform="rotate(120 12 12)"/>
      </svg>
    </div>
    <div class="brand-name">Newton <span class="ia">IA</span></div>
  </a>

  <div class="card">
    <h1>Selecionar conta</h1>
    <p class="sub">Olá, <?= htmlspecialchars(auth_user_name()) ?>. Em qual conta deseja entrar?</p>

    <?php foreach ($tenants as $t): ?>
    <form method="POST">
      <input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>">
      <button type="submit" class="tenant-btn">
        <span class="dot" style="background:<?= htmlspecialchars($t['brand_color'] ?: '#0ea5e9') ?>"></span>
        <?= htmlspecialchars($t['brand_name'] ?: $t['name']) ?>
      </button>
    </form>
    <?php endforeach; ?>

    <?php if (empty($tenants)): ?>
      <p class="empty">Nenhuma conta ativa encontrada.</p>
    <?php endif; ?>

    <a class="logout-link" href="/logout.php">← Sair</a>
  </div>
</div>
</body>
</html>
