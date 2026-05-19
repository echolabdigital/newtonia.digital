<?php
require_once __DIR__ . '/config.php';
require_login();

// Se POST, seleciona o tenant e redireciona
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['tenant_id'])) {
    if (tenant_set((int) $_POST['tenant_id'])) {
        header('Location: /app/');
        exit;
    }
}

// Super admin: lista todos os tenants ativos
if (auth_is_super()) {
    $tenants = db_all("SELECT * FROM tenants WHERE status IN ('active','pending') ORDER BY name");
} else {
    $tenants = db_all(
        "SELECT t.* FROM tenants t
         JOIN tenant_users tu ON tu.tenant_id = t.id
         WHERE tu.user_id = ? AND t.status IN ('active','pending')
         ORDER BY t.name",
        [auth_user_id()]
    );
}

// Só um tenant → entra direto
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
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>Selecionar conta — HERMES.b2b</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
         background: #f5f5f5; display: flex; align-items: center;
         justify-content: center; min-height: 100vh; }
  .card { background: #fff; border-radius: 12px; padding: 40px;
          width: 100%; max-width: 420px; box-shadow: 0 2px 16px rgba(0,0,0,.08); }
  h1 { font-size: 1.3rem; margin-bottom: 8px; color: #111; }
  p  { color: #666; font-size: .9rem; margin-bottom: 24px; }
  .tenant-btn { display: block; width: 100%; padding: 14px 18px; margin-bottom: 10px;
                background: #f8f8f8; border: 1px solid #e5e5e5; border-radius: 10px;
                font-size: 1rem; font-weight: 600; color: #111; cursor: pointer;
                text-align: left; transition: background .15s; }
  .tenant-btn:hover { background: #10b981; color: #fff; border-color: #10b981; }
  .dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%;
         margin-right: 10px; }
</style>
</head>
<body>
<div class="card">
  <h1>Selecionar conta</h1>
  <p>Olá, <?= e(auth_user_name()) ?>. Escolha em qual conta deseja entrar:</p>
  <?php foreach ($tenants as $t): ?>
  <form method="POST">
    <input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>">
    <button type="submit" class="tenant-btn">
      <span class="dot" style="background:<?= e($t['brand_color'] ?: '#10b981') ?>"></span>
      <?= e($t['brand_name'] ?: $t['name']) ?>
    </button>
  </form>
  <?php endforeach; ?>
  <?php if (empty($tenants)): ?>
    <p style="color:#dc2626">Nenhuma conta ativa encontrada.</p>
  <?php endif; ?>
  <p style="margin-top:20px;font-size:.8rem"><a href="/logout.php">Sair</a></p>
</div>
</body>
</html>
