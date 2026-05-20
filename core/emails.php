<?php
/**
 * Newton IA — Templates de e-mail transacional
 *
 * Uso:
 *   [$subject, $body] = email_boas_vindas($name, $company);
 *   hermes_mail($email, $subject, $body);
 */

// ── Layout base ───────────────────────────────────────────────────────────────

function _email_wrap(string $content, string $footer = ''): string {
    $footer_text = $footer ?: 'Você está recebendo este e-mail porque possui uma conta no Newton IA. '
        . '<a href="https://app.newtonia.digital" style="color:#0ea5e9">app.newtonia.digital</a>';

    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f6f4ef;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f4ef;padding:32px 16px">
  <tr><td align="center">
    <table width="100%" style="max-width:560px" cellpadding="0" cellspacing="0">

      <tr><td style="padding-bottom:20px">
        <span style="font-family:'Courier New',monospace;font-size:1.1rem;font-weight:700;color:#18181b;letter-spacing:-.01em">
          Newton <span style="color:#0ea5e9">IA</span>
        </span>
      </td></tr>

      <tr><td style="background:#ffffff;border-radius:12px;border:1px solid #e7e5e0;padding:32px 28px">
        {$content}
      </td></tr>

      <tr><td style="padding:20px 4px 0;font-size:.74rem;color:#8b8a93;line-height:1.7">
        <p style="margin:0 0 4px">{$footer_text}</p>
        <p style="margin:0 0 4px">Newton IA · Echo_Lab Tech · São Paulo, SP, Brasil</p>
        <p style="margin:0">
          <a href="https://app.newtonia.digital/app/configuracoes.php" style="color:#8b8a93">Gerenciar notificações</a>
          &nbsp;·&nbsp;
          <a href="mailto:contato@newtonia.digital?subject=unsubscribe" style="color:#8b8a93">Descadastrar</a>
          &nbsp;·&nbsp;
          <a href="https://newtonia.digital/privacy.php" style="color:#8b8a93">Privacidade</a>
        </p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

function _email_btn(string $url, string $label, string $color = '#0ea5e9'): string {
    return <<<HTML
<p style="margin:24px 0">
  <a href="{$url}" style="background:{$color};color:#ffffff;padding:13px 26px;border-radius:8px;text-decoration:none;font-weight:700;font-size:.95rem;display:inline-block">
    {$label}
  </a>
</p>
HTML;
}

function _email_divider(): string {
    return '<hr style="margin:24px 0;border:none;border-top:1px solid #e7e5e0">';
}

function _email_h1(string $text): string {
    return "<h1 style=\"margin:0 0 8px;font-size:1.3rem;font-weight:700;color:#18181b;letter-spacing:-.02em\">{$text}</h1>";
}

function _email_sub(string $text): string {
    return "<p style=\"margin:0 0 20px;color:#6b7280;font-size:.88rem\">{$text}</p>";
}

function _email_p(string $text): string {
    return "<p style=\"margin:0 0 16px;color:#374151;font-size:.92rem;line-height:1.6\">{$text}</p>";
}

function _email_highlight(string $text, string $color = '#0ea5e9'): string {
    $bg = $color === '#0ea5e9' ? '#f0f9ff' : '#fefce8';
    $tc = $color === '#0ea5e9' ? '#0c4a6e' : '#92400e';
    return "<p style=\"margin:0 0 20px;background:{$bg};border-left:3px solid {$color};padding:12px 16px;border-radius:0 8px 8px 0;font-size:.92rem;color:{$tc}\">{$text}</p>";
}

function _email_list(array $items): string {
    $li = '';
    foreach ($items as $item) {
        $li .= "<li style=\"margin:0 0 8px;color:#374151;font-size:.92rem\">{$item}</li>";
    }
    return "<ul style=\"margin:0 0 20px;padding-left:20px\">{$li}</ul>";
}

// ── Templates ─────────────────────────────────────────────────────────────────

/**
 * Boas-vindas após cadastro.
 * Trigger: signup.php
 */
function email_boas_vindas(string $name, string $company, string $plan_name = 'Trial', bool $is_trial = false): array
{
    $subject = "Bem-vindo ao Newton IA, {$name}!";
    $trial_note = $is_trial
        ? _email_highlight('Seu trial de 7 dias começou. Explore todos os recursos sem compromisso.')
        : '';

    $body = _email_wrap(
        _email_h1("Olá, {$name}! 👋") .
        _email_sub("Bem-vindo(a) ao Newton IA — plano <strong>{$plan_name}</strong>.") .
        $trial_note .
        _email_p("Sua conta para <strong>" . htmlspecialchars($company) . "</strong> está pronta. Crie seu primeiro agente de IA e conecte ao WhatsApp em minutos.") .
        _email_list([
            '🤖 Crie agentes com personalidade e instruções customizadas',
            '📱 Conecte ao WhatsApp via QR Code em segundos',
            '💬 Monitore conversas em tempo real no Inbox',
        ]) .
        _email_btn('https://app.newtonia.digital/app/agents.php', 'Criar meu primeiro agente →') .
        _email_divider() .
        _email_p('Precisando de ajuda? Responda este e-mail ou acesse nossa central de suporte.')
    );
    return [$subject, $body];
}

/**
 * Redefinição de senha.
 * Trigger: forgot-password.php
 */
function email_redefinir_senha(string $name, string $link): array
{
    $subject = 'Redefina sua senha — Newton IA';
    $body = _email_wrap(
        _email_h1('Redefinir senha') .
        _email_sub('Recebemos uma solicitação para redefinir sua senha.') .
        _email_p("Olá, <strong>" . htmlspecialchars($name) . "</strong>. Clique no botão abaixo para criar uma nova senha. O link expira em <strong>1 hora</strong>.") .
        _email_btn($link, 'Redefinir minha senha →') .
        _email_p("<span style=\"font-size:.82rem;color:#8b8a93\">Ou copie: <a href=\"{$link}\" style=\"color:#0ea5e9\">{$link}</a></span>") .
        _email_divider() .
        _email_p('Se você não solicitou a redefinição, ignore este e-mail — sua senha permanece a mesma.')
    );
    return [$subject, $body];
}

// Alias legado
function email_reset_senha(string $name, string $link): array { return email_redefinir_senha($name, $link); }

/**
 * Trial expirando em breve.
 * Trigger: cron/expire-trials.php — 1 dia antes
 */
function email_trial_expirando(string $name, int $diasRestantes): array
{
    $d = $diasRestantes === 1 ? 'amanhã' : "em {$diasRestantes} dias";
    $subject = "Seu trial encerra {$d} — Newton IA";
    $body = _email_wrap(
        _email_h1('Seu trial está acabando ⏰') .
        _email_highlight("Seu período gratuito encerra {$d}.", '#f59e0b') .
        _email_p("Olá, <strong>" . htmlspecialchars($name) . "</strong>! Assine agora para continuar usando seus agentes sem interrupção.") .
        _email_list([
            '✅ Todos os agentes e conversas são mantidos',
            '✅ Sem perda de configurações ou histórico',
            '✅ Cancele quando quiser',
        ]) .
        _email_btn('https://app.newtonia.digital/app/billing.php', 'Escolher meu plano →') .
        _email_divider() .
        _email_p('Tem dúvidas sobre os planos? Responda este e-mail.')
    );
    return [$subject, $body];
}

/**
 * Trial expirado — conta suspensa.
 * Trigger: cron/expire-trials.php — ao expirar
 */
function email_trial_expirado(string $name): array
{
    $subject = 'Seu trial encerrou — Newton IA';
    $body = _email_wrap(
        _email_h1('Seu trial encerrou') .
        _email_p("Olá, <strong>" . htmlspecialchars($name) . "</strong>. Seu período de teste gratuito chegou ao fim e seus agentes foram pausados.") .
        _email_p('Assine um plano para reativar tudo em menos de 1 minuto. Seus dados e configurações estão preservados.') .
        _email_btn('https://app.newtonia.digital/app/billing.php', 'Reativar minha conta →') .
        _email_divider() .
        _email_p('Seus agentes, conversas e configurações ficam armazenados por <strong>30 dias</strong>.')
    );
    return [$subject, $body];
}

// Alias legado
function email_conta_suspensa(string $name): array { return email_trial_expirado($name); }

/**
 * Pagamento confirmado / assinatura ativa.
 * Trigger: webhooks/asaas.php — PAYMENT_CONFIRMED / PAYMENT_RECEIVED
 */
function email_pagamento_confirmado(string $name, string $company, string $plan, string $valor, string $period = 'mensal', string $inv_url = ''): array
{
    $subject = 'Pagamento confirmado — Newton IA';
    $inv_btn = $inv_url ? _email_btn($inv_url, 'Ver recibo →', '#374151') : '';
    $body = _email_wrap(
        _email_h1('Pagamento confirmado ✓') .
        _email_highlight("Plano {$plan} · {$valor} · {$period}") .
        _email_p("Olá, <strong>" . htmlspecialchars($name) . "</strong>! Recebemos seu pagamento referente ao plano <strong>{$plan}</strong> de <strong>" . htmlspecialchars($company) . "</strong>.") .
        _email_p('Seus agentes estão ativos e prontos para atender 24/7.') .
        _email_btn('https://app.newtonia.digital/app/', 'Acessar o painel →') .
        $inv_btn
    );
    return [$subject, $body];
}

/**
 * Fatura vencida.
 * Trigger: webhooks/asaas.php — PAYMENT_OVERDUE
 */
function email_fatura_vencida(string $name, string $company, string $valor, string $due_date, string $inv_url = ''): array
{
    $subject = "Fatura vencida — Newton IA";
    $inv_btn = $inv_url ? _email_btn($inv_url, 'Pagar agora →') : _email_btn('https://app.newtonia.digital/app/billing.php', 'Pagar agora →');
    $body = _email_wrap(
        _email_h1('Fatura em aberto ⚠') .
        _email_highlight("Vencida em {$due_date} · {$valor}", '#f59e0b') .
        _email_p("Olá, <strong>" . htmlspecialchars($name) . "</strong>. A fatura de <strong>" . htmlspecialchars($company) . "</strong> está vencida.") .
        _email_p('Para evitar a suspensão dos seus agentes, efetue o pagamento o quanto antes.') .
        $inv_btn .
        _email_divider() .
        _email_p('Se já pagou, aguarde até 2 dias úteis para processamento. Dúvidas? Responda este e-mail.')
    );
    return [$subject, $body];
}

/**
 * Assinatura cancelada.
 * Trigger: webhooks/asaas.php — SUBSCRIPTION_INACTIVATED
 */
function email_assinatura_cancelada(string $name, string $company, string $plan): array
{
    $subject = 'Assinatura cancelada — Newton IA';
    $body = _email_wrap(
        _email_h1('Assinatura cancelada') .
        _email_p("Olá, <strong>" . htmlspecialchars($name) . "</strong>. A assinatura do plano <strong>{$plan}</strong> de <strong>" . htmlspecialchars($company) . "</strong> foi cancelada.") .
        _email_p('Seus dados ficam armazenados por 30 dias. Se mudar de ideia, basta reativar a qualquer momento.') .
        _email_btn('https://app.newtonia.digital/app/billing.php', 'Reativar assinatura →', '#374151') .
        _email_divider() .
        _email_p('Sentimos sua falta. Se quiser nos contar o motivo do cancelamento, responda este e-mail.')
    );
    return [$subject, $body];
}

/**
 * Conta reativada após pagamento de fatura vencida.
 * Trigger: webhooks/asaas.php — PAYMENT_CONFIRMED quando tenant estava suspended
 */
function email_conta_reativada(string $name, string $plan): array
{
    $subject = 'Conta reativada — Newton IA';
    $body = _email_wrap(
        _email_h1('Conta reativada ✓') .
        _email_highlight("Plano {$plan} ativo novamente.") .
        _email_p("Olá, <strong>" . htmlspecialchars($name) . "</strong>! Seu pagamento foi confirmado e seus agentes estão ativos novamente.") .
        _email_btn('https://app.newtonia.digital/app/', 'Acessar o painel →')
    );
    return [$subject, $body];
}

/**
 * Reembolso processado.
 * Trigger: webhooks/asaas.php — PAYMENT_REFUNDED
 */
function email_reembolso(string $name, string $value): array
{
    $first   = explode(' ', $name)[0];
    $subject = 'Reembolso processado — Newton IA';
    $body = _email_wrap(
        _email_h1('Reembolso processado ✅') .
        _email_highlight("Reembolso de {$value} aprovado.") .
        _email_p("Olá, <strong>" . htmlspecialchars($first) . "</strong>! O valor será creditado na forma de pagamento original em até <strong>5 dias úteis</strong>.") .
        _email_divider() .
        _email_p('Dúvidas? Responda este e-mail que nossa equipe te atende.')
    );
    return [$subject, $body];
}
