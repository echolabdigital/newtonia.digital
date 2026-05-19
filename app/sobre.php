<?php
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/_layout.php';

app_layout('Conheça o HERMES.b2b', 'sobre', function() {
?>
<style>
  .sobre { max-width:880px; margin:0 auto; padding:0 8px; }

  /* Hero */
  .sb-hero { text-align:center; padding:32px 20px 40px; }
  .sb-hero .sb-icon { width:64px; height:64px; margin:0 auto 18px; background:var(--hermes); border-radius:14px; display:flex; align-items:center; justify-content:center; color:#fff; box-shadow:0 4px 14px rgba(16,185,129,.25); }
  .sb-hero h1 { font-size:2.4rem; font-weight:700; margin:0 0 10px; letter-spacing:-0.02em; color:var(--ink); line-height:1.1; }
  .sb-hero h1 .b2b { color:var(--hermes); font-family:'Geist Mono',monospace; font-size:.7em; font-weight:600; }
  .sb-hero .sb-tag { font-family:'Geist Mono',monospace; font-size:.7rem; color:var(--mute); letter-spacing:.12em; text-transform:uppercase; font-weight:600; margin:0 0 14px; }
  .sb-hero .sb-tag::before { content:'// '; opacity:.5; }
  .sb-hero p { font-size:1.05rem; color:var(--ink-2); line-height:1.6; max-width:640px; margin:0 auto; }
  .sb-hero .sb-anchor { display:inline-block; margin-top:16px; font-family:'Geist Mono',monospace; font-size:.8rem; color:var(--hermes); font-weight:600; padding:8px 14px; background:#10b98115; border-radius:8px; }

  /* Sections */
  .sb-section { padding:36px 0; border-top:1px solid var(--line); }
  .sb-section:first-of-type { border-top:none; }
  .sb-section h2 { font-size:1.4rem; font-weight:700; margin:0 0 6px; color:var(--ink); }
  .sb-section .sb-lead { font-size:.95rem; color:var(--mute); margin:0 0 24px; line-height:1.5; }
  .sb-section p { font-size:.95rem; line-height:1.7; color:var(--ink-2); margin:0 0 14px; }
  .sb-section p strong { color:var(--ink); font-weight:600; }
  .sb-quote { background:var(--bone); border-left:3px solid var(--hermes); padding:16px 20px; margin:18px 0; border-radius:0 8px 8px 0; font-size:.95rem; color:var(--ink); font-style:italic; line-height:1.6; }

  /* Modules grid */
  .sb-mods { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .sb-mod { background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px 18px; position:relative; overflow:hidden; }
  .sb-mod::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--mc); }
  .sb-mod h3 { font-size:1rem; font-weight:600; margin:0 0 4px; }
  .sb-mod .sb-mod-sub { font-family:'Geist Mono',monospace; font-size:.66rem; color:var(--mute); letter-spacing:.06em; text-transform:uppercase; font-weight:600; margin-bottom:8px; }
  .sb-mod p { font-size:.85rem; color:var(--ink-2); margin:0; line-height:1.5; }
  .sb-mod .sb-mod-status { display:inline-block; margin-top:8px; font-family:'Geist Mono',monospace; font-size:.56rem; padding:2px 7px; border-radius:4px; letter-spacing:.08em; text-transform:uppercase; font-weight:600; }
  .sb-mod .sb-mod-status.on  { background:#dcfce7; color:#166534; }
  .sb-mod .sb-mod-status.off { background:#f3f4f6; color:var(--mute); }

  /* ICP list */
  .sb-icp { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-top:18px; }
  .sb-icp-item { background:#fff; border:1px solid var(--line); border-radius:10px; padding:16px; }
  .sb-icp-item .ico { font-size:1.4rem; margin-bottom:8px; display:block; }
  .sb-icp-item h4 { font-size:.92rem; font-weight:600; margin:0 0 4px; color:var(--ink); }
  .sb-icp-item p { font-size:.82rem; color:var(--mute); margin:0; line-height:1.5; }

  /* Manifesto callout */
  .sb-manifesto { background:linear-gradient(135deg, var(--hermes) 0%, var(--pipeline, #059669) 100%); color:#fff; border-radius:16px; padding:32px 28px; margin:24px 0; text-align:center; }
  .sb-manifesto .sb-mark { font-family:'Geist Mono',monospace; font-size:.66rem; letter-spacing:.16em; text-transform:uppercase; opacity:.7; margin-bottom:14px; }
  .sb-manifesto .sb-mark::before { content:'// '; opacity:.6; }
  .sb-manifesto h2 { color:#fff; font-size:1.8rem; line-height:1.25; font-weight:700; margin:0 0 14px; letter-spacing:-0.02em; }
  .sb-manifesto p { color:rgba(255,255,255,.92); font-size:1rem; line-height:1.6; max-width:600px; margin:0 auto; }

  /* Echo lab footer card */
  .sb-echo { background:#0f172a; color:#fff; border-radius:14px; padding:28px; margin-top:20px; }
  .sb-echo h3 { color:#fff; font-size:1.2rem; font-weight:700; margin:0 0 8px; display:flex; align-items:center; gap:10px; }
  .sb-echo h3 .ico { width:32px; height:32px; background:var(--indigo); border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; }
  .sb-echo p { color:rgba(255,255,255,.85); font-size:.92rem; line-height:1.6; margin:0 0 8px; }
  .sb-echo .sb-pilars { display:flex; gap:10px; flex-wrap:wrap; margin-top:16px; }
  .sb-echo .sb-pilar { font-family:'Geist Mono',monospace; font-size:.7rem; padding:5px 9px; border-radius:5px; font-weight:600; color:#fff; }
  .sb-echo .sb-pilar.prisma  { background:#d946ef; }
  .sb-echo .sb-pilar.newton  { background:#0ea5e9; }
  .sb-echo .sb-pilar.hermes  { background:var(--hermes); }
  .sb-echo .sb-pilar.atlas   { background:#57534e; }
  .sb-echo .sb-pilar.darwin  { background:#84cc16; }
  .sb-echo .sb-pilar.edison  { background:#ea580c; }
  .sb-echo a { color:#fff; text-decoration:underline; opacity:.9; }
  .sb-echo a:hover { opacity:1; }

  .sb-cta-row { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin:30px 0 10px; }
  .sb-cta-row a { display:inline-flex; align-items:center; gap:6px; padding:10px 18px; border-radius:8px; text-decoration:none; font-size:.88rem; font-weight:500; }
  .sb-cta-row .primary { background:var(--hermes); color:#fff; }
  .sb-cta-row .primary:hover { background:#0ea371; }
  .sb-cta-row .ghost { background:#fff; color:var(--ink); border:1px solid var(--line); }
  .sb-cta-row .ghost:hover { border-color:var(--hermes); color:var(--hermes); }

  @media (max-width: 760px) {
    .sb-mods, .sb-icp { grid-template-columns:1fr; }
    .sb-hero h1 { font-size:1.8rem; }
    .sb-manifesto h2 { font-size:1.4rem; }
  }
</style>

<div class="sobre">

  <!-- HERO -->
  <div class="sb-hero">
    <div class="sb-icon">
      <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
        <path d="M5 7l5 5-5 5"/><line x1="13" y1="17" x2="20" y2="17"/>
      </svg>
    </div>
    <div class="sb-tag">Conheça o produto</div>
    <h1>HERMES<span class="b2b">.b2b</span></h1>
    <p>O comercial completo da sua operação. Encontra quem importa, conduz o negócio até o fechamento, apoia cada conversa.</p>
    <div class="sb-anchor">hermesb2b.co</div>
  </div>

  <!-- POR QUE HERMES -->
  <div class="sb-section">
    <h2>Por que <em>Hermes</em>?</h2>
    <p class="sb-lead">O nome vem da mitologia grega e diz tudo sobre o que o produto faz.</p>
    <p>Hermes era o <strong>deus do comércio e dos mensageiros</strong>, com asas nos pés. Não ficava parado: <strong>encontrava, conversava, fechava</strong>. Esse é o pilar comercial do stack Echo_Lab Tech, e por isso emprestamos o nome.</p>
    <p>Onde a inteligência artificial automatiza e qualifica leads de forma autônoma, o HERMES <strong>estrutura o trabalho humano</strong> do time comercial. IA faz o que máquina faz melhor; humanos fecham e relacionam. O HERMES é a ponte entre os dois.</p>
    <div class="sb-quote">"Vender é movimento. HERMES coloca sua operação comercial em ação."</div>
  </div>

  <!-- MÓDULOS -->
  <div class="sb-section">
    <h2>Os 6 módulos do stack</h2>
    <p class="sb-lead">Cada módulo resolve uma frente do comercial. Juntos, fecham o ciclo completo.</p>
    <div class="sb-mods">
      <div class="sb-mod" style="--mc:#059669">
        <h3>📋 Pipeline</h3>
        <div class="sb-mod-sub">Gestão comercial</div>
        <p>CRM Kanban estilo Bitrix24. Onde cada negócio está, por quê, e o que precisa para avançar.</p>
        <span class="sb-mod-status on">● Ativo</span>
      </div>
      <div class="sb-mod" style="--mc:#0d9488">
        <h3>✉ Mail Lab</h3>
        <div class="sb-mod-sub">E-mail integrado</div>
        <p>Envia, recebe e acompanha e-mail dentro do HERMES. Self-service (Google/Microsoft) ou managed pela Echo_Lab.</p>
        <span class="sb-mod-status on">● Ativo</span>
      </div>
      <div class="sb-mod" style="--mc:#10b981">
        <h3>🎯 Radar Leads</h3>
        <div class="sb-mod-sub">Prospecção</div>
        <p>+70M empresas da Receita Federal com filtros por vertical, porte, localização e Radar Score.</p>
        <span class="sb-mod-status on">● Ativo</span>
      </div>
      <div class="sb-mod" style="--mc:#14b8a6">
        <h3>📡 Signal</h3>
        <div class="sb-mod-sub">Disparador WhatsApp</div>
        <p>Campanhas em escala via WhatsApp Business API, cadências automatizadas, controle de entregabilidade.</p>
        <span class="sb-mod-status off">○ Em breve</span>
      </div>
      <div class="sb-mod" style="--mc:#06b6d4">
        <h3>💬 Whats Lab</h3>
        <div class="sb-mod-sub">Conversas · via NEWTON IA</div>
        <p>Inbox 2-way no WhatsApp com agentes inteligentes. Só roda integrado com NEWTON IA.</p>
        <span class="sb-mod-status off">○ Em breve</span>
      </div>
      <div class="sb-mod" style="--mc:#34d399">
        <h3>🎤 Pitch</h3>
        <div class="sb-mod-sub">Scripts SPIN</div>
        <p>Geração de scripts personalizados por perfil, produto e estágio do funil. O vendedor para de improvisar.</p>
        <span class="sb-mod-status off">○ Em breve</span>
      </div>
    </div>
  </div>

  <!-- PARA QUEM É -->
  <div class="sb-section">
    <h2>Para quem é o HERMES.b2b</h2>
    <p class="sb-lead">Médias empresas brasileiras B2B/B2B2C com tração, operação que cresceu mais rápido que a estrutura, e três sintomas em comum.</p>
    <div class="sb-icp">
      <div class="sb-icp-item">
        <span class="ico">🧩</span>
        <h4>Marca colcha de retalhos</h4>
        <p>Feita por agências diferentes em momentos diferentes, sem sistema.</p>
      </div>
      <div class="sb-icp-item">
        <span class="ico">🔥</span>
        <h4>Comercial no esforço</h4>
        <p>Sem método, sem CRM ou com CRM que ninguém usa, sem pipeline previsível.</p>
      </div>
      <div class="sb-icp-item">
        <span class="ico">🪡</span>
        <h4>Tech em remendos</h4>
        <p>Site numa plataforma, e-mail em outra, automações em terceira, ninguém sabe onde está o quê.</p>
      </div>
    </div>
    <p style="margin-top:20px"><strong>Grande demais para depender de freelancer. Pequeno demais para montar tech in-house.</strong> Esse é o ponto onde o stack faz diferença.</p>
  </div>

  <!-- MANIFESTO -->
  <div class="sb-manifesto">
    <div class="sb-mark">Manifesto</div>
    <h2>Democratizar tech no Brasil é dar acesso ao stack, não vender mais um projeto.</h2>
    <p>Agência entrega coisas: um site, uma campanha, um post. Empresa de tecnologia opera um <strong>stack</strong>. O HERMES não é projeto, é operação contínua que sustenta o crescimento todos os dias.</p>
  </div>

  <!-- ECHO LAB -->
  <div class="sb-section">
    <h2>Uma plataforma <em>by</em> echo_lab.technology</h2>
    <p class="sb-lead">HERMES é o pilar comercial de um stack maior. A Echo_Lab Tech opera 6 pilares conectados, cada um resolvendo uma frente da operação.</p>
    <div class="sb-echo">
      <h3>
        <span class="ico">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" viewBox="0 0 24 24">
            <path d="M5 7l5 5-5 5"/><line x1="13" y1="17" x2="20" y2="17"/>
          </svg>
        </span>
        echo_lab tech
      </h3>
      <p><strong>Tese:</strong> empresa de tecnologia que opera um stack, não agência que entrega projetos.</p>
      <p><strong>Promessa:</strong> tecnologia que tira a fricção da operação, todos os dias, em todos os pilares.</p>
      <p><strong>Frase-âncora:</strong> Você opera. A gente sustenta.</p>
      <div class="sb-pilars">
        <span class="sb-pilar prisma">PRISMA · Marca</span>
        <span class="sb-pilar newton">NEWTON IA · Inteligência</span>
        <span class="sb-pilar hermes">HERMES · Comercial</span>
        <span class="sb-pilar atlas">ATLAS · Infra</span>
        <span class="sb-pilar darwin">DARWIN · Verticais</span>
        <span class="sb-pilar edison">EDISON · Custom dev</span>
      </div>
      <p style="margin-top:16px">Saiba mais sobre o stack completo em <a href="https://echolab.technology" target="_blank">echolab.technology</a></p>
    </div>
  </div>

  <div class="sb-cta-row">
    <a href="index.php" class="primary">← Voltar ao dashboard</a>
    <a href="cnpj.php" class="ghost">🎯 Começar a prospectar</a>
  </div>

</div>
<?php
});
