<?php
/**
 * Newton IA — Templates de e-mail transacional
 */

function _email_wrap(string $content, string $footer = ''): string {
    $footer_default = 'Você está recebendo este e-mail porque possui uma conta no Newton IA. '
        . '<a href="https://app.newtonia.digital" style="color:#0ea5e9">app.newtonia.digital</a>';
    $footer_text = $footer ?: $footer_default;

    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f6f4ef;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f4ef;padding:32px 16px">
  <tr><td align="center">
    <table width="100%" style="max-width:560px" cellpadding="0" cellspacing="0">

      <tr><td style="padding-bottom:20px">
        <span style="font-family:'Courier New',monospace;font-size:1.05rem;font-weight:700;color:#18181b">
          Newton <span style="color:#0ea5e9">IA</span>
        </span>
      </td></tr>

      <tr><td style="background:#ffffff;border-radius:12px;border:1px solid #e7e5e0;padding:32px 28px">
        {$content}
      </td></tr>

      <tr><td style="padding:20px 4px 0;font-size:.74rem;color:#8b8a93;line-height:1.7">
        <p style="margin:0 0 6px">{$footer_text}</p>
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

function _email_btn(string $url, string $label): string {
    return <<<HTML
<p style="margin:24px 0">
  <a href="{$url}" style="background:#0ea5e9;color:#ffffff;padding:13px 26px;border-radius:8px;text-decoration:none;font-weight:700;font-size:.95rem;display:inline-block">
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
    return "<p style=\"margin:0 0 16px;color:#374151;font-size:.92rem;line-height:1.6\">{$text}</p>";
}

function _email_highlight(string $text): string {
    return "<p style=\"margin:0 0 20px;background:#f0f9ff;border-left:3px solid #0ea5e9;padding:12px 16px;border-radius:0 8px 8px 0;font-size:.92rem;color:#0c4a6e\">{$text}</p>";
}

// ── Templates ─────────────────────────────────────────────────────────────────

function email_boas_vindas(string $name, string $company): array
{
    $subject = "Bem-vindo ao Newton IA, {$name}!";
    $body = _email_wrap(
        _email_heading("Olá, {$name}! 👋") .
        _email_sub("Bem-vindo(a) ao Newton IA — sua operação agora roda 24/7.") .
        _email_p("Sua conta para <strong>{$company}</strong> foi criada com sucesso. Crie seus primeiros agentes de IA e conecte-os ao WhatsApp em minutos.") .
        _email_btn('https://app.newtonia.digital/app/agents.php', 'Criar meu primeiro agente →') .
        _email_divider() .
        _email_p('Precisa de ajuda? Acesse nossa base de conhecimento ou fale com o suporte.')
    );
    return [$subject, $body];
}

function email_reset_senha(string $name, string $link): array
{
    $subject = 'Redefinição de senha — Newton IA';
    $body = _email_wrap(
        _email_heading('Redefinir senha') .
        _email_sub('Recebemos uma solicitação para redefinir sua senha.') .
        _email_p("Olá, <strong>{$name}</strong>. Clique no botão abaixo para criar uma nova senha. O link expira em 2 horas.") .
        _email_btn($link, 'Redefinir senha →') .
        _email_divider() .
        _email_p('Se você não solicitou a redefinição, ignore este e-mail.')
    );
    return [$subject, $body];
}

function email_pagamento_confirmado(string $name, string $plan, string $valor): array
{
    $subject = 'Pagamento confirmado — Newton IA';
    $body = _email_wrap(
        _email_heading('Pagamento confirmado ✓') .
        _email_highlight("Plano {$plan} · {$valor}") .
        _email_p("Olá, <strong>{$name}</strong>! Seu pagamento foi confirmado e seus agentes estão prontos para atender 24/7.") .
        _email_btn('https://app.newtonia.digital/app/', 'Acessar o painel →')
    );
    return [$subject, $body];
}

function email_trial_expirando(string $name, int $diasRestantes): array
{
    $d = $diasRestantes > 1 ? "{$diasRestantes} dias" : "1 dia";
    $subject = "Seu trial encerra em {$d} — Newton IA";
    $body = _email_wrap(
        _email_heading("Seu trial encerra em breve ⏰") .
        _email_p("Olá, <strong>{$name}</strong>! Faltam <strong>{$d}</strong> para o fim do seu período gratuito.") .
        _email_p('Escolha um plano e continue usando seus agentes sem interrupção.') .
        _email_btn('https://app.newtonia.digital/app/billing.php', 'Ver planos →')
    );
    return [$subject, $body];
}

function email_conta_suspensa(string $name): array
{
    $subject = 'Conta suspensa — Newton IA';
    $body = _email_wrap(
        _email_heading('Conta suspensa') .
        _email_p("Olá, <strong>{$name}</strong>. Sua conta foi suspensa por falta de pagamento.") .
        _email_p('Regularize sua situação para reativar seus agentes.') .
        _email_btn('https://app.newtonia.digital/app/billing.php', 'Regularizar conta →')
    );
    return [$subject, $body];
}

function email_reembolso(string $name, string $value): array
{
    $subject = 'Reembolso processado — Newton IA';
    $first   = explode(' ', $name)[0];
    $body = _email_wrap(
        _email_heading('Reembolso processado ✅') .
        _email_highlight("Reembolso de {$value} aprovado.") .
        _email_p("Olá, <strong>{$first}</strong>! O valor será creditado na forma de pagamento original em até 5 dias úteis.") .
        _email_divider() .
        _email_p('Se tiver dúvidas, responda este e-mail.')
    );
    return [$subject, $body];
}
