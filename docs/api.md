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

### `POST /leads`  *(FLUX — ingest)*
Recebe leads de HERMES Radar / Make / n8n. Cria a lista automaticamente se não existir. Dedup por `phone+list`.

**Body (lead único):**
```json
{
  "list": "HERMES Radar — Vets Florianópolis",
  "lead": {
    "name":     "Clínica Vet Patinhas",
    "phone":    "5548999998888",
    "business": "Clínica veterinária",
    "city":     "Florianópolis",
    "state":    "SC",
    "rating":   4.8,
    "notes":    "atendimento 24h",
    "source":   "hermes-radar"
  }
}
```

**Body (lote — até 500):**
```json
{ "list": "Vets-SC-jan", "leads": [ {...}, {...}, ... ] }
```

**Resposta:** `{ ok, list_id, list, imported, skipped, ids: [42, 43, ...] }`

---

### `POST /flux/personalize`  *(FLUX — IA personaliza msg)*
Recebe um lead + template, devolve mensagem reescrita pela IA com base no perfil. Não envia — apenas gera o texto.

**Body:**
```json
{
  "agent_id": 12,
  "template": "Oi {first_name}, vi seu {business} em {city}, gostaria de te apresentar...",
  "lead": {
    "name":     "João Silva",
    "business": "Pizzaria do João",
    "city":     "São Paulo",
    "state":    "SP",
    "rating":   4.7,
    "notes":    "delivery via iFood"
  }
}
```

Placeholders aceitos no template: `{name}` `{first_name}` `{business}` `{city}` `{state}`

**Resposta:** `{ ok, message, provider, model, latency_ms }`

---

### `POST /pulse/qualify`  *(PULSE — SPIN Selling)*
Analisa uma conversa e devolve qualificação SPIN + score (0-100) + temperatura.

**Modo A — conversa Newton:**
```json
{ "agent_id": 12, "conversation_id": 456 }
```

**Modo B — transcript externo (HERMES envia):**
```json
{
  "agent_id": 12,
  "messages": [
    { "role": "user",      "content": "tô com 50 leads parados, não dou conta" },
    { "role": "assistant", "content": "entendi, e quanto tempo perde por dia com isso?" },
    { "role": "user",      "content": "umas 4 horas..." }
  ]
}
```

**Resposta:**
```json
{
  "ok": true,
  "spin": {
    "situation":   "Dono de empresa SaaS com 50 leads parados",
    "problem":     "Não dá conta de qualificar todos",
    "implication": "Perde 4h/dia em tarefa operacional",
    "need_payoff": "Automatizar qualificação libera o time para fechar",
    "score":       82,
    "temperature": "hot",
    "next_step":   "agendar demo de 20min"
  },
  "source": "messages"
}
```

---

### `POST /sonar/tts`  *(SONAR — texto → áudio MP3)*
Gera áudio via PlayAI (Groq, grátis) ou ElevenLabs.

**Body:**
```json
{
  "text":     "Olá, tudo bem? Aqui é a Newton.",
  "voice_id": "Celeste-PlayAI",
  "provider": "groq",
  "agent_id": 12
}
```

Vozes Groq PlayAI: `Celeste-PlayAI` `Aaliyah-PlayAI` `Fritz-PlayAI` `Atlas-PlayAI` `Orion-PlayAI` etc. — ver `core/sonar.php:sonar_groq_voices()`.

**Resposta:** `{ ok, audio_url, provider, voice_id, char_count, latency_ms }`

---

### `POST /sonar/transcribe`  *(SONAR — áudio → texto)*
Transcreve áudio público (URL https) via Whisper Large v3 Turbo (Groq).

**Body:** `{ "audio_url": "https://.../msg.ogg", "agent_id": 12 }`

**Resposta:** `{ ok, transcript, provider, char_count, latency_ms }`

---

## Scopes disponíveis

| Scope | Endpoints |
|---|---|
| `chat:read` `chat:write` | `/chat` |
| `agents:read` `agents:write` | `/agents` |
| `conversations:read` | `/conversations` |
| `flux:write` | `/leads`, `/flux/personalize` |
| `pulse:read` | `/pulse/qualify` |
| `sonar:write` | `/sonar/tts`, `/sonar/transcribe` |

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
