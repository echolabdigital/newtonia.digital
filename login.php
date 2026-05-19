<?php
require_once __DIR__ . '/config.php';

// Já logado → redireciona para o destino correto
if (auth_user_id()) {
    header('Location: ' . (auth_is_super() ? '/admin/' : '/app/'));
    exit;
}

$error    = '';
$redirect = $_GET['redirect'] ?? '/app/';

// ── Rate limiting: máx 10 tentativas / 15 min por IP ─────────────────────────
function _login_rate_check(): bool {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip = trim(explode(',', $ip)[0]);
    try {
        db_q("CREATE TABLE IF NOT EXISTS login_rate_limits (
            ip VARCHAR(45) NOT NULL,
            attempts INT DEFAULT 0,
            window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db_q("DELETE FROM login_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $row = db_one("SELECT attempts FROM login_rate_limits WHERE ip = ?", [$ip]);
        return !$row || (int)$row['attempts'] < 10;
    } catch (\Throwable $e) { return true; }
}
function _login_rate_record(bool $failed): void {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip = trim(explode(',', $ip)[0]);
    if (!$failed) {
        try { db_q("DELETE FROM login_rate_limits WHERE ip = ?", [$ip]); } catch (\Throwable $e) {}
        return;
    }
    try {
        db_q("INSERT INTO login_rate_limits (ip, attempts, window_start) VALUES (?, 1, NOW())
              ON DUPLICATE KEY UPDATE
              attempts    = IF(window_start < DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, attempts + 1),
              window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL 15 MINUTE), NOW(), window_start)",
            [$ip]);
    } catch (\Throwable $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!_login_rate_check()) {
        $error = 'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.';
    } else {
        $result = auth_login(
            $_POST['email']    ?? '',
            $_POST['password'] ?? ''
        );
        _login_rate_record(!$result['ok']);
        if ($result['ok']) {
            header('Location: /' . ltrim($result['redirect'], '/'));
            exit;
        }
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' rx='22' fill='%230EA5E9'/%3E%3Ccircle cx='50' cy='50' r='10' fill='%23fff'/%3E%3Cellipse cx='50' cy='50' rx='38' ry='13' fill='none' stroke='%23fff' stroke-width='6'/%3E%3Cellipse cx='50' cy='50' rx='38' ry='13' fill='none' stroke='%23fff' stroke-width='6' transform='rotate(60 50 50)'/%3E%3Cellipse cx='50' cy='50' rx='38' ry='13' fill='none' stroke='%23fff' stroke-width='6' transform='rotate(120 50 50)'/%3E%3C/svg%3E">
<title>Entrar — Newton IA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --newton:#0ea5e9; --newton-2:#0284c7; --indigo:#3d3dff; --coral:#be123c;
    --ink:#0a0a0f; --fg-2:#4a4a55; --mute:#8b8a93; --line:#e4e4dd; --bg:#fafaf7;
    --font-display:'Geist',system-ui,sans-serif; --font-mono:'Geist Mono','SF Mono',monospace;
    --radius:10px; --radius-sm:6px;
  }
  body { font-family: var(--font-display); background: var(--bg); color: var(--ink); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; -webkit-font-smoothing: antialiased; letter-spacing: -0.01em; }

  .wrap { width: 100%; max-width: 420px; }

  .brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 24px; text-decoration: none; color: inherit; }
  .brand-icon { width: 44px; height: 44px; border-radius: 12px; background: var(--newton); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 12px rgba(14,165,233,.3); flex-shrink: 0; }
  .brand-name { font-family: var(--font-mono); font-weight: 700; font-size: 1.15rem; letter-spacing: -0.01em; }
  .brand-name .ia { color: var(--newton); }

  .card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.04); }
  .card h1 { font-size: 1.3rem; font-weight: 600; margin-bottom: 4px; text-align: center; letter-spacing: -0.02em; color: var(--ink); }
  .card .sub { color: var(--mute); font-size: .88rem; text-align: center; margin-bottom: 24px; line-height: 1.5; }

  .field { margin-bottom: 14px; }
  .field label { display: block; font-family: var(--font-mono); font-size: .62rem; color: var(--mute); text-transform: uppercase; letter-spacing: .08em; font-weight: 600; margin-bottom: 6px; }
  .field input { width: 100%; padding: 11px 13px; border: 1px solid var(--line); border-radius: var(--radius-sm); font-size: .92rem; font-family: inherit; background: #fff; color: var(--ink); transition: border-color .15s, box-shadow .15s; }
  .field input:focus { outline: none; border-color: var(--newton); box-shadow: 0 0 0 3px rgba(14,165,233,.12); }

  .forgot { display: block; text-align: right; font-size: .78rem; color: var(--mute); margin-top: -4px; margin-bottom: 14px; text-decoration: none; transition: color .15s; }
  .forgot:hover { color: var(--newton); }

  .submit { width: 100%; padding: 12px; background: var(--ink); color: #fff; border: none; border-radius: var(--radius); font-size: .95rem; font-weight: 600; cursor: pointer; font-family: var(--font-mono); letter-spacing: .02em; transition: background .18s; margin-top: 6px; }
  .submit:hover { background: var(--newton); }

  .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 11px 14px; border-radius: var(--radius-sm); font-size: .85rem; margin-bottom: 18px; }

  .signup-cta { text-align: center; margin-top: 18px; font-size: .88rem; color: var(--mute); }
  .signup-cta a { color: var(--newton); font-weight: 600; text-decoration: none; }
  .signup-cta a:hover { color: var(--newton-2); }

  .foot { text-align: center; margin-top: 18px; font-size: .72rem; color: var(--mute); }
  .foot a { color: var(--mute); text-decoration: none; }
  .foot a:hover { color: var(--newton); }
</style>
</head>
<body>
<div class="wrap">

  <a class="brand" href="https://newtonia.digital">
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
    <h1>Bem-vindo de volta</h1>
    <p class="sub">Entre na sua conta para gerenciar seus agentes de IA.</p>

    <?php if ($error): ?>
      <div class="error">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="_redirect" value="<?= htmlspecialchars($redirect) ?>">
      <div class="field">
        <label>E-mail</label>
        <input type="email" name="email" required autofocus placeholder="seu@email.com">
      </div>
      <div class="field">
        <label>Senha</label>
        <input type="password" name="password" required placeholder="••••••••">
      </div>
      <a href="/forgot-password.php" class="forgot">Esqueci minha senha</a>
      <button type="submit" class="submit">Entrar →</button>
    </form>

    <p class="signup-cta">
      Não tem conta? <a href="/signup.php">Comece grátis →</a>
    </p>
  </div>

  <div class="foot">
    <a href="https://newtonia.digital" target="_blank">newtonia.digital</a> · by echo_lab ·
    <a href="/terms.php">Termos</a> · <a href="/privacy.php">Privacidade</a>
  </div>
</div>
<?php require_once __DIR__ . '/core/lgpd_banner.php'; ?>
</body>
</html>
