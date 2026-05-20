<?php
/**
 * Newton IA — POST /api/v1/flux/personalize
 *
 * Personaliza uma mensagem de abordagem para um lead especifico usando IA.
 * Nao envia mensagem nem grava lead — apenas devolve o texto pronto.
 *
 * Body JSON:
 *   {
 *     "agent_id":  123,                    // obrigatorio
 *     "template":  "Oi {first_name}, vi seu {business} em {city}...",   // obrigatorio
 *     "lead": {                            // obrigatorio
 *       "name":     "Joao Silva",
 *       "business": "Pizzaria do Joao",
 *       "city":     "Sao Paulo",
 *       "state":    "SP",
 *       "rating":   4.8,                   // opcional
 *       "notes":    "delivery via iFood"   // opcional
 *     }
 *   }
 *
 * Placeholders suportados no template: {name} {first_name} {business} {city} {state}
 *
 * Retorna: { ok, message, model, provider, latency_ms }
 */
require_once __DIR__ . '/../_bootstrap.php';

$ctx = api_boot('flux:write', ['POST']);
api_track($ctx);

$body    = $ctx['body'];
$tid     = (int)$ctx['tenant']['id'];
$agentId = (int)($body['agent_id'] ?? 0);
$tpl     = trim((string)($body['template'] ?? ''));
$lead    = is_array($body['lead'] ?? null) ? $body['lead'] : [];

if (!$agentId)        api_fail(400, 'missing_field', 'agent_id obrigatorio');
if ($tpl === '')      api_fail(400, 'missing_field', 'template obrigatorio');
if (empty($lead))     api_fail(400, 'missing_field', 'lead obrigatorio');

$agent = agent_get($agentId, $tid);
if (!$agent) api_fail(404, 'agent_not_found', "Agente $agentId nao encontrado");

$t0      = microtime(true);
$message = flux_personalize_message(
    ['template' => $tpl, 'personalize' => 1],
    $lead,
    $agent
);
$latency = (int) ((microtime(true) - $t0) * 1000);

if (!$message) api_fail(502, 'llm_error', 'LLM nao respondeu. Verifique a chave Groq em Integracoes.');

$provider = $agent['provider'] ?: llm_provider_from_model($agent['model'] ?? '');
api_ok([
    'message'    => $message,
    'provider'   => $provider,
    'model'      => $agent['model'],
    'latency_ms' => $latency,
]);
