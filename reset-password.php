<?php
/**
 * HERMES.b2b — Redefinir senha
 * Valida token + salva nova senha.
 */
require_once __DIR__ . '/config.php';

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

ensure_password_resets_table();

$token = trim($_GET['token'] ?? '');
$error = '';
$done  = false;

// Valida o token (existe, não expirado, não usado)
$reset = null;
if ($token !== '') {
    $reset = db_one(
        "SELECT r.*, u.name, u.email
         FROM password_resets r
         JOIN users u ON u.id = r.user_id
         WHERE r.token = ?
           AND r.expires_at > NOW()
           AND r.used_at IS NULL
         LIMIT 1",
        [$token]
    );
}

$invalid_token = ($token === '' || !$reset);

if (!$invalid_token && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass1 = $_POST['password']  ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (strlen($pass1) < 8) {
        $error = 'A senha deve ter pelo menos 8 caracteres.';
    } elseif ($pass1 !== $pass2) {
        $error = 'As senhas não coincidem.';
    } else {
        $hash = password_hash($pass1, PASSWORD_BCRYPT, ['cost' => 12]);
        db_q('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $reset['user_id']]);
        db_q('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$reset['id']]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>Nova senha — HERMES.b2b</title>
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

  .strength { height: 3px; border-radius: 2px; background: var(--line); margin-top: 6px; overflow: hidden; }
  .strength-bar { height: 100%; width: 0; transition: width .3s, background .3s; }

  .submit { width: 100%; padding: 12px; background: var(--hermes); color: #fff; border: none; border-radius: 10px; font-size: 1rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: background .15s; margin-top: 6px; }
  .submit:hover { background: #0ea371; }

  .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 11px 14px; border-radius: 8px; font-size: .85rem; margin-bottom: 18px; }
  .success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 16px 18px; border-radius: 8px; font-size: .9rem; line-height: 1.5; text-align: center; }
  .warning { background: #fef3c7; border: 1px solid #fbbf24; color: #78350f; padding: 16px 18px; border-radius: 8px; font-size: .9rem; text-align: center; }

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
    <h1>Criar nova senha</h1>

    <?php if ($invalid_token): ?>
      <div class="warning">
        ⚠ <strong>Link inválido ou expirado.</strong><br>
        Solicite um novo link de redefinição de senha.
      </div>
      <a class="back" href="/forgot-password.php" style="margin-top:14px;display:block;text-align:center">Solicitar novo link →</a>

    <?php elseif ($done): ?>
      <div class="success">
        ✓ <strong>Senha redefinida com sucesso!</strong><br>
        Você já pode entrar com sua nova senha.
      </div>
      <a class="submit" href="/login.php" style="display:block;text-align:center;text-decoration:none;margin-top:16px">Ir pro login →</a>

    <?php else: ?>
      <p class="sub">Conta: <strong><?= htmlspecialchars($reset['email']) ?></strong></p>

      <?php if ($error): ?>
        <div class="error">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <div class="field">
          <label>Nova senha</label>
          <input type="password" name="password" id="pw1" required minlength="8"
                 autocomplete="new-password" oninput="checkStrength(this.value)">
          <div class="strength"><div class="strength-bar" id="str-bar"></div></div>
        </div>

        <div class="field">
          <label>Confirmar nova senha</label>
          <input type="password" name="password2" id="pw2" required minlength="8"
                 autocomplete="new-password">
        </div>

        <button type="submit" class="submit">Salvar nova senha →</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$done && !$invalid_token): ?>
    <a class="back" href="/login.php">← Voltar pro login</a>
  <?php endif; ?>

  <div class="foot">
    <a href="https://www.hermesb2b.co" target="_blank">hermesb2b.co</a> · by echo_lab
  </div>
</div>

<script>
function checkStrength(v) {
  const bar = document.getElementById('str-bar');
  let score = 0;
  if (v.length >= 8)  score++;
  if (v.length >= 12) score++;
  if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
  if (/\d/.test(v)) score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;
  const colors = ['#ef4444','#f97316','#eab308','#10b981','#10b981'];
  const widths  = ['20%','40%','60%','80%','100%'];
  bar.style.background = colors[score - 1] || 'transparent';
  bar.style.width      = widths[score - 1] || '0';
}
</script>
<?php require_once __DIR__ . '/core/lgpd_banner.php'; ?>
</body>
</html>
