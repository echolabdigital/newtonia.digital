<?php http_response_code(404); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>Página não encontrada — HERMES.b2b</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root { --hermes:#10b981; --ink:#18181b; --mute:#8b8a93; --line:#e7e5e0; --bone:#f6f4ef; }
  body { font-family: 'Geist', system-ui, sans-serif; background: var(--bone); color: var(--ink); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; -webkit-font-smoothing: antialiased; letter-spacing: -0.01em; }

  .wrap { width: 100%; max-width: 480px; text-align: center; }

  .brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 36px; text-decoration: none; color: inherit; }
  .brand-icon { width: 44px; height: 44px; border-radius: 11px; background: var(--hermes); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(16,185,129,.25); }
  .brand-text { font-family: 'Geist Mono', monospace; font-weight: 700; font-size: 1.2rem; line-height: 1.1; }
  .brand-text .b2b { color: var(--hermes); font-size: .76em; }

  .code { font-family: 'Geist Mono', monospace; font-size: 6rem; font-weight: 800; color: var(--hermes); line-height: 1; margin-bottom: 12px; letter-spacing: -4px; }
  .title { font-size: 1.4rem; font-weight: 700; margin-bottom: 10px; letter-spacing: -0.02em; }
  .desc { color: var(--mute); font-size: .92rem; line-height: 1.6; margin-bottom: 28px; }

  .mono-tag { font-family: 'Geist Mono', monospace; font-size: .7rem; color: var(--mute); margin-bottom: 24px; display: block; }

  .actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
  .btn { padding: 11px 22px; border-radius: 9px; font-size: .92rem; font-weight: 600; font-family: inherit; text-decoration: none; cursor: pointer; transition: all .15s; }
  .btn-primary { background: var(--hermes); color: #fff; border: none; }
  .btn-primary:hover { background: #0ea371; }
  .btn-ghost { background: #fff; color: var(--ink); border: 1px solid var(--line); }
  .btn-ghost:hover { background: var(--bone); }

  .foot { margin-top: 40px; font-size: .72rem; color: var(--mute); }
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

  <div class="code">404</div>
  <h1 class="title">Página não encontrada</h1>
  <p class="desc">O endereço que você acessou não existe ou foi removido.<br>Verifique o link ou volte para a tela inicial.</p>
  <span class="mono-tag">// <?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/') ?></span>

  <div class="actions">
    <a href="/app/" class="btn btn-primary">Ir pro painel →</a>
    <a href="javascript:history.back()" class="btn btn-ghost">← Voltar</a>
  </div>

  <div class="foot">
    <a href="https://www.hermesb2b.co" target="_blank">hermesb2b.co</a> · by echo_lab
  </div>
</div>
</body>
</html>
