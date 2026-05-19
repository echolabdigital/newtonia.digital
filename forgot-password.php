<?php
/**
 * Newton IA — Esqueci minha senha
 * Gera token único e envia link de reset por e-mail.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/emails.php';

if (auth_user_id()) {
    header('Location: /app/');
    exit;
}

function ensure_password_resets_table(): void {
    static $done = false;
    if ($done) return;
    db_q("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(64) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        used_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

$sent  = false;
$error = '';

function _reset_rate_ok(): bool {
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0')[0]);
    try {
        db_q("CREATE TABLE IF NOT EXISTS login_rate_limits (
            ip VARCHAR(45) NOT NULL, attempts INT DEFAULT 0,
            window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $key = 'reset_' . $ip;
        db_q("DELETE FROM login_rate_limits WHERE ip = ? AND window_start < DATE_SUB(NOW(), INTERVAL 30 MINUTE)", [$key]);
        $row = db_one("SELECT attempts FROM login_rate_limits WHERE ip = ?", [$key]);
        if ($row && (int)$row['attempts'] >= 3) return false;
        db_q("INSERT INTO login_rate_limits (ip, attempts, window_start) VALUES (?, 1, NOW())
              ON DUPLICATE KEY UPDATE
              attempts    = IF(window_start < DATE_SUB(NOW(), INTERVAL 30 MINUTE), 1, attempts + 1),
              window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL 30 MINUTE), NOW(), window_start)",
            [$key]);
        return true;
    } catch (\Throwable $e) { return true; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!_reset_rate_ok()) {
        $error = 'Muitas solicitações de reset. Aguarde 30 minutos.';
        goto end_post;
    }
    ensure_password_resets_table();

    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Informe um e-mail válido.';
    } else {
        $user = db_one('SELECT id, name FROM users WHERE email = ? LIMIT 1', [$email]);

        if ($user) {
            db_q("DELETE FROM password_resets WHERE user_id = ?", [$user['id']]);
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            db_q("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)",
                [$user['id'], $token, $expires]);
            $reset_link = rtrim(APP_URL, '/') . '/reset-password.php?token=' . $token;
            [$subject, $body] = email_redefinir_senha($user['name'] ?: 'usuário', $reset_link);
            hermes_mail($email, $subject, $body);
        }

        $sent = true;
    }
    end_post:;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' rx='22' fill='%230EA5E9'/%3E%3Ccircle cx='50' cy='50' r='10' fill='%23fff'/%3E%3Cellipse cx='50' cy='50' rx='38' ry='13' fill='none' stroke='%23fff' stroke-width='6'/%3E%3Cellipse cx='50' cy='50' rx='38' ry='13' fill='none' stroke='%23fff' stroke-width='6' transform='rotate(60 50 50)'/%3E%3Cellipse cx='50' cy='50' rx='38' ry='13' fill='none' stroke='%23fff' stroke-width='6' transform='rotate(120 50 50)'/%3E%3C/svg%3E">
<title>Recuperar senha — Newton IA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
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
  .card h1 { font-size: 1.3rem; font-weight: 600; margin-bottom: 4px; text-align: center; letter-spacing: -0.02em; }
  .card .sub { color: var(--mute); font-size: .88rem; text-align: center; margin-bottom: 22px; line-height: 1.5; }

  .field { margin-bottom: 14px; }
  .field label { display: block; font-family: var(--font-mono); font-size: .62rem; color: var(--mute); text-transform: uppercase; letter-spacing: .08em; font-weight: 600; margin-bottom: 6px; }
  .field input { width: 100%; padding: 11px 13px; border: 1px solid var(--line); border-radius: var(--radius-sm); font-size: .92rem; font-family: inherit; background: #fff; color: var(--ink); transition: border-color .15s, box-shadow .15s; }
  .field input:focus { outline: none; border-color: var(--newton); box-shadow: 0 0 0 3px rgba(14,165,233,.12); }

  .submit { width: 100%; padding: 12px; background: var(--ink); color: #fff; border: none; border-radius: var(--radius); font-size: .95rem; font-weight: 600; cursor: pointer; font-family: var(--font-mono); letter-spacing: .02em; transition: background .18s; margin-top: 6px; }
  .submit:hover { background: var(--newton); }

  .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 11px 14px; border-radius: var(--radius-sm); font-size: .85rem; margin-bottom: 18px; }
  .success { background: #f0f9ff; border: 1px solid #bae6fd; color: #0c4a6e; padding: 16px 18px; border-radius: var(--radius-sm); font-size: .9rem; line-height: 1.6; }

  .back { display: block; text-align: center; margin-top: 18px; font-size: .88rem; color: var(--mute); text-decoration: none; transition: color .15s; }
  .back:hover { color: var(--newton); }

  .foot { text-align: center; margin-top: 18px; font-size: .72rem; color: var(--mute); }
  .foot a { color: var(--mute); text-decoration: none; }
  .foot a:hover { color: var(--newton); }
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
    <h1>Recuperar senha</h1>
    <p class="sub">Informe seu e-mail e enviaremos um link para criar uma nova senha.</p>

    <?php if ($sent): ?>
      <div class="success">
        ✓ <strong>Verifique seu e-mail.</strong><br>
        Se existe uma conta com esse endereço, você receberá um link de redefinição em instantes. O link expira em 1 hora.
      </div>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="error">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="field">
          <label>E-mail da conta</label>
          <input type="email" name="email" required autofocus
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="seu@email.com">
        </div>
        <button type="submit" class="submit">Enviar link de redefinição →</button>
      </form>
    <?php endif; ?>
  </div>

  <a class="back" href="/login.php">← Voltar pro login</a>

  <div class="foot">
    <a href="https://newtonia.digital" target="_blank">newtonia.digital</a> · by echo_lab ·
    <a href="/terms.php">Termos</a> · <a href="/privacy.php">Privacidade</a>
  </div>
</div>
<?php require_once __DIR__ . '/core/lgpd_banner.php'; ?>
</body>
</html>
