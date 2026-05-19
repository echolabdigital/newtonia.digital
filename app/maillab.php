<?php
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/_layout.php';

$tid = (int) $tenant['id'];

// Verifica se já tem alguma conta Mail Lab configurada (schema futuro)
// Quando Phase 1 entrar, será: db_val('SELECT COUNT(*) FROM mail_accounts WHERE tenant_id=?', [$tid]);
$has_account = false; // stub — sempre false por enquanto

app_layout('Mail Lab', 'maillab', function() use ($has_account) {
?>
<style>
  .ml-hero { background:#fff; border-radius:14px; padding:24px 28px; margin-bottom:18px; box-shadow:0 1px 4px rgba(0,0,0,.05); border:1px solid var(--line); display:flex; align-items:center; gap:16px; }
  .ml-hero .ml-icon { width:54px; height:54px; border-radius:12px; background:#0d948815; color:#0d9488; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .ml-hero h1 { font-size:1.3rem; font-weight:700; margin:0 0 4px; color:var(--ink); }
  .ml-hero .badge { font-family:'Geist Mono',monospace; font-size:.58rem; background:#0d9488; color:#fff; padding:3px 8px; border-radius:4px; letter-spacing:.08em; text-transform:uppercase; font-weight:600; margin-left:8px; vertical-align:middle; }
  .ml-hero p { font-size:.92rem; color:var(--mute); margin:0; }

  .ml-status { background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:12px 16px; font-size:.85rem; color:#92400e; margin-bottom:18px; display:flex; align-items:center; gap:10px; }
  .ml-status strong { color:#78350f; }

  .ml-choices { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:18px; }
  .ml-choice { background:#fff; border:1px solid var(--line); border-radius:14px; padding:22px 24px; position:relative; transition:all .15s; display:flex; flex-direction:column; }
  .ml-choice:hover { border-color:#0d9488; box-shadow:0 4px 18px rgba(13, 148, 136, .08); transform:translateY(-2px); }
  .ml-choice .ml-tag { position:absolute; top:14px; right:14px; font-family:'Geist Mono',monospace; font-size:.58rem; padding:2px 7px; border-radius:4px; letter-spacing:.08em; text-transform:uppercase; font-weight:600; }
  .ml-choice .ml-tag.self { background:#dcfce7; color:#166534; }
  .ml-choice .ml-tag.managed { background:#dbeafe; color:#1e40af; }
  .ml-choice .ml-tag.soon { background:#fef3c7; color:#92400e; }
  .ml-choice h3 { font-size:1.05rem; font-weight:700; margin:0 0 6px; color:var(--ink); }
  .ml-choice .ml-sub { font-family:'Geist Mono',monospace; font-size:.66rem; text-transform:uppercase; letter-spacing:.08em; color:var(--mute); font-weight:600; margin-bottom:14px; }
  .ml-choice .ml-features { list-style:none; padding:0; margin:0 0 18px; flex:1; }
  .ml-choice .ml-features li { font-size:.85rem; color:var(--ink-2); padding:5px 0; display:flex; align-items:flex-start; gap:8px; line-height:1.4; }
  .ml-choice .ml-features li::before { content:'✓'; color:#0d9488; font-weight:700; flex-shrink:0; margin-top:1px; }
  .ml-choice .ml-cta { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:11px 18px; border-radius:8px; text-decoration:none; font-size:.88rem; font-weight:600; font-family:inherit; cursor:pointer; border:none; transition:all .15s; }
  .ml-choice .ml-cta.primary { background:#0d9488; color:#fff; }
  .ml-choice .ml-cta.primary:hover { background:#0f766e; }
  .ml-choice .ml-cta.ghost { background:#fff; color:#0d9488; border:1px solid #0d9488; }
  .ml-choice .ml-cta.ghost:hover { background:#0d948810; }
  .ml-choice .ml-cta.disabled { background:var(--bone); color:var(--mute); cursor:not-allowed; }
  .ml-choice .ml-cta.disabled:hover { background:var(--bone); }
  .ml-choice .ml-stack { font-family:'Geist Mono',monospace; font-size:.7rem; color:var(--mute); margin-top:8px; }
  .ml-choice .ml-stack span { background:var(--bone); padding:2px 6px; border-radius:4px; }

  .ml-meanwhile { background:#fff; border:1px solid var(--line); border-radius:12px; padding:18px 20px; }
  .ml-meanwhile h4 { font-size:.92rem; font-weight:700; margin:0 0 6px; display:flex; align-items:center; gap:6px; }
  .ml-meanwhile p { font-size:.86rem; color:var(--ink-2); margin:0 0 12px; line-height:1.5; }
  .ml-meanwhile .ml-links { display:flex; gap:8px; flex-wrap:wrap; }
  .ml-meanwhile a { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; background:var(--bone); color:var(--ink); border-radius:7px; text-decoration:none; font-size:.84rem; font-weight:500; transition:all .15s; }
  .ml-meanwhile a:hover { background:#0d948815; color:#0d9488; }

  @media (max-width: 880px) {
    .ml-choices { grid-template-columns:1fr; }
    .ml-hero { flex-direction:column; align-items:flex-start; text-align:left; }
  }
</style>

<!-- Hero -->
<div class="ml-hero">
  <div class="ml-icon">
    <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2zM22 6l-10 7L2 6"/></svg>
  </div>
  <div>
    <h1>Mail Lab <span class="badge">HERMES</span></h1>
    <p>E-mail integrado ponta a ponta com o Pipeline e o Radar — envie, receba, acompanhe.</p>
  </div>
</div>

<?php if (!$has_account): ?>
<div class="ml-status">
  ⚠ <span><strong>Mail Lab ainda não configurado.</strong> Escolha como ativar:</span>
</div>

<!-- Duas modalidades -->
<div class="ml-choices">
  <!-- OPÇÃO 1: Self-service Google/Microsoft -->
  <div class="ml-choice">
    <span class="ml-tag self">Self-service</span>
    <h3>Conectar Google ou Microsoft</h3>
    <div class="ml-sub">// OAuth · passo a passo</div>
    <ul class="ml-features">
      <li>Vincula sua conta Google Workspace ou Microsoft 365 existente</li>
      <li>Envia e recebe usando seu domínio próprio sem mudar nada</li>
      <li>Caixa de entrada dentro do HERMES, visual Gmail-like</li>
      <li>Tracking de abertura e clique nos e-mails enviados</li>
      <li>Templates e assinaturas reutilizáveis</li>
    </ul>
    <button class="ml-cta primary disabled" disabled>
      Conectar conta
      <span class="ml-tag soon" style="position:static;margin-left:4px">Em breve</span>
    </button>
    <div class="ml-stack">Stack: <span>Gmail API</span> <span>MS Graph</span> <span>OAuth 2.0</span></div>
  </div>

  <!-- OPÇÃO 2: Managed pela echo_lab -->
  <div class="ml-choice">
    <span class="ml-tag managed">Managed</span>
    <h3>Echo_Lab ativa pra você</h3>
    <div class="ml-sub">// e-mail com a gente · infra própria</div>
    <ul class="ml-features">
      <li>Cria seu e-mail corporativo no domínio que você escolher</li>
      <li>Reputação de IP cuidada pela nossa equipe (DKIM, SPF, DMARC)</li>
      <li>Webmail Mail Lab com visual Gmail</li>
      <li>Email Delivery próprio — entregabilidade alta desde o dia 1</li>
      <li>Nós configuramos, você só usa</li>
    </ul>
    <button class="ml-cta ghost disabled" disabled>
      Solicitar ativação
      <span class="ml-tag soon" style="position:static;margin-left:4px">Em breve</span>
    </button>
    <div class="ml-stack">Stack: <span>Mail Lab by Vultr</span> <span>Email Delivery</span> <span>ATLAS</span></div>
  </div>
</div>
<?php endif; ?>

<!-- Compose disponível agora pelos cards -->
<div class="ml-meanwhile">
  <h4>✉ Compor e-mail está disponível agora</h4>
  <p>Você já pode <strong>compor e-mails diretamente pelos cards</strong> do Pipeline e do Radar Leads. O envio acontece pelo cliente padrão do seu sistema (Outlook, Gmail Web, Mail do macOS). Quando o Mail Lab estiver ativado na sua conta, o envio passa pela API integrada com tracking de abertura.</p>
  <div class="ml-links">
    <a href="cnpj.php">🎯 Ir pro Radar Leads</a>
    <a href="crm.php">📋 Abrir Pipeline</a>
    <a href="#" onclick="openMailCompose({}); return false">✉ Compor e-mail agora</a>
  </div>
</div>

<?php
});
