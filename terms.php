<?php
/**
 * Newton IA — Termos de Uso
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
<title>Termos de Uso — Newton IA</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root { --newton:#0ea5e9; --ink:#18181b; --mute:#8b8a93; --line:#e7e5e0; --bone:#f6f4ef; }
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
  .doc-section ul, .doc-section ol { padding-left: 20px; margin-bottom: 14px; }
  .doc-section li { font-size: .9rem; color: #3f3f46; line-height: 1.75; margin-bottom: 4px; }
  .doc-section a { color: var(--hermes); text-decoration: none; }
  .doc-section a:hover { text-decoration: underline; }

  .highlight-box { background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px; font-size: .88rem; color: #166534; line-height: 1.6; }
  .warn-box { background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px; font-size: .88rem; color: #78350f; line-height: 1.6; }

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
    <div class="mono">// legal</div>
    <h1>Termos de Uso</h1>
    <div class="meta">Última atualização: <?= $last_updated ?> · Newton IA by echo_lab</div>
  </div>

  <div class="toc">
    <h3>// índice</h3>
    <ol>
      <li><a href="#aceitacao">Aceitação dos Termos</a></li>
      <li><a href="#servico">Descrição do Serviço</a></li>
      <li><a href="#conta">Conta e Responsabilidades</a></li>
      <li><a href="#planos">Planos, Pagamentos e Cancelamento</a></li>
      <li><a href="#uso-aceitavel">Uso Aceitável</a></li>
      <li><a href="#propriedade">Propriedade Intelectual</a></li>
      <li><a href="#dados">Dados e Privacidade</a></li>
      <li><a href="#disponibilidade">Disponibilidade e SLA</a></li>
      <li><a href="#responsabilidade">Limitação de Responsabilidade</a></li>
      <li><a href="#rescisao">Rescisão</a></li>
      <li><a href="#alteracoes">Alterações nos Termos</a></li>
      <li><a href="#lei">Lei Aplicável e Foro</a></li>
      <li><a href="#contato">Contato</a></li>
    </ol>
  </div>

  <div id="aceitacao" class="doc-section">
    <h2>// 1. Aceitação dos Termos</h2>
    <p>Ao criar uma conta ou utilizar o <strong>Newton IA</strong> ("Serviço"), operado pela <strong>echo_lab</strong> ("Empresa", "nós"), você ("Usuário", "Cliente") concorda integralmente com estes Termos de Uso e com nossa <a href="/privacy.php">Política de Privacidade</a>.</p>
    <p>Se você não concordar com estes termos, não utilize o Serviço. O uso continuado após alterações implica aceitação das novas condições.</p>
  </div>

  <div id="servico" class="doc-section">
    <h2>// 2. Descrição do Serviço</h2>
    <p>O Newton IA é uma plataforma SaaS de prospecção e gestão comercial B2B, composta pelos módulos:</p>
    <ul>
      <li><strong>Radar Leads</strong> — pesquisa e qualificação de CNPJs da Receita Federal</li>
      <li><strong>Pipeline</strong> — CRM Kanban para gestão de oportunidades</li>
      <li><strong>Mail Lab</strong> — integração de e-mail comercial</li>
      <li><strong>Signal, Whats Lab, Pitch</strong> — módulos em desenvolvimento, disponíveis conforme plano</li>
    </ul>
    <p>O Serviço é prestado na modalidade <strong>SaaS (Software as a Service)</strong>, acessível via navegador em <code>app.newtonia.digital</code>. A Empresa se reserva o direito de adicionar, modificar ou remover funcionalidades mediante aviso prévio.</p>
  </div>

  <div id="conta" class="doc-section">
    <h2>// 3. Conta e Responsabilidades</h2>
    <h3>3.1 Cadastro</h3>
    <p>Para usar o Serviço, você deve fornecer informações verdadeiras, precisas e atualizadas. A Empresa pode suspender contas com dados falsos.</p>
    <h3>3.2 Credenciais</h3>
    <p>Você é responsável pela confidencialidade de sua senha e por todas as atividades realizadas sob sua conta. Notifique-nos imediatamente em caso de uso não autorizado: <a href="mailto:suporte@newtonia.digital">suporte@newtonia.digital</a>.</p>
    <h3>3.3 Titularidade</h3>
    <p>Contas de pessoa jurídica podem ter múltiplos usuários conforme o plano contratado. O titular da conta é responsável pelos atos de todos os usuários vinculados.</p>
  </div>

  <div id="planos" class="doc-section">
    <h2>// 4. Planos, Pagamentos e Cancelamento</h2>
    <h3>4.1 Trial</h3>
    <p>O período de teste gratuito é de <strong>3 (três) dias</strong>, sem necessidade de cartão de crédito, com os limites do plano Trial. Ao final, a conta é suspensa automaticamente caso nenhum plano pago seja contratado.</p>
    <h3>4.2 Pagamento</h3>
    <p>Os pagamentos são processados pela <strong>Asaas Serviços Financeiros S/A</strong>, plataforma certificada PCI DSS. O Newton IA não armazena dados de cartão de crédito.</p>
    <p>São aceitas as formas de pagamento: <strong>PIX</strong> e <strong>Cartão de Crédito</strong> (recorrente). O vencimento das cobranças mensais segue a data de início da assinatura.</p>
    <h3>4.3 Inadimplência</h3>
    <p>Cobranças não pagas dentro de <strong>7 (sete) dias</strong> do vencimento resultam na suspensão automática do acesso. A reativação ocorre após a confirmação do pagamento.</p>
    <h3>4.4 Cancelamento</h3>
    <p>O cancelamento pode ser realizado a qualquer momento pelo próprio usuário em <strong>Painel → Plano e Cobranças → Cancelar assinatura</strong>. O acesso é mantido até o fim do período pago. <strong>Não há reembolso proporcional</strong> por período não utilizado.</p>
    <h3>4.5 Reajuste</h3>
    <p>Os preços podem ser reajustados com aviso prévio de <strong>30 (trinta) dias</strong> por e-mail. O reajuste será aplicado na próxima renovação após o aviso.</p>
  </div>

  <div id="uso-aceitavel" class="doc-section">
    <h2>// 5. Uso Aceitável</h2>
    <div class="warn-box">
      ⚠ O descumprimento das políticas de uso pode resultar na suspensão imediata da conta sem reembolso.
    </div>
    <p>É <strong>proibido</strong> utilizar o Serviço para:</p>
    <ul>
      <li>Enviar spam, mensagens não solicitadas ou comunicações em massa não autorizadas</li>
      <li>Violar a LGPD (Lei 13.709/2018) ou outras legislações aplicáveis à proteção de dados</li>
      <li>Revender, sublicenciar ou redistribuir o acesso ao Serviço sem autorização expressa</li>
      <li>Realizar engenharia reversa, descompilar ou tentar extrair o código-fonte</li>
      <li>Sobrecarregar intencionalmente a infraestrutura do Serviço (ataques DDoS, scraping massivo)</li>
      <li>Utilizar os dados obtidos via Radar Leads para fins fraudulentos, discriminatórios ou ilegais</li>
      <li>Criar contas falsas ou múltiplas contas para contornar os limites do plano</li>
    </ul>
    <h3>5.1 Dados da Receita Federal</h3>
    <p>Os CNPJs disponibilizados pelo Radar Leads são dados públicos da Receita Federal do Brasil. O usuário é integralmente responsável pelo uso que fizer dessas informações, devendo respeitar a finalidade legítima de prospecção B2B e as disposições da LGPD.</p>
  </div>

  <div id="propriedade" class="doc-section">
    <h2>// 6. Propriedade Intelectual</h2>
    <p>O Serviço, incluindo software, design, marca Newton IA, logotipos, textos e demais elementos, é de propriedade exclusiva da echo_lab e está protegido por leis de propriedade intelectual.</p>
    <p>O usuário recebe uma licença <strong>limitada, não exclusiva, intransferível e revogável</strong> para acessar e usar o Serviço durante o período de assinatura vigente.</p>
    <p><strong>Seus dados</strong> (leads, cards de Pipeline, listas etc.) são de sua propriedade. A echo_lab não reivindica propriedade sobre o conteúdo inserido pelo usuário.</p>
  </div>

  <div id="dados" class="doc-section">
    <h2>// 7. Dados e Privacidade</h2>
    <p>O tratamento de dados pessoais é regido pela nossa <a href="/privacy.php">Política de Privacidade</a>, em conformidade com a LGPD (Lei 13.709/2018).</p>
    <p>Em caso de cancelamento, seus dados ficam disponíveis por <strong>60 (sessenta) dias</strong> para eventuais exportações. Após esse prazo, os dados podem ser removidos permanentemente.</p>
  </div>

  <div id="disponibilidade" class="doc-section">
    <h2>// 8. Disponibilidade e SLA</h2>
    <p>A Empresa se esforça para manter o Serviço disponível <strong>99% do tempo</strong> (≈ 7,2h de downtime/mês), excluindo manutenções programadas anunciadas com antecedência.</p>
    <p>Manutenções planejadas são comunicadas com pelo menos <strong>24 horas de antecedência</strong> por e-mail e/ou aviso no painel. Não há garantia de SLA para planos Trial e Starter.</p>
  </div>

  <div id="responsabilidade" class="doc-section">
    <h2>// 9. Limitação de Responsabilidade</h2>
    <p>Na máxima extensão permitida pela lei, a Empresa não será responsável por:</p>
    <ul>
      <li>Perda de receita, lucros cessantes ou danos indiretos decorrentes do uso ou impossibilidade de uso do Serviço</li>
      <li>Decisões comerciais tomadas com base nos dados do Radar Leads</li>
      <li>Falhas de terceiros (Asaas, Receita Federal, provedores de e-mail, infraestrutura)</li>
      <li>Perda de dados causada por ação do próprio usuário</li>
    </ul>
    <p>A responsabilidade total da Empresa, em qualquer hipótese, fica limitada ao valor pago pelo usuário nos últimos <strong>3 (três) meses</strong> de assinatura.</p>
  </div>

  <div id="rescisao" class="doc-section">
    <h2>// 10. Rescisão</h2>
    <p>A Empresa pode encerrar ou suspender o acesso de um usuário, a qualquer momento e sem aviso prévio, em caso de violação destes Termos, fraude, uso abusivo ou determinação legal.</p>
    <p>O usuário pode encerrar sua conta a qualquer momento via painel ou solicitando ao suporte. A rescisão não exime o pagamento de valores em aberto.</p>
  </div>

  <div id="alteracoes" class="doc-section">
    <h2>// 11. Alterações nos Termos</h2>
    <p>A Empresa pode atualizar estes Termos periodicamente. Alterações substanciais serão comunicadas por e-mail com antecedência mínima de <strong>15 dias</strong>. O uso continuado após o aviso implica aceitação das novas condições.</p>
  </div>

  <div id="lei" class="doc-section">
    <h2>// 12. Lei Aplicável e Foro</h2>
    <p>Estes Termos são regidos pelas leis da <strong>República Federativa do Brasil</strong>. As partes elegem o foro da Comarca de <strong>São Paulo/SP</strong> para dirimir quaisquer controvérsias decorrentes deste instrumento, com renúncia a qualquer outro por mais privilegiado que seja.</p>
  </div>

  <div id="contato" class="doc-section">
    <h2>// 13. Contato</h2>
    <div class="highlight-box">
      <strong>echo_lab — Newton IA</strong><br>
      E-mail: <a href="mailto:suporte@newtonia.digital">suporte@newtonia.digital</a><br>
      Site: <a href="https://www.newtonia.digital" target="_blank">newtonia.digital</a>
    </div>
  </div>

</div>

<div class="foot">
  <a href="/terms.php">Termos de Uso</a>
  <a href="/privacy.php">Privacidade</a>
  <a href="/login.php">Login</a>
  <a href="https://www.newtonia.digital" target="_blank">newtonia.digital</a>
  <span>· by echo_lab · <?= date('Y') ?></span>
</div>

</body>
</html>
