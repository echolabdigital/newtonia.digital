<?php
/**
 * HERMES.b2b — Templates de e-mail transacional
 *
 * Uso:
 *   hermes_mail($to, ...$email_boas_vindas($name, $company));
 *   // ou separado:
 *   [$subject, $body] = email_boas_vindas($name, $company);
 *   hermes_mail($to, $subject, $body);
 */

// ── Layout base ───────────────────────────────────────────────────────────────

function _email_wrap(string $content, string $footer = ''): string {
    $footer_default = 'Você está recebendo este e-mail porque possui uma conta no HERMES.b2b. '
        . '<a href="https://app.hermesb2b.co" style="color:#10b981">app.hermesb2b.co</a>';
    $footer_text = $footer ?: $footer_default;

    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f6f4ef;font-family:Arial,Helvetica,sans-serif;-webkit-font-smoothing:antialiased">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f4ef;padding:32px 16px">
  <tr><td align="center">
    <table width="100%" style="max-width:560px" cellpadding="0" cellspacing="0">

      <!-- Logo -->
      <tr><td style="padding-bottom:20px">
        <span style="font-family:'Courier New',monospace;font-size:1.05rem;font-weight:700;color:#18181b;letter-spacing:-.01em">
          HERMES<span style="color:#10b981">.b2b</span>
        </span>
      </td></tr>

      <!-- Card -->
      <tr><td style="background:#ffffff;border-radius:12px;border:1px solid #e7e5e0;padding:32px 28px">
        {$content}
      </td></tr>

      <!-- Footer -->
      <tr><td style="padding:20px 4px 0;font-size:.74rem;color:#8b8a93;line-height:1.7;border-top:0">
        <p style="margin:0 0 6px">{$footer_text}</p>
        <p style="margin:0 0 4px">HERMES.b2b · Echo Lab Tecnologia · São Paulo, SP, Brasil</p>
        <p style="margin:0">
          <a href="https://app.hermesb2b.co/app/configuracoes.php" style="color:#8b8a93">Gerenciar notificações</a>
          &nbsp;·&nbsp;
          <a href="mailto:descadastrar@hermesb2b.co?subject=unsubscribe" style="color:#8b8a93">Descadastrar</a>
          &nbsp;·&nbsp;
          <a href="https://hermesb2b.co/privacy.php" style="color:#8b8a93">Privacidade</a>
        </p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

function _email_btn(string $url, string $label): string {
    return <<<HTML
<p style="margin:24px 0">
  <a href="{$url}" style="background:#10b981;color:#ffffff;padding:13px 26px;border-radius:8px;text-decoration:none;font-weight:700;font-size:.95rem;display:inline-block">
    {$label}
  </a>
</p>
HTML;
}

function _email_divider(): string {
    return '<hr style="margin:24px 0;border:none;border-top:1px solid #e7e5e0">';
}

function _email_heading(string $text): string {
    return "<h1 style=\"margin:0 0 8px;font-size:1.3rem;font-weight:700;color:#18181b;letter-spacing:-.02em\">{$text}</h1>";
}

function _email_sub(string $text): string {
    return "<p style=\"margin:0 0 20px;color:#6b7280;font-size:.88rem\">{$text}</p>";
}

function _email_p(string $text): string {
    return "<p style=\"margin:0 0 14px;color:#18181b;font-size:.92rem;line-height:1.65\">{$text}</p>";
}

function _email_highlight(string $text, string $color = '#10b981'): string {
    $bg = $color . '15';
    return <<<HTML
<div style="background:{$bg};border-left:4px solid {$color};padding:14px 16px;border-radius:0 8px 8px 0;margin:16px 0">
  <p style="margin:0;color:{$color};font-weight:700;font-size:.95rem">{$text}</p>
</div>
HTML;
}

function _email_kv(array $items): string {
    $rows = '';
    foreach ($items as [$label, $value]) {
        $rows .= <<<HTML
<tr>
  <td style="padding:7px 0;color:#8b8a93;font-size:.82rem;width:40%;vertical-align:top">{$label}</td>
  <td style="padding:7px 0;color:#18181b;font-weight:600;font-size:.82rem;font-family:'Courier New',monospace">{$value}</td>
</tr>
HTML;
    }
    return "<table width=\"100%\" style=\"border-collapse:collapse;margin:16px 0\">{$rows}</table>";
}

// ── 1. Boas-vindas ────────────────────────────────────────────────────────────

/**
 * E-mail de boas-vindas enviado logo após o cadastro.
 *
 * @param  string $name       Nome do usuário
 * @param  string $company    Nome da empresa
 * @param  string $plan_name  Ex: "Trial", "Pro", "Starter"
 * @param  bool   $is_trial   Se for trial, mostra info do período grátis
 * @return array              [$subject, $body]
 */
function email_boas_vindas(string $name, string $company, string $plan_name = 'Trial', bool $is_trial = true): array {
    $first = explode(' ', trim($name))[0];
    $subject = "Bem-vindo ao HERMES.b2b, {$first}!";

    $plan_info = $is_trial
        ? _email_highlight('🎉 Seu trial de 3 dias começa agora. Sem cartão, sem compromisso.')
        : _email_highlight("✅ Sua conta {$plan_name} está ativa. Bons prospectos!");

    $content = _email_heading("Olá, {$first}! Sua conta está pronta.")
        . _email_sub("Bem-vindo(a) à HERMES.b2b — o stack comercial B2B da {$company}.")
        . $plan_info
        . _email_p('Com o HERMES você acessa <strong>25,3 milhões de empresas ativas</strong>, filtra por vertical, região, porte e muito mais.')
        . _email_p('Para começar, explore o painel de prospecção e encontre seus primeiros leads qualificados:')
        . _email_btn('https://app.hermesb2b.co/app/', 'Acessar o HERMES.b2b →')
        . _email_divider()
        . _email_p('<strong>Dicas para começar:</strong><br>
            ① Use os <strong>filtros de vertical</strong> para encontrar o segmento certo<br>
            ② Ative o <strong>Newton Score</strong> para priorizar leads quentes<br>
            ③ Exporte e conecte com seu CRM')
        . "<p style=\"margin:16px 0 0;font-size:.82rem;color:#8b8a93\">Precisa de ajuda? Responda este e-mail ou acesse <a href=\"https://app.hermesb2b.co\" style=\"color:#10b981\">app.hermesb2b.co</a></p>";

    return [$subject, _email_wrap($content)];
}

// ── 2. Trial expirando (1 dia antes) ─────────────────────────────────────────

/**
 * @param  string $name       Nome do usuário
 * @param  string $company    Nome da empresa
 * @return array              [$subject, $body]
 */
function email_trial_expirando(string $name, string $company): array {
    $first = explode(' ', trim($name))[0];
    $subject = 'Seu trial HERMES.b2b expira amanhã';

    $content = _email_heading('Seu trial expira amanhã ⏰')
        . _email_sub("Olá, {$first} — não perca o acesso à {$company}.")
        . _email_highlight('⚠️ Falta 1 dia para o fim do seu período gratuito.', '#f59e0b')
        . _email_p('Para continuar prospectando com o HERMES.b2b sem interrupção, escolha um plano agora. É rápido e você não perde nenhuma configuração.')
        . _email_btn('https://app.hermesb2b.co/app/billing.php', 'Ver planos e assinar →')
        . _email_divider()
        . _email_p('<strong>O que você perde se não assinar:</strong><br>
            • Acesso à base de 25,3 mi de empresas<br>
            • Newton Score e filtros de qualificação<br>
            • Histórico de leads visitados')
        . "<p style=\"margin:16px 0 0;font-size:.82rem;color:#8b8a93\">Dúvidas sobre os planos? Responda este e-mail.</p>";

    return [$subject, _email_wrap($content)];
}

// ── 3. Trial expirado ─────────────────────────────────────────────────────────

/**
 * @param  string $name       Nome do usuário
 * @param  string $company    Nome da empresa
 * @return array              [$subject, $body]
 */
function email_trial_expirado(string $name, string $company): array {
    $first = explode(' ', trim($name))[0];
    $subject = 'Seu trial HERMES.b2b encerrou';

    $content = _email_heading('Seu período gratuito encerrou.')
        . _email_sub("Olá, {$first} — esperamos que tenha gostado do HERMES.b2b.")
        . _email_highlight('🔒 A conta da ' . htmlspecialchars($company) . ' foi temporariamente suspensa.', '#be123c')
        . _email_p('Seus dados estão seguros e você pode reativar a qualquer momento escolhendo um plano. Nada foi apagado.')
        . _email_btn('https://app.hermesb2b.co/app/billing.php', 'Reativar minha conta →')
        . _email_divider()
        . _email_p('Caso tenha dúvidas ou queira conversar sobre o plano ideal para sua operação, basta responder este e-mail. Estamos aqui.')
        . "<p style=\"margin:16px 0 0;font-size:.82rem;color:#8b8a93\">Conta suspensa pode ser reativada a qualquer momento — seus dados ficam armazenados por 60 dias.</p>";

    return [$subject, _email_wrap($content)];
}

// ── 4. Pagamento confirmado ───────────────────────────────────────────────────

/**
 * @param  string $name         Nome do usuário
 * @param  string $company      Nome da empresa
 * @param  string $plan_name    Ex: "Pro"
 * @param  string $value        Ex: "R$ 297,00"
 * @param  string $period       Ex: "mensal" ou "anual"
 * @param  string $invoice_url  Link da fatura (pode ser vazio)
 * @return array                [$subject, $body]
 */
function email_pagamento_confirmado(string $name, string $company, string $plan_name, string $value, string $period = 'mensal', string $invoice_url = ''): array {
    $first = explode(' ', trim($name))[0];
    $subject = "Pagamento confirmado — HERMES.b2b {$plan_name}";

    $fatura_btn = $invoice_url
        ? _email_btn($invoice_url, '📄 Ver recibo →')
        : '';

    $content = _email_heading('Pagamento confirmado ✅')
        . _email_sub("Obrigado, {$first}! Sua assinatura está ativa.")
        . _email_highlight("✅ Plano {$plan_name} ativo para {$company}.")
        . _email_kv([
            ['Plano', $plan_name],
            ['Valor', $value],
            ['Ciclo', ucfirst($period)],
            ['Status', 'Ativo'],
        ])
        . _email_p('Você já pode usar o HERMES.b2b com acesso completo ao seu plano. Bons prospectos!')
        . _email_btn('https://app.hermesb2b.co/app/', 'Ir para o painel →')
        . $fatura_btn
        . _email_divider()
        . "<p style=\"margin:0;font-size:.82rem;color:#8b8a93\">Gerencie sua assinatura em <a href=\"https://app.hermesb2b.co/app/billing.php\" style=\"color:#10b981\">Minha conta → Assinatura</a>.</p>";

    return [$subject, _email_wrap($content)];
}

// ── 5. Fatura vencida ─────────────────────────────────────────────────────────

/**
 * @param  string $name         Nome do usuário
 * @param  string $company      Nome da empresa
 * @param  string $value        Ex: "R$ 297,00"
 * @param  string $due_date     Ex: "18/05/2026"
 * @param  string $invoice_url  Link para pagar
 * @return array                [$subject, $body]
 */
function email_fatura_vencida(string $name, string $company, string $value, string $due_date, string $invoice_url = ''): array {
    $first = explode(' ', trim($name))[0];
    $subject = 'Fatura vencida — HERMES.b2b';

    $pagar_btn = $invoice_url
        ? _email_btn($invoice_url, '💳 Pagar agora →')
        : _email_btn('https://app.hermesb2b.co/app/billing.php', '💳 Regularizar agora →');

    $content = _email_heading('Fatura em aberto ⚠️')
        . _email_sub("Olá, {$first} — há uma pendência na conta da {$company}.")
        . _email_highlight("⚠️ Fatura de {$value} venceu em {$due_date}.", '#f59e0b')
        . _email_p('Para evitar a suspensão do serviço, regularize o pagamento. Após a confirmação, o acesso é restabelecido automaticamente.')
        . $pagar_btn
        . _email_divider()
        . _email_p('Se já pagou, aguarde alguns minutos para o processamento. Em caso de dúvidas, responda este e-mail.')
        . "<p style=\"margin:0;font-size:.82rem;color:#8b8a93\">O acesso ao HERMES.b2b pode ser suspenso após 7 dias de inadimplência.</p>";

    return [$subject, _email_wrap($content)];
}

// ── 6. Assinatura cancelada ───────────────────────────────────────────────────

/**
 * @param  string $name       Nome do usuário
 * @param  string $company    Nome da empresa
 * @param  string $plan_name  Ex: "Pro"
 * @return array              [$subject, $body]
 */
function email_assinatura_cancelada(string $name, string $company, string $plan_name): array {
    $first = explode(' ', trim($name))[0];
    $subject = 'Assinatura cancelada — HERMES.b2b';

    $content = _email_heading('Assinatura encerrada.')
        . _email_sub("Olá, {$first} — confirmamos o cancelamento da sua conta.")
        . _email_highlight("🔒 Plano {$plan_name} da {$company} cancelado.", '#be123c')
        . _email_p('Seu acesso ao HERMES.b2b foi encerrado. Seus dados ficam armazenados por <strong>60 dias</strong> — caso mude de ideia, é só reativar.')
        . _email_btn('https://app.hermesb2b.co/signup.php', 'Reativar minha conta →')
        . _email_divider()
        . _email_p('Sentimos sua falta. Se quiser nos contar o motivo do cancelamento, responda este e-mail — lemos todos e usamos para melhorar o produto.')
        . "<p style=\"margin:0;font-size:.82rem;color:#8b8a93\">Obrigado por ter feito parte do HERMES.b2b. Até a próxima!</p>";

    return [$subject, _email_wrap($content)];
}

// ── 7. Reembolso processado ───────────────────────────────────────────────────

/**
 * @param  string $name   Nome do usuário
 * @param  string $value  Ex: "R$ 297,00"
 * @return array          [$subject, $body]
 */
function email_reembolso(string $name, string $value): array {
    $first = explode(' ', trim($name))[0];
    $subject = 'Reembolso processado — HERMES.b2b';

    $content = _email_heading('Reembolso processado ✅')
        . _email_sub("Olá, {$first} — seu reembolso foi confirmado.")
        . _email_highlight("✅ Reembolso de {$value} aprovado.")
        . _email_p('O valor será creditado na forma de pagamento original em <strong>até 5 dias úteis</strong>, dependendo da sua operadora.')
        . _email_divider()
        . _email_p('Se tiver dúvidas ou precisar de um comprovante, responda este e-mail.')
        . "<p style=\"margin:0;font-size:.82rem;color:#8b8a93\">Esperamos te ver novamente no HERMES.b2b.</p>";

    return [$subject, _email_wrap($content)];
}
