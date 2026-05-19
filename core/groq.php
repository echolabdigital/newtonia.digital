<?php
/**
 * Groq API — cliente HTTP simples.
 * Usa setting_get('groq.api_key') do banco. Fallback pra constante GROQ_API_KEY.
 */

function groq_api_key(): string {
    return setting_get('groq.api_key') ?: (defined('GROQ_API_KEY') ? GROQ_API_KEY : '');
}

function groq_model(): string {
    return setting_get('groq.model') ?: 'llama-3.3-70b-versatile';
}

/**
 * Chama Groq Chat Completions.
 * $messages = [['role'=>'system','content'=>'...'], ['role'=>'user','content'=>'...']]
 * Retorna o texto da resposta ou null em caso de erro.
 */
function groq_chat(array $messages, string $model = '', array $opts = []): ?string {
    $key = groq_api_key();
    if (!$key) return null;

    $model = $model ?: groq_model();
    $body  = array_merge([
        'model'       => $model,
        'messages'    => $messages,
        'max_tokens'  => 1024,
        'temperature' => 0.7,
    ], $opts);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $resp  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno || $code !== 200) return null;

    $data = json_decode($resp, true);
    return $data['choices'][0]['message']['content'] ?? null;
}
