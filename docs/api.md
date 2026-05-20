# Newton IA — API REST v1

Newton é o **cérebro de IA** da sua operação. Use esta API para integrar HERMES, Make, n8n, Zapier ou qualquer sistema externo.

**Base URL:** `https://app.newtonia.digital/api/v1`
**Autenticação:** `Authorization: Bearer nai_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`

Crie chaves em `/app/api-keys.php`.

---

## Endpoints

### `POST /chat`
Envia uma mensagem para um agente e recebe a resposta gerada pela IA.

**Body:**
```json
{
  "agent_id": 1,
  "message": "Olá, queria informações",
  "conversation_id": 42,
  "contact": { "phone": "+5547999998888", "name": "Maria" },
  "metadata": { "source": "n8n", "campaign": "vet-leads" }
}
```

- `agent_id` *(int, obrigatório)* — ID do agente
- `message` *(string, obrigatório)* — texto do usuário (até 8000 chars)
- `conversation_id` *(int, opcional)* — continua conversa existente
- `contact` *(object, opcional)* — cria/recupera conversa por contato
- `metadata` *(object, opcional)* — anexado para tracking

**Resposta:**
```json
{
  "ok": true,
  "reply": "Claro Maria, em que posso ajudar?",
  "conversation_id": 42,
  "message_id": 1337,
  "inbound_id": 1336,
  "provider": "groq",
  "model": "llama-3.3-70b-versatile",
  "latency_ms": 412
}
```

### `GET /agents`
Lista agentes do workspace.

### `GET /conversations`
Lista conversas. Query params: `agent_id`, `status`, `limit` (max 200), `offset`.

### `GET /conversations?id=42`
Detalhes da conversa + últimas 50 mensagens. Use `messages_limit` para ajustar.

---

## Webhooks de saída

Newton notifica seu sistema quando eventos acontecem. Configure em `/app/api-keys.php`.

**Eventos:**
- `message.received` — mensagem inbound chegou
- `message.sent` — agente respondeu
- `conversation.started` — conversa criada
- `conversation.ended` — conversa encerrada
- `handoff.requested` — cliente pediu humano

**Headers enviados:**
```
Content-Type: application/json
X-Newton-Event: message.received
X-Newton-Signature: sha256=<HMAC do body usando seu secret>
X-Newton-Delivery: <id único da entrega>
```

**Validação HMAC (PHP):**
```php
$body = file_get_contents('php://input');
$expected = hash_hmac('sha256', $body, $YOUR_SECRET);
$received = substr($_SERVER['HTTP_X_NEWTON_SIGNATURE'] ?? '', 7);
if (!hash_equals($expected, $received)) http_response_code(401);
```

---

## Limites e códigos

- Rate limit: **60 req/min por API key** (`X-RateLimit-Remaining` no header)
- `200` ok · `400` body inválido · `401` chave inválida · `403` scope insuficiente · `404` recurso não existe · `429` rate limit · `502` LLM falhou

---

## Integração com HERMES

HERMES = camada de execução (CRM, email, Signal, campanhas).
Newton = cérebro (IA, agentes, conversação).

Quando HERMES precisa de uma resposta inteligente:
```
HERMES → POST /api/v1/chat → Newton processa → resposta volta pro HERMES
```

Quando Newton recebe um lead novo via WhatsApp:
```
WhatsApp → Z-API → Newton webhook → IA responde
                                 ↓
                          outbound webhook → HERMES CRM (cria lead)
```
