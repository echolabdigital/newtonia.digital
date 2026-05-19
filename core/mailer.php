<?php
/**
 * HERMES.b2b — Abstração de envio de e-mail
 *
 * Em produção: troca o driver abaixo por 'postmark' ou 'sendgrid'
 * definindo MAIL_DRIVER no config.php ou variável de ambiente.
 * Em local/sandbox: usa mail() nativo do PHP.
 *
 * Uso:
 *   hermes_mail('destino@email.com', 'Assunto', '<p>HTML body</p>');
 */

/**
 * Envia um e-mail transacional.
 *
 * @param  string $to       Endereço de destino
 * @param  string $subject  Assunto
 * @param  string $body     Corpo em HTML (plain-text gerado automaticamente)
 * @param  array  $opts     ['from'=>'...', 'reply_to'=>'...', 'cc'=>'...']
 * @return bool             true se aceito pelo driver, false em erro
 */
function hermes_mail(string $to, string $subject, string $body, array $opts = []): bool {
    $driver = defined('MAIL_DRIVER') ? MAIL_DRIVER : (getenv('MAIL_DRIVER') ?: 'native');

    return match ($driver) {
        'postmark'  => _hermes_mail_postmark($to, $subject, $body, $opts),
        'sendgrid'  => _hermes_mail_sendgrid($to, $subject, $body, $opts),
        'smtp'      => _hermes_mail_smtp($to, $subject, $body, $opts),
        default     => _hermes_mail_native($to, $subject, $body, $opts),
    };
}

// ── Drivers ──────────────────────────────────────────────────────────────────

function _hermes_mail_native(string $to, string $subject, string $body, array $opts): bool {
    $from      = $opts['from']     ?? (defined('MAIL_FROM')    ? MAIL_FROM    : 'noreply@hermesb2b.co');
    $fromName  = $opts['fromName'] ?? (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'HERMES.b2b');
    $replyTo   = $opts['reply_to'] ?? (defined('MAIL_REPLY_TO') ? MAIL_REPLY_TO : $from);
    $msgId     = '<' . uniqid('hermes.', true) . '@hermesb2b.co>';
    $date      = date('r');
    $unsubUrl  = 'https://app.hermesb2b.co/app/configuracoes.php';

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: quoted-printable\r\n";
    $headers .= "From: {$fromName} <{$from}>\r\n";
    $headers .= "Reply-To: {$replyTo}\r\n";
    $headers .= "Date: {$date}\r\n";
    $headers .= "Message-ID: {$msgId}\r\n";
    $headers .= "List-Unsubscribe: <mailto:descadastrar@hermesb2b.co?subject=unsubscribe>, <{$unsubUrl}>\r\n";
    $headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
    $headers .= "X-Mailer: HERMES.b2b\r\n";
    $headers .= "X-Priority: 3\r\n";

    if (!empty($opts['cc'])) {
        $headers .= "Cc: {$opts['cc']}\r\n";
    }

    // quoted-printable encoding para compatibilidade máxima
    $body_qp = quoted_printable_encode($body);

    // -f força o envelope sender (MAIL FROM) — obrigatório para o relay CyberMail
    $result = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body_qp, $headers, '-f ' . $from);
    if (!$result) {
        error_log("[hermes_mail/native] Falha ao enviar para {$to} — assunto: {$subject}");
    }
    return (bool) $result;
}

function _hermes_mail_smtp(string $to, string $subject, string $body, array $opts): bool {
    $host     = defined('SMTP_HOST')     ? SMTP_HOST     : (getenv('SMTP_HOST')     ?: '');
    $port     = defined('SMTP_PORT')     ? (int)SMTP_PORT : (int)(getenv('SMTP_PORT') ?: 587);
    $user     = defined('SMTP_USER')     ? SMTP_USER     : (getenv('SMTP_USER')     ?: '');
    $pass     = defined('SMTP_PASS')     ? SMTP_PASS     : (getenv('SMTP_PASS')     ?: '');
    $from     = $opts['from']     ?? (defined('MAIL_FROM')      ? MAIL_FROM      : 'noreply@hermesb2b.co');
    $fromName = $opts['fromName'] ?? (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'HERMES.b2b');
    $replyTo  = $opts['reply_to'] ?? $from;

    if (!$host || !$user) {
        error_log('[hermes_mail/smtp] SMTP_HOST ou SMTP_USER não configurados.');
        return false;
    }

    // Suporte a TLS (porta 587) e SSL (porta 465)
    $prefix = ($port === 465) ? 'ssl://' : '';
    $errno  = 0; $errstr = '';
    $sock = @fsockopen($prefix . $host, $port, $errno, $errstr, 10);
    if (!$sock) {
        error_log("[hermes_mail/smtp] Falha na conexão {$host}:{$port} — {$errstr}");
        return false;
    }

    $read = fn() => fgets($sock, 512);
    $send = function(string $cmd) use ($sock, &$read): string {
        fwrite($sock, $cmd . "\r\n");
        return $read();
    };

    $read(); // banner
    $send("EHLO hermesb2b.co");
    // Lê todas as linhas do EHLO (multi-line)
    while (true) { $l = $read(); if ($l === false || substr($l, 3, 1) === ' ') break; }

    // STARTTLS se porta 587
    if ($port === 587) {
        $send("STARTTLS");
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $send("EHLO hermesb2b.co");
        while (true) { $l = $read(); if ($l === false || substr($l, 3, 1) === ' ') break; }
    }

    // Auth LOGIN
    $send("AUTH LOGIN");
    $send(base64_encode($user));
    $authResp = $send(base64_encode($pass));
    if (!str_starts_with(trim($authResp), '235')) {
        error_log("[hermes_mail/smtp] Auth falhou: {$authResp}");
        fclose($sock);
        return false;
    }

    $send("MAIL FROM:<{$from}>");
    $send("RCPT TO:<{$to}>");
    $send("DATA");

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $date    = date('r');
    $msgId   = '<' . uniqid('hermes', true) . '@hermesb2b.co>';
    $plain   = wordwrap(strip_tags($body), 998);
    $htmlB64 = chunk_split(base64_encode($body));
    $plainB64= chunk_split(base64_encode($plain));
    $boundary= 'b_' . md5(uniqid());

    $msg  = "Date: {$date}\r\n";
    $msg .= "From: {$fromName} <{$from}>\r\n";
    $msg .= "To: {$to}\r\n";
    $msg .= "Reply-To: {$replyTo}\r\n";
    $msg .= "Subject: {$encodedSubject}\r\n";
    $msg .= "Message-ID: {$msgId}\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
    $msg .= "--{$boundary}\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n{$plainB64}\r\n";
    $msg .= "--{$boundary}\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n{$htmlB64}\r\n";
    $msg .= "--{$boundary}--\r\n";

    fwrite($sock, $msg . "\r\n.\r\n");
    $dataResp = $read();
    $send("QUIT");
    fclose($sock);

    if (!str_starts_with(trim($dataResp), '250')) {
        error_log("[hermes_mail/smtp] Envio falhou para {$to}: {$dataResp}");
        return false;
    }
    return true;
}

function _hermes_mail_postmark(string $to, string $subject, string $body, array $opts): bool {
    $apiKey   = defined('POSTMARK_API_KEY') ? POSTMARK_API_KEY : getenv('POSTMARK_API_KEY');
    $from     = $opts['from'] ?? (defined('MAIL_FROM') ? MAIL_FROM : 'noreply@hermesb2b.co');
    $fromName = $opts['fromName'] ?? (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'HERMES.b2b');

    if (!$apiKey) {
        error_log('[hermes_mail/postmark] POSTMARK_API_KEY não configurada.');
        return false;
    }

    $payload = json_encode([
        'From'     => "{$fromName} <{$from}>",
        'To'       => $to,
        'Subject'  => $subject,
        'HtmlBody' => $body,
        'TextBody' => strip_tags($body),
        'MessageStream' => 'outbound',
    ]);

    $ch = curl_init('https://api.postmarkapp.com/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Postmark-Server-Token: ' . $apiKey,
        ],
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        error_log("[hermes_mail/postmark] HTTP {$status} para {$to}: {$resp}");
        return false;
    }
    return true;
}

function _hermes_mail_sendgrid(string $to, string $subject, string $body, array $opts): bool {
    $apiKey   = defined('SENDGRID_API_KEY') ? SENDGRID_API_KEY : getenv('SENDGRID_API_KEY');
    $from     = $opts['from'] ?? (defined('MAIL_FROM') ? MAIL_FROM : 'noreply@hermesb2b.co');
    $fromName = $opts['fromName'] ?? (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'HERMES.b2b');

    if (!$apiKey) {
        error_log('[hermes_mail/sendgrid] SENDGRID_API_KEY não configurada.');
        return false;
    }

    $payload = json_encode([
        'personalizations' => [['to' => [['email' => $to]]]],
        'from'    => ['email' => $from, 'name' => $fromName],
        'subject' => $subject,
        'content' => [
            ['type' => 'text/html',  'value' => $body],
            ['type' => 'text/plain', 'value' => strip_tags($body)],
        ],
    ]);

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // SendGrid retorna 202 em sucesso
    if ($status !== 202) {
        error_log("[hermes_mail/sendgrid] HTTP {$status} para {$to}: {$resp}");
        return false;
    }
    return true;
}
