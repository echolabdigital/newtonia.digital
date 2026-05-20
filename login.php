<?php
require_once __DIR__ . '/config.php';

if (auth_user_id()) {
    header('Location: ' . (auth_is_super() ? '/admin/' : '/app/'));
    exit;
}

$error    = '';
$redirect = $_GET['redirect'] ?? '/app/';

function _login_rate_check(): bool {
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0')[0]);
    try {
        db_q("CREATE TABLE IF NOT EXISTS login_rate_limits (
            ip VARCHAR(45) NOT NULL, attempts INT DEFAULT 0,
            window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db_q("DELETE FROM login_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $row = db_one("SELECT attempts FROM login_rate_limits WHERE ip = ?", [$ip]);
        return !$row || (int)$row['attempts'] < 10;
    } catch (\Throwable $e) { return true; }
}
function _login_rate_record(bool $failed): void {
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0')[0]);
    if (!$failed) { try { db_q("DELETE FROM login_rate_limits WHERE ip = ?", [$ip]); } catch (\Throwable $e) {} return; }
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
        $error = 'Muitas tentativas. Aguarde alguns minutos.';
    } else {
        $result = auth_login($_POST['email'] ?? '', $_POST['password'] ?? '');
        _login_rate_record(!$result['ok']);
        if ($result['ok']) { header('Location: /' . ltrim($result['redirect'], '/')); exit; }
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Entrar — Newton IA</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  /* DS v3 tokens */
  --newton:      #0EA5E9;
  --newton-dark: #0284c7;
  --indigo:      #3D3DFF;
  --coral:       #BE123C;
  --ink:         #0a0a0f;
  --fg-2:        #4a4a55;
  --fg-3:        #7a7a86;
  --fg-4:        #a8a8b3;
  --bg:          #fafaf7;
  --bg-3:        #ebebe6;
  --surface:     #ffffff;
  --line:        #e4e4dd;
  --line-2:      #d4d4cc;
  --font-display: 'Geist', system-ui, -apple-system, sans-serif;
  --font-mono:    'Geist Mono', 'SF Mono', Menlo, monospace;
  --radius:      10px;
  --radius-sm:   6px;
  --shadow:      0 1px 3px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.04);
}

body {
  font-family: var(--font-display);
  background: var(--bg);
  color: var(--ink);
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  -webkit-font-smoothing: antialiased;
  letter-spacing: -0.01em;
}

.wrap { width: 100%; max-width: 400px; }

/* Logo — estilo Echo_Lab DS v3 */
.brand {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  margin-bottom: 28px;
  text-decoration: none;
  color: inherit;
}
.brand-icon {
  width: 42px; height: 42px;
  border-radius: 11px;
  background: var(--newton);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 2px 10px rgba(14,165,233,.35);
}
.brand-name {
  font-family: var(--font-mono);
  font-size: 1.1rem;
  font-weight: 700;
  letter-spacing: -0.01em;
  color: var(--ink);
  line-height: 1;
}
.brand-name .ia {
  color: var(--newton);
}

/* Card */
.card {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 32px;
  box-shadow: var(--shadow);
}

.card h1 {
  font-size: 1.25rem;
  font-weight: 600;
  letter-spacing: -0.025em;
  margin-bottom: 4px;
  text-align: center;
  color: var(--ink);
}
.card .sub {
  font-size: .875rem;
  color: var(--fg-3);
  text-align: center;
  margin-bottom: 24px;
  line-height: 1.5;
}

/* Campos */
.field { margin-bottom: 14px; }
.field label {
  display: block;
  font-family: var(--font-mono);
  font-size: .6rem;
  font-weight: 700;
  letter-spacing: .14em;
  text-transform: uppercase;
  color: var(--fg-3);
  margin-bottom: 7px;
}
.field input {
  width: 100%;
  padding: 11px 13px;
  border: 1px solid var(--line-2);
  border-radius: var(--radius-sm);
  font-family: var(--font-display);
  font-size: .92rem;
  background: var(--surface);
  color: var(--ink);
  transition: border-color .15s, box-shadow .15s;
  outline: none;
}
.field input:focus {
  border-color: var(--newton);
  box-shadow: 0 0 0 3px rgba(14,165,233,.15);
}

/* Esqueci */
.forgot {
  display: block;
  text-align: right;
  font-size: .78rem;
  color: var(--fg-4);
  margin-top: -6px;
  margin-bottom: 16px;
  text-decoration: none;
  transition: color .15s;
}
.forgot:hover { color: var(--newton); }

/* Botão primário — DS v3: ink fundo, hover → newton */
.btn-primary {
  display: block;
  width: 100%;
  padding: 13px 24px;
  background: var(--ink);
  color: #fff;
  border: 1.5px solid var(--ink);
  border-radius: var(--radius-sm);
  font-family: var(--font-mono);
  font-size: .88rem;
  font-weight: 500;
  letter-spacing: .02em;
  text-align: center;
  cursor: pointer;
  transition: background .18s, box-shadow .18s, transform .18s, border-color .18s;
  white-space: nowrap;
}
.btn-primary:hover {
  background: var(--newton);
  border-color: var(--newton);
  box-shadow: 0 1px 0 rgba(14,165,233,.4), 0 8px 24px -8px rgba(14,165,233,.35);
  transform: translateY(-1px);
}

/* Erro */
.error {
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #991b1b;
  padding: 11px 14px;
  border-radius: var(--radius-sm);
  font-size: .84rem;
  margin-bottom: 18px;
}

/* CTA cadastro */
.signup-cta {
  text-align: center;
  margin-top: 20px;
  font-size: .85rem;
  color: var(--fg-3);
}
.signup-cta a {
  color: var(--newton);
  font-weight: 600;
  text-decoration: none;
}
.signup-cta a:hover { color: var(--newton-dark); }

/* Footer */
.foot {
  text-align: center;
  margin-top: 20px;
  font-family: var(--font-mono);
  font-size: .68rem;
  letter-spacing: .06em;
  color: var(--fg-4);
}
.foot a { color: var(--fg-4); text-decoration: none; }
.foot a:hover { color: var(--newton); }

/* Divider */
.divider {
  height: 1px;
  background: var(--line);
  margin: 22px 0;
}
</style>
</head>
<body>
<div class="wrap">

  <a class="brand" href="https://newtonia.digital">
    <!-- Favicon inline: chevron > branco + dot coral sobre fundo newton -->
    <div class="brand-icon">
      <svg viewBox="0 0 100 100" width="28" height="28" xmlns="http://www.w3.org/2000/svg">
        <path d="M22 20 L58 50 L22 80" stroke="#fff" stroke-width="14" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
        <rect x="62" y="68" width="24" height="11" rx="5.5" fill="#BE123C"/>
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
        <input type="email" name="email" required autofocus
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               placeholder="seu@email.com">
      </div>
      <div class="field">
        <label>Senha</label>
        <input type="password" name="password" required placeholder="••••••••">
      </div>
      <a href="/forgot-password.php" class="forgot">Esqueci minha senha</a>
      <button type="submit" class="btn-primary">Entrar →</button>
    </form>

    <div class="divider"></div>

    <p class="signup-cta">
      Não tem conta? <a href="/signup.php">Comece grátis →</a>
    </p>
  </div>

  <div class="foot">
    <a href="https://newtonia.digital">newtonia.digital</a>
    &nbsp;·&nbsp; by echo_lab
    &nbsp;·&nbsp; <a href="/terms.php">Termos</a>
    &nbsp;·&nbsp; <a href="/privacy.php">Privacidade</a>
  </div>

</div>
<?php require_once __DIR__ . '/core/lgpd_banner.php'; ?>
</body>
</html>
