<?php
/**
 * Newton IA — LLM Universal Router
 * Suporta: Groq, OpenAI, Anthropic (Claude), Google Gemini, Mistral
 *
 * Uso: llm_chat($provider, $model, $messages, $opts)
 * Provider detectado automaticamente se não informado.
 */

// ── Catálogo de modelos disponíveis ──────────────────────────────────────────

function llm_catalog(): array {
    return [
        'groq' => [
            'label'  => 'Groq',
            'color'  => '#f55036',
            'icon'   => 'G',
            'desc'   => 'Ultra-rápido · Open-source models',
            'models' => [
                'llama-3.3-70b-versatile'  => 'Llama 3.3 70B · Versátil (recomendado)',
                'llama-3.1-8b-instant'     => 'Llama 3.1 8B · Ultra-rápido',
                'llama3-70b-8192'          => 'Llama 3 70B',
                'mixtral-8x7b-32768'       => 'Mixtral 8x7B · 32k ctx',
                'gemma2-9b-it'             => 'Gemma 2 9B (Google)',
            ],
        ],
        'openai' => [
            'label'  => 'OpenAI',
            'color'  => '#10a37f',
            'icon'   => 'O',
            'desc'   => 'GPT-4o · Melhor qualidade geral',
            'models' => [
                'gpt-4o'                   => 'GPT-4o · Flagship',
                'gpt-4o-mini'              => 'GPT-4o mini · Rápido e barato',
                'gpt-4-turbo'              => 'GPT-4 Turbo · 128k ctx',
                'gpt-3.5-turbo'            => 'GPT-3.5 Turbo · Econômico',
            ],
        ],
        'anthropic' => [
            'label'  => 'Anthropic',
            'color'  => '#d97706',
            'icon'   => 'A',
            'desc'   => 'Claude · Raciocínio avançado',
            'models' => [
                'claude-sonnet-4-6'        => 'Claude Sonnet 4.6 · Balanceado (recomendado)',
                'claude-haiku-4-5'         => 'Claude Haiku 4.5 · Rápido e barato',
                'claude-opus-4-7'          => 'Claude Opus 4.7 · Melhor qualidade',
            ],
        ],
        'google' => [
            'label'  => 'Google',
            'color'  => '#4285f4',
            'icon'   => 'G',
            'desc'   => 'Gemini · Multimodal nativo',
            'models' => [
                'gemini-1.5-pro'           => 'Gemini 1.5 Pro · 1M ctx',
                'gemini-1.5-flash'         => 'Gemini 1.5 Flash · Rápido',
                'gemini-2.0-flash'         => 'Gemini 2.0 Flash · Mais novo',
            ],
        ],
        'mistral' => [
            'label'  => 'Mistral',
            'color'  => '#ff7000',
            'icon'   => 'M',
            'desc'   => 'Europeu · LGPD friendly',
            'models' => [
                'mistral-large-latest'     => 'Mistral Large · Melhor',
                'mistral-small-latest'     => 'Mistral Small · Econômico',
                'open-mistral-7b'          => 'Mistral 7B · Open-source',
                'open-mixtral-8x22b'       => 'Mixtral 8x22B · Poderoso',
            ],
        ],
    ];
}

function llm_provider_from_model(string $model): string {
    $map = [
        'gpt-'       => 'openai',
        'claude-'    => 'anthropic',
        'gemini-'    => 'google',
        'mistral-'   => 'mistral',
        'open-mistr' => 'mistral',
        'open-mixt'  => 'mistral',
        'llama'      => 'groq',
        'mixtral'    => 'groq',
        'gemma'      => 'groq',
        'whisper'    => 'groq',
    ];
    foreach ($map as $prefix => $provider) {
        if (str_starts_with($model, $prefix)) return $provider;
    }
    return 'groq';
}

// ── Roteador principal ────────────────────────────────────────────────────────

/**
 * Chama o LLM certo baseado no provider.
 * $messages = [['role'=>'system','content'=>'...'], ...]
 * Retorna o texto da resposta ou null em erro.
 */
function llm_chat(string $provider, string $model, array $messages, array $opts = []): ?string {
    return match($provider) {
        'openai'    => llm_openai($model, $messages, $opts),
        'anthropic' => llm_anthropic($model, $messages, $opts),
        'google'    => llm_gemini($model, $messages, $opts),
        'mistral'   => llm_mistral($model, $messages, $opts),
        default     => llm_groq($model, $messages, $opts),
    };
}

// ── Groq ─────────────────────────────────────────────────────────────────────

function llm_groq(string $model, array $messages, array $opts = []): ?string {
    $key = setting_get('groq.api_key') ?: (defined('GROQ_API_KEY') ? GROQ_API_KEY : '');
    if (!$key) return null;
    return _llm_openai_compat('https://api.groq.com/openai/v1/chat/completions', $key, $model, $messages, $opts);
}

// ── OpenAI ───────────────────────────────────────────────────────────────────

function llm_openai(string $model, array $messages, array $opts = []): ?string {
    $key = setting_get('openai.api_key');
    if (!$key) return null;
    return _llm_openai_compat('https://api.openai.com/v1/chat/completions', $key, $model, $messages, $opts);
}

// ── Mistral ──────────────────────────────────────────────────────────────────

function llm_mistral(string $model, array $messages, array $opts = []): ?string {
    $key = setting_get('mistral.api_key');
    if (!$key) return null;
    return _llm_openai_compat('https://api.mistral.ai/v1/chat/completions', $key, $model, $messages, $opts);
}

// ── OpenAI-compatible helper ─────────────────────────────────────────────────

function _llm_openai_compat(string $url, string $key, string $model, array $messages, array $opts): ?string {
    $body = array_merge([
        'model'       => $model,
        'messages'    => $messages,
        'max_tokens'  => $opts['max_tokens']  ?? 1024,
        'temperature' => $opts['temperature'] ?? 0.7,
    ], array_filter($opts, fn($k) => !in_array($k, ['max_tokens','temperature']), ARRAY_FILTER_USE_KEY));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno || $code < 200 || $code >= 300) return null;
    $data = json_decode($resp, true);
    return trim($data['choices'][0]['message']['content'] ?? '') ?: null;
}

// ── Anthropic (Claude) ───────────────────────────────────────────────────────

function llm_anthropic(string $model, array $messages, array $opts = []): ?string {
    $key = setting_get('anthropic.api_key');
    if (!$key) return null;

    // Anthropic separa system do array de messages
    $system   = '';
    $filtered = [];
    foreach ($messages as $m) {
        if ($m['role'] === 'system') { $system = $m['content']; continue; }
        $filtered[] = $m;
    }

    $body = [
        'model'      => $model,
        'max_tokens' => $opts['max_tokens'] ?? 1024,
        'messages'   => $filtered,
    ];
    if ($system) $body['system'] = $system;

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) return null;
    $data = json_decode($resp, true);
    return trim($data['content'][0]['text'] ?? '') ?: null;
}

// ── Google Gemini ─────────────────────────────────────────────────────────────

function llm_gemini(string $model, array $messages, array $opts = []): ?string {
    $key = setting_get('gemini.api_key');
    if (!$key) return null;

    // Converte messages para formato Gemini
    $system   = '';
    $contents = [];
    foreach ($messages as $m) {
        if ($m['role'] === 'system') { $system = $m['content']; continue; }
        $contents[] = [
            'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ];
    }

    $body = ['contents' => $contents, 'generationConfig' => ['maxOutputTokens' => $opts['max_tokens'] ?? 1024]];
    if ($system) $body['systemInstruction'] = ['parts' => [['text' => $system]]];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) return null;
    $data = json_decode($resp, true);
    return trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '') ?: null;
}

// ── Test helper (admin) ───────────────────────────────────────────────────────

function llm_test(string $provider): array {
    $catalog = llm_catalog();
    $info    = $catalog[$provider] ?? null;
    if (!$info) return ['ok' => false, 'error' => 'Provider desconhecido'];

    $firstModel = array_key_first($info['models']);
    $result = llm_chat($provider, $firstModel, [
        ['role' => 'user', 'content' => 'Responda apenas: OK'],
    ], ['max_tokens' => 10]);

    return ['ok' => $result !== null, 'response' => $result];
}
