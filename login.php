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
    $ip = trim(explode(',', $ip)[0]); // primeiro IP se vier via proxy
    try {
        db_q("CREATE TABLE IF NOT EXISTS login_rate_limits (
            ip VARCHAR(45) NOT NULL,
            attempts INT DEFAULT 0,
            window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Limpa janelas antigas (manutenção leve)
        db_q("DELETE FROM login_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $row = db_one("SELECT attempts FROM login_rate_limits WHERE ip = ?", [$ip]);
        return !$row || (int)$row['attempts'] < 10;
    } catch (\Throwable $e) { return true; } // fail-open para não travar login em erro de DB
}
function _login_rate_record(bool $failed): void {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip = trim(explode(',', $ip)[0]);
    if (!$failed) {
        // Login OK: zera tentativas desse IP
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
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>Entrar — HERMES.b2b</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --hermes:#10b981; --indigo:#3d3dff; --coral:#be123c;
    --ink:#18181b; --mute:#8b8a93; --line:#e7e5e0; --bone:#f6f4ef;
  }
  body { font-family: 'Geist', system-ui, sans-serif; background: var(--bone); color: var(--ink); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; -webkit-font-smoothing: antialiased; letter-spacing: -0.01em; }

  .wrap { width: 100%; max-width: 420px; }

  .brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 22px; text-decoration: none; color: inherit; }
  .brand-icon { width: 44px; height: 44px; border-radius: 11px; background: var(--hermes); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(16,185,129,.25); }
  .brand-text { font-family: 'Geist Mono', monospace; font-weight: 700; font-size: 1.2rem; line-height: 1.1; }
  .brand-text .b2b { color: var(--hermes); font-size: .76em; }

  .card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 32px; box-shadow: 0 1px 6px rgba(0,0,0,.04); }
  .card h1 { font-size: 1.3rem; font-weight: 700; margin-bottom: 4px; text-align: center; letter-spacing: -0.02em; }
  .card .sub { color: var(--mute); font-size: .88rem; text-align: center; margin-bottom: 22px; }

  .field { margin-bottom: 14px; }
  .field label { display: block; font-family: 'Geist Mono', monospace; font-size: .62rem; color: var(--mute); text-transform: uppercase; letter-spacing: .06em; font-weight: 600; margin-bottom: 6px; }
  .field input { width: 100%; padding: 11px 13px; border: 1px solid var(--line); border-radius: 8px; font-size: .92rem; font-family: inherit; background: #fff; color: var(--ink); transition: all .15s; }
  .field input:focus { outline: none; border-color: var(--hermes); box-shadow: 0 0 0 3px rgba(16,185,129,.1); }

  .submit { width: 100%; padding: 12px; background: var(--hermes); color: #fff; border: none; border-radius: 10px; font-size: 1rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: background .15s; margin-top: 6px; }
  .submit:hover { background: #0ea371; }

  .forgot { display: block; text-align: right; font-size: .78rem; color: var(--mute); margin-top: -4px; margin-bottom: 14px; text-decoration: none; }
  .forgot:hover { color: var(--hermes); }

  .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 11px 14px; border-radius: 8px; font-size: .85rem; margin-bottom: 18px; }

  .signup-cta { text-align: center; margin-top: 18px; font-size: .88rem; color: var(--mute); }
  .signup-cta a { color: var(--hermes); font-weight: 600; text-decoration: none; }
  .signup-cta a:hover { color: #0ea371; }

  .foot { text-align: center; margin-top: 18px; font-size: .72rem; color: var(--mute); }
  .foot a { color: var(--mute); text-decoration: none; }
  .foot a:hover { color: var(--hermes); }
</style>
</head>
<body>
<div class="wrap">

  <a class="brand" href="/">
    <div class="brand-icon">
      <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 7l5 5-5 5"/><line x1="13" y1="17" x2="20" y2="17"/>
      </svg>
    </div>
    <div class="brand-text">HERMES<span class="b2b">.b2b</span></div>
  </a>

  <div class="card">
    <h1>Bem-vindo de volta</h1>
    <p class="sub">Entre na sua conta pra continuar prospectando.</p>

    <?php if ($error): ?>
      <div class="error">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="_redirect" value="<?= htmlspecialchars($redirect) ?>">
      <div class="field">
        <label>E-mail</label>
        <input type="email" name="email" required autofocus>
      </div>
      <div class="field">
        <label>Senha</label>
        <input type="password" name="password" required>
      </div>
      <a href="/forgot-password.php" class="forgot">Esqueci minha senha</a>
      <button type="submit" class="submit">Entrar</button>
    </form>

    <p class="signup-cta">
      Não tem conta? <a href="/signup.php">Comece grátis →</a>
    </p>
  </div>

  <div class="foot">
    <a href="https://www.hermesb2b.co" target="_blank">hermesb2b.co</a> · by echo_lab ·
    <a href="/terms.php">Termos</a> · <a href="/privacy.php">Privacidade</a>
  </div>
</div>
<?php require_once __DIR__ . '/core/lgpd_banner.php'; ?>
</body>
</html>
