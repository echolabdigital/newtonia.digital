<?php
/**
 * Newton IA — Script de teste de e-mail
 * Acesse: https://app.newtonia.digital/test-mail.php?to=seu@email.com&secret=NEWTON_TEST
 * REMOVA este arquivo após o teste!
 */

define('APP_PATH', __DIR__);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/emails.php';

// Proteção simples contra acesso não autorizado
$secret = $_GET['secret'] ?? '';
if ($secret !== 'NEWTON_TEST') {
    http_response_code(403);
    die('<pre>403 — passe ?secret=NEWTON_TEST na URL</pre>');
}

$to = filter_var($_GET['to'] ?? '', FILTER_VALIDATE_EMAIL);
if (!$to) {
    die('<pre>Informe um e-mail válido: ?to=seu@email.com&secret=NEWTON_TEST</pre>');
}

header('Content-Type: text/html; charset=UTF-8');

// Exibe config atual
$driver  = defined('MAIL_DRIVER') ? MAIL_DRIVER : 'native';
$from    = defined('MAIL_FROM')   ? MAIL_FROM   : '(não definido)';
$appurl  = defined('APP_URL')     ? APP_URL      : '(não definido)';

echo "<h2>Newton IA — Teste de E-mail</h2>";
echo "<pre>";
echo "Driver : {$driver}\n";
echo "From   : {$from}\n";
echo "APP_URL: {$appurl}\n";
echo "Para   : {$to}\n";
echo "---\n";

// Testa 3 templates diferentes
$tests = [
    ['fn' => fn() => email_boas_vindas('Marcus Calixto', 'Echo_Lab', 'Trial', true),
     'label' => 'Boas-vindas (trial)'],
    ['fn' => fn() => email_trial_expirando('Marcus Calixto', 1),
     'label' => 'Trial expirando (1 dia)'],
    ['fn' => fn() => email_redefinir_senha('Marcus Calixto', $appurl . '/reset-password.php?token=TEST123'),
     'label' => 'Redefinir senha'],
];

$ok = 0; $fail = 0;
foreach ($tests as $t) {
    [$subj, $body] = ($t['fn'])();
    $result = hermes_mail($to, '[TESTE] ' . $subj, $body);
    $status = $result ? '✓ OK' : '✗ FALHOU';
    if ($result) $ok++; else $fail++;
    echo "{$status} — {$t['label']}\n";
    echo "       Assunto: {$subj}\n\n";
}

echo "---\n";
echo "Resultado: {$ok} enviado(s), {$fail} falhou(ram)\n";
echo "</pre>";

if ($ok > 0) {
    echo "<p style='color:green'><strong>✓ E-mails enviados. Verifique a caixa de entrada (e spam) de {$to}.</strong></p>";
} else {
    echo "<p style='color:red'><strong>✗ Falha no envio. Verifique o error_log do PHP.</strong></p>";
    echo "<p>Possível causa: MAIL_DRIVER='native' mas o PHP mail() não está configurado no servidor.<br>Tente mudar para driver 'smtp' em config.php com as credenciais do CyberPanel.</p>";
}

echo "<br><p style='color:#888;font-size:.8rem'>⚠ Remova o arquivo test-mail.php após o teste.</p>";
