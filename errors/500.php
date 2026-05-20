<?php http_response_code(500); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>Erro interno — Newton IA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root { --newton:#0ea5e9; --coral:#be123c; --ink:#18181b; --mute:#8b8a93; --line:#e7e5e0; --bone:#f6f4ef; }
  body { font-family: 'Geist', system-ui, sans-serif; background: var(--bone); color: var(--ink); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; -webkit-font-smoothing: antialiased; letter-spacing: -0.01em; }

  .wrap { width: 100%; max-width: 480px; text-align: center; }

  .brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 36px; text-decoration: none; color: inherit; }
  .brand-icon { width: 44px; height: 44px; border-radius: 11px; background: var(--newton); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(16,185,129,.25); }
  .brand-text { font-family: 'Geist Mono', monospace; font-weight: 700; font-size: 1.2rem; line-height: 1.1; }
  .brand-text .b2b { color: var(--newton); font-size: .76em; }

  .code { font-family: 'Geist Mono', monospace; font-size: 6rem; font-weight: 800; color: var(--coral); line-height: 1; margin-bottom: 12px; letter-spacing: -4px; }
  .title { font-size: 1.4rem; font-weight: 700; margin-bottom: 10px; letter-spacing: -0.02em; }
  .desc { color: var(--mute); font-size: .92rem; line-height: 1.6; margin-bottom: 28px; }

  .alert-box { background: #fff; border: 1px solid var(--line); border-left: 3px solid var(--coral); border-radius: 8px; padding: 14px 18px; margin-bottom: 28px; text-align: left; font-size: .84rem; color: var(--mute); line-height: 1.6; }
  .alert-box strong { color: var(--ink); }

  .actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
  .btn { padding: 11px 22px; border-radius: 9px; font-size: .92rem; font-weight: 600; font-family: inherit; text-decoration: none; cursor: pointer; transition: all .15s; }
  .btn-primary { background: var(--newton); color: #fff; border: none; }
  .btn-primary:hover { background: #0ea371; }
  .btn-ghost { background: #fff; color: var(--ink); border: 1px solid var(--line); }
  .btn-ghost:hover { background: var(--bone); }

  .foot { margin-top: 40px; font-size: .72rem; color: var(--mute); }
  .foot a { color: var(--mute); text-decoration: none; }
  .foot a:hover { color: var(--newton); }
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

  <div class="code">500</div>
  <h1 class="title">Algo deu errado</h1>
  <p class="desc">Ocorreu um erro interno no servidor. Nossa equipe já foi notificada e está trabalhando na correção.</p>

  <div class="alert-box">
    <strong>O que fazer agora?</strong><br>
    Tente recarregar a página em alguns instantes. Se o problema persistir, entre em contato pelo e-mail
    <a href="mailto:suporte@newtonia.digital" style="color:var(--newton);text-decoration:none">suporte@newtonia.digital</a>.
  </div>

  <div class="actions">
    <a href="javascript:location.reload()" class="btn btn-primary">Tentar novamente →</a>
    <a href="/app/" class="btn btn-ghost">Ir pro painel</a>
  </div>

  <div class="foot">
    <a href="https://www.newtonia.digital" target="_blank">newtonia.digital</a> · by echo_lab
  </div>
</div>
</body>
</html>
