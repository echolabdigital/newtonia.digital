<?php
/**
 * SYNAPSE — Engine de conversa.
 * Usa llm.php para rotear pro provider certo (Groq, OpenAI, Anthropic, Gemini, Mistral).
 */

function synapse_process(array $agent, array $conv, string $inbound, ?array $channel = null): ?string {
    $convId = (int) $conv['id'];
    $limit  = max(6, (int)($agent['context_window'] ?? 20));

    synapse_save_message($convId, 'in', $inbound);

    $history  = synapse_get_history($convId, $limit);
    $messages = [['role' => 'system', 'content' => synapse_build_system($agent, $conv)]];
    foreach ($history as $row) {
        $messages[] = ['role' => $row['direction'] === 'in' ? 'user' : 'assistant', 'content' => $row['content']];
    }

    $provider = $agent['provider'] ?: llm_provider_from_model($agent['model'] ?: '');
    $response = llm_chat($provider, $agent['model'] ?: 'llama-3.3-70b-versatile', $messages);
    if (!$response) return null;

    $response = trim($response);
    synapse_save_message($convId, 'out', $response);

    if ($channel && ($channel['status'] ?? '') === 'connected') {
        $cfg = zapi_from_channel($channel);
        if ($cfg['instance'] && $cfg['token'] && $cfg['client_token']) {
            zapi_send_text($cfg['instance'], $cfg['token'], $cfg['client_token'], $conv['contact_phone'], $response);
        }
    }

    return $response;
}

/**
 * Monta o system prompt do agente.
 *
 * @param array      $agent  Row da tabela agents
 * @param array      $conv   Row da tabela conversations (precisa de contact_name/contact_phone)
 * @param array|null $hermesCtx  Contexto rico retornado por hermes_ctx() — opcional
 */
function synapse_build_system(array $agent, array $conv, ?array $hermesCtx = null): string
{
    $name   = $conv['contact_name'] ?: $conv['contact_phone'];
    $prompt = trim($agent['prompt']);
    $now    = date('d/m/Y H:i');

    // Knowledge base injection
    $kb = '';
    if (function_exists('kb_fetch_for_prompt') && !empty($agent['id'])) {
        $kbText = kb_fetch_for_prompt((int)$agent['id']);
        if ($kbText) {
            $kb = "\n\n---\nBase de conhecimento (use SOMENTE estas informacoes para fatos especificos do negocio):\n\n" . $kbText;
        }
    }

    // Contexto Hermes CRM — injeta antes da instrucao de resposta
    $hermesBlock = '';
    if ($hermesCtx && function_exists('hermes_build_context_block')) {
        $hermesBlock = "\n\n" . hermes_build_context_block($hermesCtx);
    }

    return <<<SYS
{$prompt}
{$kb}{$hermesBlock}

---
Contexto atual:
- Data e hora: {$now}
- Voce esta conversando com: {$name}
- Responda sempre em portugues do Brasil, de forma natural e objetiva.
- Nunca revele que voce e uma IA a menos que o usuario pergunte diretamente.
SYS;
}

function synapse_test(array $agent, array $chatHistory, string $userMessage): ?string {
    $messages = [['role' => 'system', 'content' => synapse_build_system($agent, ['contact_name' => 'Teste', 'contact_phone' => '0000'])]];
    foreach ($chatHistory as $h) {
        if (!empty($h['role']) && !empty($h['content'])) $messages[] = $h;
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    $provider = $agent['provider'] ?: llm_provider_from_model($agent['model'] ?: '');
    return llm_chat($provider, $agent['model'] ?: 'llama-3.3-70b-versatile', $messages);
}
