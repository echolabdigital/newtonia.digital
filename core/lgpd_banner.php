<?php
/**
 * HERMES.b2b — Banner LGPD / Cookie Consent
 *
 * Incluir ANTES do </body> em todas as páginas públicas.
 * O banner aparece apenas se o cookie "hermes_lgpd_ok" não estiver setado.
 *
 * Uso:
 *   <?php require_once __DIR__ . '/../core/lgpd_banner.php'; ?>
 *   (ou require_once __DIR__ . '/core/lgpd_banner.php' da raiz)
 */
?>
<div id="lgpd-banner" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:9999;
     background:#18181b;color:#fff;padding:16px 24px;box-shadow:0 -4px 20px rgba(0,0,0,.25);
     display:flex;align-items:center;gap:16px;flex-wrap:wrap;font-family:'Geist',system-ui,sans-serif;font-size:.84rem;line-height:1.5">
  <div style="flex:1;min-width:260px">
    <span style="font-family:'Geist Mono',monospace;font-size:.6rem;color:#10b981;text-transform:uppercase;letter-spacing:.08em;margin-right:8px">// LGPD</span>
    Usamos apenas cookies essenciais para manter sua sessão ativa. Não rastreamos você para fins publicitários.
    <a href="/privacy.php" style="color:#10b981;text-decoration:none;margin-left:6px;white-space:nowrap">Política de Privacidade →</a>
  </div>
  <div style="display:flex;gap:8px;flex-shrink:0">
    <a href="/privacy.php" style="padding:8px 14px;border-radius:7px;border:1px solid rgba(255,255,255,.2);color:#a1a1aa;text-decoration:none;font-size:.8rem;white-space:nowrap">Saiba mais</a>
    <button onclick="lgpdAccept()" style="padding:8px 18px;border-radius:7px;background:#10b981;border:none;color:#fff;font-weight:600;cursor:pointer;font-family:inherit;font-size:.84rem;white-space:nowrap">Entendi →</button>
  </div>
</div>

<script>
(function() {
  function getCookie(name) {
    return document.cookie.split(';').some(c => c.trim().startsWith(name + '='));
  }
  // Só mostra se o usuário ainda não aceitou
  if (!getCookie('hermes_lgpd_ok')) {
    var b = document.getElementById('lgpd-banner');
    if (b) b.style.display = 'flex';
  }
})();

function lgpdAccept() {
  // Seta cookie por 365 dias
  var d = new Date();
  d.setFullYear(d.getFullYear() + 1);
  document.cookie = 'hermes_lgpd_ok=1; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
  var b = document.getElementById('lgpd-banner');
  if (b) {
    b.style.transition = 'opacity .3s';
    b.style.opacity = '0';
    setTimeout(function() { b.style.display = 'none'; }, 300);
  }
}
</script>
