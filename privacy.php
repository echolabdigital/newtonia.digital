<?php
/**
 * HERMES.b2b — Política de Privacidade (LGPD)
 */
$_is_logged = false;
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    $_is_logged = (bool) auth_user_id();
}
$last_updated = '17 de maio de 2026';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Política de Privacidade — HERMES.b2b</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root { --hermes:#10b981; --ink:#18181b; --mute:#8b8a93; --line:#e7e5e0; --bone:#f6f4ef; }
  body { font-family: 'Geist', system-ui, sans-serif; background: var(--bone); color: var(--ink); -webkit-font-smoothing: antialiased; letter-spacing: -0.01em; }
  .top-bar { background: #fff; border-bottom: 1px solid var(--line); padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 10; }
  .brand { display: flex; align-items: center; gap: 9px; text-decoration: none; color: inherit; }
  .brand-icon { width: 36px; height: 36px; border-radius: 9px; background: var(--hermes); color: #fff; display: flex; align-items: center; justify-content: center; }
  .brand-text { font-family: 'Geist Mono', monospace; font-weight: 700; font-size: 1rem; }
  .brand-text .b2b { color: var(--hermes); font-size: .76em; }
  .nav-link { font-size: .85rem; color: var(--mute); text-decoration: none; }
  .nav-link:hover { color: var(--hermes); }
  .page { max-width: 760px; margin: 0 auto; padding: 48px 24px 80px; }
  .page-header { margin-bottom: 36px; padding-bottom: 24px; border-bottom: 1px solid var(--line); }
  .page-header .mono { font-family: 'Geist Mono', monospace; font-size: .65rem; color: var(--hermes); text-transform: uppercase; letter-spacing: .1em; margin-bottom: 10px; }
  .page-header h1 { font-size: 2rem; font-weight: 800; letter-spacing: -0.03em; margin-bottom: 8px; }
  .page-header .meta { font-size: .82rem; color: var(--mute); }
  .doc-section { margin-bottom: 36px; }
  .doc-section h2 { font-family: 'Geist Mono', monospace; font-size: .78rem; color: var(--hermes); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 1px solid var(--line); }
  .doc-section h3 { font-size: .95rem; font-weight: 700; margin: 18px 0 8px; color: var(--ink); }
  .doc-section p { font-size: .9rem; color: #3f3f46; line-height: 1.75; margin-bottom: 12px; }
  .doc-section ul { padding-left: 20px; margin-bottom: 14px; }
  .doc-section li { font-size: .9rem; color: #3f3f46; line-height: 1.75; margin-bottom: 4px; }
  .doc-section a { color: var(--hermes); text-decoration: none; }
  .doc-section a:hover { text-decoration: underline; }
  .highlight-box { background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px; font-size: .88rem; color: #166534; line-height: 1.6; }
  .data-table { width: 100%; border-collapse: collapse; font-size: .86rem; margin-bottom: 18px; }
  .data-table th { background: var(--bone); padding: 10px 12px; text-align: left; font-family: 'Geist Mono', monospace; font-size: .62rem; text-transform: uppercase; color: var(--mute); letter-spacing: .06em; }
  .data-table td { padding: 10px 12px; border-bottom: 1px solid var(--line); vertical-align: top; color: #3f3f46; line-height: 1.5; }
  .data-table tr:last-child td { border-bottom: none; }
  .rights-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
  @media (max-width: 600px) { .rights-grid { grid-template-columns: 1fr; } }
  .right-card { background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 14px; }
  .right-card .icon { font-size: 1.2rem; margin-bottom: 6px; }
  .right-card strong { font-size: .88rem; display: block; margin-bottom: 4px; }
  .right-card p { font-size: .8rem; color: var(--mute); margin: 0; line-height: 1.45; }
  .toc { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 18px 22px; margin-bottom: 36px; }
  .toc h3 { font-family: 'Geist Mono', monospace; font-size: .65rem; text-transform: uppercase; letter-spacing: .08em; color: var(--mute); margin-bottom: 12px; }
  .toc ol { padding-left: 18px; }
  .toc li { font-size: .88rem; line-height: 2; }
  .toc a { color: var(--hermes); text-decoration: none; }
  .toc a:hover { text-decoration: underline; }
  .foot { text-align: center; padding: 24px; font-size: .78rem; color: var(--mute); border-top: 1px solid var(--line); margin-top: 40px; }
  .foot a { color: var(--mute); text-decoration: none; margin: 0 8px; }
  .foot a:hover { color: var(--hermes); }
</style>
</head>
<body>

<div class="top-bar">
  <a class="brand" href="/">
    <div class="brand-icon">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 7l5 5-5 5"/><line x1="13" y1="17" x2="20" y2="17"/>
      </svg>
    </div>
    <div class="brand-text">HERMES<span class="b2b">.b2b</span></div>
  </a>
  <?php if ($_is_logged): ?>
    <a class="nav-link" href="/app/">← Voltar ao painel</a>
  <?php else: ?>
    <a class="nav-link" href="/login.php">Entrar →</a>
  <?php endif; ?>
</div>

<div class="page">

  <div class="page-header">
    <div class="mono">// legal · LGPD</div>
    <h1>Política de Privacidade</h1>
    <div class="meta">Última atualização: <?= $last_updated ?> · HERMES.b2b by echo_lab</div>
  </div>

  <div class="highlight-box">
    Estamos comprometidos com a proteção dos seus dados. Esta Política descreve como coletamos, usamos e protegemos suas informações, em conformidade com a <strong>Lei Geral de Proteção de Dados (LGPD — Lei 13.709/2018)</strong>.
  </div>

  <div class="toc">
    <h3>// índice</h3>
    <ol>
      <li><a href="#controlador">Controlador dos Dados</a></li>
      <li><a href="#coleta">Dados que Coletamos</a></li>
      <li><a href="#finalidade">Finalidade e Base Legal</a></li>
      <li><a href="#compartilhamento">Compartilhamento de Dados</a></li>
      <li><a href="#retencao">Retenção e Exclusão</a></li>
      <li><a href="#seguranca">Segurança</a></li>
      <li><a href="#cookies">Cookies</a></li>
      <li><a href="#direitos">Direitos do Titular</a></li>
      <li><a href="#dpo">Encarregado (DPO)</a></li>
      <li><a href="#alteracoes">Alterações nesta Política</a></li>
    </ol>
  </div>

  <div id="controlador" class="doc-section">
    <h2>// 1. Controlador dos Dados</h2>
    <p>O controlador responsável pelo tratamento de dados pessoais é a <strong>echo_lab</strong>, operadora do HERMES.b2b.</p>
    <div class="highlight-box">
      <strong>echo_lab — HERMES.b2b</strong><br>
      E-mail para assuntos de privacidade: <a href="mailto:privacidade@hermesb2b.co">privacidade@hermesb2b.co</a><br>
      Site: <a href="https://www.hermesb2b.co" target="_blank">hermesb2b.co</a>
    </div>
  </div>

  <div id="coleta" class="doc-section">
    <h2>// 2. Dados que Coletamos</h2>
    <table class="data-table">
      <thead>
        <tr><th>Categoria</th><th>Dados</th><th>Origem</th></tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>Cadastro</strong></td>
          <td>Nome, e-mail, senha (hash bcrypt)</td>
          <td>Fornecido pelo usuário no signup</td>
        </tr>
        <tr>
          <td><strong>Fiscal</strong></td>
          <td>CPF ou CNPJ</td>
          <td>Fornecido para emissão de cobranças</td>
        </tr>
        <tr>
          <td><strong>Uso do Serviço</strong></td>
          <td>Logs de acesso, ações no painel, data/hora de login</td>
          <td>Gerado automaticamente pelo sistema</td>
        </tr>
        <tr>
          <td><strong>Financeiro</strong></td>
          <td>Histórico de cobranças, status de pagamentos</td>
          <td>Processado pela Asaas (não armazenamos dados de cartão)</td>
        </tr>
        <tr>
          <td><strong>Dados CRM</strong></td>
          <td>Contatos, CNPJs, anotações no Pipeline</td>
          <td>Inseridos pelo próprio usuário</td>
        </tr>
        <tr>
          <td><strong>Técnicos</strong></td>
          <td>Endereço IP, tipo de navegador (logs Apache)</td>
          <td>Coletados automaticamente para segurança</td>
        </tr>
      </tbody>
    </table>
    <p><strong>Não coletamos</strong> dados sensíveis (saúde, biometria, origem racial, convicção religiosa, orientação sexual) conforme art. 11 da LGPD.</p>
  </div>

  <div id="finalidade" class="doc-section">
    <h2>// 3. Finalidade e Base Legal</h2>
    <table class="data-table">
      <thead>
        <tr><th>Finalidade</th><th>Base legal (LGPD)</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>Prestação do serviço contratado (acesso ao painel)</td>
          <td>Execução de contrato — art. 7º, V</td>
        </tr>
        <tr>
          <td>Processamento de pagamentos e emissão de cobranças</td>
          <td>Execução de contrato — art. 7º, V</td>
        </tr>
        <tr>
          <td>Comunicações sobre o serviço (avisos, suporte, notas de versão)</td>
          <td>Legítimo interesse — art. 7º, IX</td>
        </tr>
        <tr>
          <td>Segurança, prevenção de fraudes e auditoria</td>
          <td>Legítimo interesse — art. 7º, IX</td>
        </tr>
        <tr>
          <td>Cumprimento de obrigações legais (fiscal, BACEN)</td>
          <td>Obrigação legal — art. 7º, II</td>
        </tr>
        <tr>
          <td>Comunicações de marketing (novidades, upgrades)</td>
          <td>Consentimento — art. 7º, I (opt-in)</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div id="compartilhamento" class="doc-section">
    <h2>// 4. Compartilhamento de Dados</h2>
    <p>Seus dados são compartilhados apenas com:</p>
    <ul>
      <li><strong>Asaas Serviços Financeiros S/A</strong> — processamento de pagamentos. <a href="https://www.asaas.com/privacidade" target="_blank">Política de privacidade Asaas →</a></li>
      <li><strong>Vultr Holdings LLC</strong> — infraestrutura de hospedagem (servidores em que o app roda)</li>
      <li><strong>Receita Federal do Brasil</strong> — consulta de CNPJs (dados públicos, sem envio de dados pessoais)</li>
    </ul>
    <p><strong>Não vendemos, alugamos nem comercializamos seus dados pessoais</strong> com terceiros para fins de marketing.</p>
    <p>Podemos divulgar dados quando exigido por lei, ordem judicial ou autoridade competente.</p>
  </div>

  <div id="retencao" class="doc-section">
    <h2>// 5. Retenção e Exclusão</h2>
    <p>Retemos seus dados pelo tempo necessário para a prestação do serviço e conforme obrigações legais:</p>
    <ul>
      <li><strong>Conta ativa</strong> — durante toda a vigência do contrato</li>
      <li><strong>Após cancelamento</strong> — dados de uso mantidos por <strong>60 dias</strong> para eventual reativação</li>
      <li><strong>Dados fiscais e financeiros</strong> — 5 anos (obrigação legal — Código Tributário Nacional)</li>
      <li><strong>Logs de segurança</strong> — 90 dias</li>
    </ul>
    <p>Para solicitar a exclusão antecipada dos seus dados, envie e-mail para <a href="mailto:privacidade@hermesb2b.co">privacidade@hermesb2b.co</a> com assunto "Exclusão de dados — [seu e-mail]". Atendemos em até 15 dias úteis.</p>
  </div>

  <div id="seguranca" class="doc-section">
    <h2>// 6. Segurança</h2>
    <p>Adotamos as seguintes medidas técnicas e organizacionais para proteger seus dados:</p>
    <ul>
      <li>Transmissão via <strong>HTTPS/TLS</strong> em todas as comunicações</li>
      <li>Senhas armazenadas com <strong>hash bcrypt</strong> (custo 12) — nunca em texto puro</li>
      <li>Dados de cartão processados diretamente pela Asaas (certificada <strong>PCI DSS</strong>) — não passam pelo nosso servidor</li>
      <li>Acesso ao banco de dados restrito à rede interna do servidor</li>
      <li>Headers de segurança HTTP (HSTS, X-Frame-Options, CSP, X-Content-Type-Options)</li>
      <li>Logs de auditoria para ações sensíveis (login, alteração de senha, cancelamento)</li>
    </ul>
    <p>Em caso de incidente de segurança que afete dados pessoais, notificaremos os usuários e a ANPD conforme exigido pela LGPD, em até 72 horas da ciência do incidente.</p>
  </div>

  <div id="cookies" class="doc-section">
    <h2>// 7. Cookies</h2>
    <p>Utilizamos apenas cookies <strong>estritamente necessários</strong> para o funcionamento do serviço:</p>
    <ul>
      <li><strong>hermes_sess</strong> — cookie de sessão autenticada. Expira em 30 dias ou ao fazer logout. Sem esse cookie, não é possível usar o painel.</li>
    </ul>
    <p>Não utilizamos cookies de rastreamento, pixels de anúncio ou ferramentas de analytics de terceiros que coletem dados pessoais.</p>
  </div>

  <div id="direitos" class="doc-section">
    <h2>// 8. Direitos do Titular (LGPD art. 18)</h2>
    <p>Você tem os seguintes direitos em relação aos seus dados pessoais:</p>
    <div class="rights-grid">
      <div class="right-card">
        <div class="icon">👁</div>
        <strong>Acesso</strong>
        <p>Confirmar se tratamos seus dados e receber uma cópia.</p>
      </div>
      <div class="right-card">
        <div class="icon">✏️</div>
        <strong>Correção</strong>
        <p>Corrigir dados incompletos, inexatos ou desatualizados.</p>
      </div>
      <div class="right-card">
        <div class="icon">🗑</div>
        <strong>Exclusão</strong>
        <p>Solicitar a exclusão de dados tratados com base em consentimento.</p>
      </div>
      <div class="right-card">
        <div class="icon">🚫</div>
        <strong>Oposição</strong>
        <p>Opor-se ao tratamento baseado em legítimo interesse.</p>
      </div>
      <div class="right-card">
        <div class="icon">📤</div>
        <strong>Portabilidade</strong>
        <p>Receber seus dados em formato estruturado para outro serviço.</p>
      </div>
      <div class="right-card">
        <div class="icon">ℹ️</div>
        <strong>Informação</strong>
        <p>Saber com quais entidades compartilhamos seus dados.</p>
      </div>
    </div>
    <p>Para exercer qualquer um desses direitos, envie e-mail para <a href="mailto:privacidade@hermesb2b.co">privacidade@hermesb2b.co</a>. Respondemos em até <strong>15 dias úteis</strong>.</p>
  </div>

  <div id="dpo" class="doc-section">
    <h2>// 9. Encarregado de Dados (DPO)</h2>
    <p>O encarregado pelo tratamento de dados pessoais (DPO) da echo_lab pode ser contatado em:</p>
    <div class="highlight-box">
      <strong>DPO — echo_lab / HERMES.b2b</strong><br>
      E-mail: <a href="mailto:privacidade@hermesb2b.co">privacidade@hermesb2b.co</a>
    </div>
  </div>

  <div id="alteracoes" class="doc-section">
    <h2>// 10. Alterações nesta Política</h2>
    <p>Esta Política pode ser atualizada periodicamente. Alterações relevantes serão comunicadas por e-mail com antecedência mínima de <strong>15 dias</strong>. A data de "última atualização" no topo do documento indica a versão vigente.</p>
    <p>Recomendamos a revisão periódica desta página. Dúvidas? Escreva para <a href="mailto:privacidade@hermesb2b.co">privacidade@hermesb2b.co</a>.</p>
  </div>

</div>

<div class="foot">
  <a href="/terms.php">Termos de Uso</a>
  <a href="/privacy.php">Privacidade</a>
  <a href="/login.php">Login</a>
  <a href="https://www.hermesb2b.co" target="_blank">hermesb2b.co</a>
  <span>· by echo_lab · <?= date('Y') ?></span>
</div>

</body>
</html>
