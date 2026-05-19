<?php
/**
 * HERMES.b2b — Esqueci minha senha
 * Gera token único e envia link de reset por e-mail.
 */
require_once __DIR__ . '/config.php';

// Redireciona se já está logado
if (auth_user_id()) {
    header('Location: /app/');
    exit;
}

// ── Garante tabela password_resets ───────────────────────────────────────────
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

// ── Rate limiting: máx 3 resets / 30 min por IP ──────────────────────────────
function _reset_rate_ok(): bool {
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0')[0]);
    try {
        // Reutiliza a tabela criada pelo login
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

        // Segurança: sempre mostramos a mensagem de "enviado" independente de existir
        if ($user) {
            // Invalida tokens anteriores do usuário
            db_q("DELETE FROM password_resets WHERE user_id = ?", [$user['id']]);

            $token   = bin2hex(random_bytes(32)); // 64 chars hex
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            db_q(
                "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)",
                [$user['id'], $token, $expires]
            );

            $base_url   = rtrim(APP_URL, '/');
            $reset_link = $base_url . '/reset-password.php?token=' . $token;
            $name       = $user['name'] ?: 'usuário';

            $subject = 'HERMES.b2b — Redefina sua senha';
            $body    = '<!DOCTYPE html><html lang="pt-BR"><body style="font-family:sans-serif;color:#18181b;padding:24px;max-width:520px;margin:0 auto">'
                     . '<div style="margin-bottom:18px"><strong style="font-size:1.1rem;color:#10b981">HERMES<span style="color:#18181b">.b2b</span></strong></div>'
                     . "<p>Olá, <strong>{$name}</strong>!</p>"
                     . '<p style="margin:12px 0">Recebemos uma solicitação para redefinir a senha da sua conta HERMES.b2b.</p>'
                     . '<p style="margin:12px 0">Clique no botão abaixo para criar uma nova senha (link válido por <strong>1 hora</strong>):</p>'
                     . "<p style=\"margin:20px 0\"><a href=\"{$reset_link}\" style=\"background:#10b981;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block\">Redefinir minha senha →</a></p>"
                     . "<p style=\"font-size:.82rem;color:#8b8a93\">Ou copie e cole: <br><a href=\"{$reset_link}\" style=\"color:#10b981\">{$reset_link}</a></p>"
                     . '<hr style="margin:20px 0;border:none;border-top:1px solid #e7e5e0">'
                     . '<p style="font-size:.78rem;color:#8b8a93">Se você não solicitou isso, ignore este e-mail — sua senha não será alterada.</p>'
                     . '</body></html>';

            hermes_mail($email, $subject, $body, ['reply_to' => 'suporte@hermesb2b.co']);
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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Esqueci minha senha — HERMES.b2b</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --hermes:#10b981; --coral:#be123c;
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
  .card .sub { color: var(--mute); font-size: .88rem; text-align: center; margin-bottom: 22px; line-height: 1.5; }

  .field { margin-bottom: 14px; }
  .field label { display: block; font-family: 'Geist Mono', monospace; font-size: .62rem; color: var(--mute); text-transform: uppercase; letter-spacing: .06em; font-weight: 600; margin-bottom: 6px; }
  .field input { width: 100%; padding: 11px 13px; border: 1px solid var(--line); border-radius: 8px; font-size: .92rem; font-family: inherit; background: #fff; color: var(--ink); transition: all .15s; }
  .field input:focus { outline: none; border-color: var(--hermes); box-shadow: 0 0 0 3px rgba(16,185,129,.1); }

  .submit { width: 100%; padding: 12px; background: var(--hermes); color: #fff; border: none; border-radius: 10px; font-size: 1rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: background .15s; margin-top: 6px; }
  .submit:hover { background: #0ea371; }

  .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 11px 14px; border-radius: 8px; font-size: .85rem; margin-bottom: 18px; }
  .success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 16px 18px; border-radius: 8px; font-size: .9rem; line-height: 1.5; }

  .back { display: block; text-align: center; margin-top: 18px; font-size: .88rem; color: var(--mute); text-decoration: none; }
  .back:hover { color: var(--hermes); }

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
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <button type="submit" class="submit">Enviar link de redefinição →</button>
      </form>
    <?php endif; ?>
  </div>

  <a class="back" href="/login.php">← Voltar pro login</a>

  <div class="foot">
    <a href="https://www.hermesb2b.co" target="_blank">hermesb2b.co</a> · by echo_lab ·
    <a href="/terms.php">Termos</a> · <a href="/privacy.php">Privacidade</a>
  </div>
</div>
<?php require_once __DIR__ . '/core/lgpd_banner.php'; ?>
</body>
</html>
