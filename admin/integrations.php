<?php
require_once __DIR__ . '/../config.php';
require_super_admin();
require_once __DIR__ . '/_layout.php';

// Salva config de provider
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $provider = $_POST['provider'] ?? '';
    $catalog  = llm_catalog();

    if ($provider === 'asaas') {
        setting_set('asaas.api_key',      trim($_POST['asaas_key'] ?? ''), auth_user_id(), true);
        setting_set('asaas.env',          trim($_POST['asaas_env'] ?? 'sandbox'), auth_user_id());
        setting_set('asaas.webhook_token',trim($_POST['asaas_webhook'] ?? ''), auth_user_id(), true);
        flash('success', 'Asaas atualizado.');
    } elseif ($provider === 'zapi') {
        $partner = trim($_POST['zapi_partner'] ?? '');
        $client  = trim($_POST['zapi_client'] ?? '');
        if ($partner && $partner !== '••••••••') setting_set('zapi.partner_token', $partner, auth_user_id(), true);
        if ($client  && $client  !== '••••••••') setting_set('zapi.default_client_token', $client, auth_user_id(), true);
        flash('success', 'Z-API atualizado.');
    } elseif ($provider === 'google') {
        $places = trim($_POST['google_places'] ?? '');
        if ($places && $places !== '••••••••') setting_set('google.places_api_key', $places, auth_user_id(), true);
        flash('success', 'Google Places atualizado.');
    } elseif ($provider === 'elevenlabs') {
        $key   = trim($_POST['elevenlabs_key']   ?? '');
        $voice = trim($_POST['elevenlabs_voice'] ?? '');
        if ($key   && $key   !== '••••••••') setting_set('elevenlabs.api_key', $key, auth_user_id(), true);
        if ($voice) setting_set('elevenlabs.default_voice', $voice, auth_user_id());
        flash('success', 'ElevenLabs atualizado.');
    } elseif (isset($catalog[$provider])) {
        $key     = trim($_POST['api_key'] ?? '');
        $enabled = isset($_POST['enabled']) ? '1' : '0';
        if ($key !== '••••••••') setting_set("{$provider}.api_key", $key, auth_user_id(), true);
        setting_set("{$provider}.enabled", $enabled, auth_user_id());
        flash('success', ucfirst($provider) . ' atualizado.');
    }

    header('Location: /admin/integrations.php'); exit;
}

// Test API
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['test'])) {
    header('Content-Type: application/json');
    $p = $_GET['test'];
    if ($p === 'zapi') {
        require_once __DIR__ . '/../core/zapi.php';
        $partner = setting_get('zapi.partner_token') ?: '';
        if (!$partner) { echo json_encode(['ok' => false, 'error' => 'sem partner token']); exit; }
        $list = zapi_partner_list($partner);
        echo json_encode([
            'ok'        => is_array($list),
            'instances' => count($list),
            'web'       => count(array_filter($list, fn($i) => ($i['middleware'] ?? 'web') === 'web')),
            'mobile'    => count(array_filter($list, fn($i) => ($i['middleware'] ?? 'web') === 'mobile')),
        ]);
        exit;
    }
    echo json_encode(llm_test($p));
    exit;
}

$catalog = llm_catalog();

admin_layout('Integrações · IA', 'integrations', function() use ($catalog) {
?>
<style>
.pcard { background: #fff; border: 1px solid #e7e5e0; border-radius: 16px; overflow: hidden; transition: box-shadow .2s; }
.pcard:hover { box-shadow: 0 8px 40px rgba(0,0,0,.07); }
.pcard-head { padding: 1.4rem 1.6rem; display: flex; align-items: flex-start; gap: 1rem; border-bottom: 1px solid #f4f2ed; }
.pcard-body { padding: 1.4rem 1.6rem; display: flex; flex-direction: column; gap: 1rem; }
.pcard-foot { padding: 1rem 1.6rem; background: #fafaf9; border-top: 1px solid #f4f2ed; display: flex; align-items: center; justify-content: space-between; }
.provider-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 1.1rem; flex-shrink: 0; font-family: 'Geist Mono', monospace; }
.field-label { font-size: .8rem; font-weight: 600; color: #3a3a40; margin-bottom: .35rem; display: block; }
.field-input { width: 100%; padding: .65rem .85rem; border: 1px solid #e7e5e0; border-radius: 8px; font-size: .875rem; font-family: 'Geist Mono', monospace; color: #18181b; outline: none; box-sizing: border-box; transition: border-color .15s; background: #fff; }
.field-input:focus { border-color: #0ea5e9; }
.toggle-wrap { display: flex; align-items: center; gap: .6rem; }
.toggle { width: 40px; height: 22px; border-radius: 99px; background: #d1d5db; position: relative; cursor: pointer; transition: background .2s; flex-shrink: 0; }
.toggle.on { background: #0ea5e9; }
.toggle::after { content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: left .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
.toggle.on::after { left: 21px; }
.tag-chip { display: inline-block; font-size: .68rem; font-weight: 700; padding: 2px 8px; border-radius: 99px; font-family: 'Geist Mono', monospace; letter-spacing: .05em; }
.btn-test { padding: .45rem .9rem; background: #f8fafc; border: 1px solid #e7e5e0; border-radius: 8px; font-size: .78rem; font-weight: 600; color: #3a3a40; cursor: pointer; transition: all .15s; }
.btn-test:hover { background: #f0f9ff; border-color: #bae6fd; color: #0ea5e9; }
.btn-save { padding: .6rem 1.2rem; background: #0ea5e9; color: #fff; border: none; border-radius: 8px; font-size: .85rem; font-weight: 600; cursor: pointer; transition: background .15s; }
.btn-save:hover { background: #0284c7; }
.model-list { display: flex; flex-wrap: wrap; gap: .35rem; }
.model-chip { font-family: 'Geist Mono', monospace; font-size: .7rem; padding: 3px 8px; border-radius: 6px; background: #f4f2ed; color: #64748b; }
.dot-status { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
</style>

<div style="max-width:1000px;margin:0 auto;padding:2rem 1.5rem">

  <?php $f = flash_get(); if ($f): ?>
  <div style="padding:.75rem 1rem;border-radius:10px;margin-bottom:1.5rem;background:<?= $f['type']==='success'?'#f0fdf4':'#fef2f2' ?>;color:<?= $f['type']==='success'?'#16a34a':'#dc2626' ?>;border:1px solid <?= $f['type']==='success'?'#bbf7d0':'#fecaca' ?>;font-size:.875rem">
    <?= htmlspecialchars($f['msg']) ?>
  </div>
  <?php endif ?>

  <!-- Header -->
  <div style="margin-bottom:2rem">
    <div style="font-family:'Geist Mono',monospace;font-size:.68rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:#0ea5e9;margin-bottom:.4rem">NEWTON IA · SUPER ADMIN</div>
    <h1 style="margin:0 0 .4rem;font-size:1.6rem;font-weight:700;color:#18181b">Integrações &amp; Provedores de IA</h1>
    <p style="margin:0;color:#8b8a93;font-size:.9rem">Configure as API keys dos LLMs. Tenants escolhem qual modelo usar em cada agente.</p>
  </div>

  <!-- Grid de providers -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem;margin-bottom:2rem">
    <?php foreach ($catalog as $p => $info):
      $key     = setting_get("{$p}.api_key") ?: '';
      $enabled = setting_get("{$p}.enabled", '0') === '1';
      $masked  = $key ? substr($key, 0, 6) . str_repeat('•', min(28, strlen($key) - 6)) : '';
      $configured = !empty($key);
      $dotClass  = $enabled && $configured ? 'background:#22c55e;box-shadow:0 0 6px rgba(34,197,94,.4)' : ($configured ? 'background:#f59e0b' : 'background:#d1d5db');
      $statusLabel = $enabled && $configured ? 'Ativo' : ($configured ? 'Configurado' : 'Sem key');
      $statusColor = $enabled && $configured ? '#16a34a' : ($configured ? '#d97706' : '#94a3b8');
    ?>
    <div class="pcard">
      <div class="pcard-head">
        <div class="provider-icon" style="background:<?= $info['color'] ?>18;color:<?= $info['color'] ?>"><?= $info['icon'] ?></div>
        <div style="flex:1">
          <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.2rem">
            <span style="font-size:1rem;font-weight:700;color:#18181b"><?= $info['label'] ?></span>
            <div style="display:flex;align-items:center;gap:.35rem">
              <div class="dot-status" style="<?= $dotClass ?>"></div>
              <span style="font-size:.72rem;font-weight:600;color:<?= $statusColor ?>"><?= $statusLabel ?></span>
            </div>
          </div>
          <div style="font-size:.8rem;color:#8b8a93"><?= $info['desc'] ?></div>
        </div>
      </div>

      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="provider" value="<?= $p ?>">
        <div class="pcard-body">

          <!-- Toggle ativo -->
          <div class="toggle-wrap">
            <label style="position:relative;display:block;cursor:pointer">
              <input type="checkbox" name="enabled" <?= $enabled?'checked':'' ?> onchange="toggleUpdate(this)" style="opacity:0;position:absolute;width:0;height:0">
              <div class="toggle <?= $enabled?'on':'' ?>" onclick="this.previousElementSibling.checked=!this.previousElementSibling.checked;this.classList.toggle('on')"></div>
            </label>
            <span style="font-size:.85rem;color:#3a3a40;font-weight:500">Habilitado para tenants</span>
          </div>

          <!-- API Key -->
          <div>
            <label class="field-label">API Key</label>
            <div style="position:relative">
              <input class="field-input" type="password" name="api_key" value="<?= htmlspecialchars($masked) ?>" placeholder="<?= $configured ? '(mantém a chave atual)' : 'Cole a API key aqui' ?>" autocomplete="new-password">
              <button type="button" onclick="toggleVis(this.previousElementSibling)" style="position:absolute;right:.6rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#8b8a93;font-size:.75rem;padding:.2rem .4rem">mostrar</button>
            </div>
          </div>

          <!-- Modelos disponíveis -->
          <div>
            <label class="field-label" style="margin-bottom:.5rem">Modelos disponíveis</label>
            <div class="model-list">
              <?php foreach ($info['models'] as $m => $ml): ?>
              <span class="model-chip"><?= htmlspecialchars($m) ?></span>
              <?php endforeach ?>
            </div>
          </div>

        </div>
        <div class="pcard-foot">
          <button type="button" class="btn-test" onclick="testProvider('<?= $p ?>', this)">
            Testar conexão
          </button>
          <div id="test-result-<?= $p ?>" style="font-size:.78rem;display:none"></div>
          <button type="submit" class="btn-save">Salvar</button>
        </div>
      </form>
    </div>
    <?php endforeach ?>
  </div>

  <!-- Z-API Partner (WhatsApp) -->
  <?php
    $zapiPartner   = setting_get('zapi.partner_token') ?: '';
    $zapiClient    = setting_get('zapi.default_client_token') ?: '';
    $zapiConfigured = !empty($zapiPartner) && !empty($zapiClient);
  ?>
  <div class="pcard" style="margin-bottom:1.5rem">
    <div class="pcard-head">
      <div class="provider-icon" style="background:#25d36618;color:#25d366">Z</div>
      <div style="flex:1">
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.2rem">
          <span style="font-size:1rem;font-weight:700;color:#18181b">Z-API · WhatsApp</span>
          <div style="display:flex;align-items:center;gap:.35rem">
            <div class="dot-status" style="background:<?= $zapiConfigured ? '#22c55e;box-shadow:0 0 6px rgba(34,197,94,.4)' : '#d1d5db' ?>"></div>
            <span style="font-size:.72rem;font-weight:600;color:<?= $zapiConfigured ? '#16a34a' : '#94a3b8' ?>">
              <?= $zapiConfigured ? 'Configurado' : 'Sem credenciais' ?>
            </span>
          </div>
        </div>
        <div style="font-size:.8rem;color:#8b8a93">Credenciais Partner para auto-descobrir instâncias e criar webhooks dos agentes Newton IA.</div>
      </div>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="provider" value="zapi">
      <div class="pcard-body" style="display:grid;grid-template-columns:1fr;gap:1rem">
        <div>
          <label class="field-label">Partner Token (JWT)</label>
          <div style="position:relative">
            <input class="field-input" type="password" name="zapi_partner"
                   value="<?= $zapiPartner ? '••••••••' : '' ?>"
                   placeholder="<?= $zapiPartner ? '(mantém o token atual)' : 'eyJhbGciOiJIUzI1NiJ9...' ?>"
                   autocomplete="new-password">
            <button type="button" onclick="toggleVis(this.previousElementSibling)" style="position:absolute;right:.6rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#8b8a93;font-size:.75rem;padding:.2rem .4rem">mostrar</button>
          </div>
          <div style="font-size:.74rem;color:#8b8a93;margin-top:.3rem">Obtido em <a href="https://app.z-api.io" target="_blank" style="color:#0ea5e9">app.z-api.io</a> &rsaquo; Partner &rsaquo; Tokens</div>
        </div>
        <div>
          <label class="field-label">Client Token (default para novas instâncias)</label>
          <div style="position:relative">
            <input class="field-input" type="password" name="zapi_client"
                   value="<?= $zapiClient ? '••••••••' : '' ?>"
                   placeholder="<?= $zapiClient ? '(mantém o token atual)' : 'Fc79c27579b2f463b9973e219441ddd33S' ?>"
                   autocomplete="new-password">
            <button type="button" onclick="toggleVis(this.previousElementSibling)" style="position:absolute;right:.6rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#8b8a93;font-size:.75rem;padding:.2rem .4rem">mostrar</button>
          </div>
        </div>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:.7rem .85rem;font-size:.78rem;color:#92400e;line-height:1.5">
          <strong>📱 Newton IA usa instâncias mobile</strong> (autônomas, sem espelho no celular do cliente). Crie no painel Z-API &rsaquo; Nova Instância &rsaquo; Mobile.
        </div>
      </div>
      <div class="pcard-foot">
        <button type="button" class="btn-test" onclick="testProvider('zapi', this)">Testar conexão</button>
        <div id="test-result-zapi" style="font-size:.78rem;display:none"></div>
        <button type="submit" class="btn-save">Salvar</button>
      </div>
    </form>
  </div>

  <!-- Google Places API (FLUX scraper) -->
  <?php $googlePlaces = setting_get('google.places_api_key') ?: ''; ?>
  <div class="pcard" style="margin-bottom:1.5rem">
    <div class="pcard-head">
      <div class="provider-icon" style="background:#4285f418;color:#4285f4">G</div>
      <div style="flex:1">
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.2rem">
          <span style="font-size:1rem;font-weight:700;color:#18181b">Google Places · Scraper de leads</span>
          <div style="display:flex;align-items:center;gap:.35rem">
            <div class="dot-status" style="background:<?= $googlePlaces ? '#22c55e' : '#d1d5db' ?>"></div>
            <span style="font-size:.72rem;font-weight:600;color:<?= $googlePlaces ? '#16a34a' : '#94a3b8' ?>"><?= $googlePlaces ? 'Configurado' : 'Sem chave' ?></span>
          </div>
        </div>
        <div style="font-size:.8rem;color:#8b8a93">Permite o FLUX extrair leads do Google Maps por nicho/cidade. Custo ~$0.03/lead (Google cobra).</div>
      </div>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="provider" value="google">
      <div class="pcard-body">
        <label class="field-label">Places API Key</label>
        <input class="field-input" type="password" name="google_places"
               value="<?= $googlePlaces ? '••••••••' : '' ?>"
               placeholder="<?= $googlePlaces ? '(mantém a chave atual)' : 'AIzaSy...' ?>"
               autocomplete="new-password">
        <div style="font-size:.74rem;color:#8b8a93;margin-top:.3rem">Habilite a <b>Places API</b> em <a href="https://console.cloud.google.com" target="_blank" style="color:#0ea5e9">console.cloud.google.com</a> e cole a chave aqui.</div>
      </div>
      <div class="pcard-foot"><button type="submit" class="btn-save">Salvar</button></div>
    </form>
  </div>

  <!-- ElevenLabs (SONAR — voz no WhatsApp) -->
  <?php
    $elKey   = setting_get('elevenlabs.api_key') ?: '';
    $elVoice = setting_get('elevenlabs.default_voice') ?: 'EXAVITQu4vr4xnSDxMaL';
  ?>
  <div class="pcard" style="margin-bottom:1.5rem">
    <div class="pcard-head">
      <div class="provider-icon" style="background:#7c3aed18;color:#7c3aed">🎙</div>
      <div style="flex:1">
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.2rem">
          <span style="font-size:1rem;font-weight:700;color:#18181b">ElevenLabs · SONAR (voz)</span>
          <div style="display:flex;align-items:center;gap:.35rem">
            <div class="dot-status" style="background:<?= $elKey ? '#22c55e' : '#d1d5db' ?>"></div>
            <span style="font-size:.72rem;font-weight:600;color:<?= $elKey ? '#16a34a' : '#94a3b8' ?>"><?= $elKey ? 'Configurado' : 'Sem chave' ?></span>
          </div>
        </div>
        <div style="font-size:.8rem;color:#8b8a93">TTS multilingual realista. Whisper (transcribe) usa o Groq (chave Groq ja configurada).</div>
      </div>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="provider" value="elevenlabs">
      <div class="pcard-body" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <div>
          <label class="field-label">API Key</label>
          <input class="field-input" type="password" name="elevenlabs_key"
                 value="<?= $elKey ? '••••••••' : '' ?>"
                 placeholder="<?= $elKey ? '(mantém a chave atual)' : 'sk_xxxxxxxxxxxxxxxx' ?>"
                 autocomplete="new-password">
          <div style="font-size:.74rem;color:#8b8a93;margin-top:.3rem"><a href="https://elevenlabs.io/app/settings/api-keys" target="_blank" style="color:#0ea5e9">elevenlabs.io</a> &rsaquo; Settings &rsaquo; API Keys</div>
        </div>
        <div>
          <label class="field-label">Voice ID padrao</label>
          <input class="field-input" type="text" name="elevenlabs_voice" value="<?= e($elVoice) ?>" placeholder="EXAVITQu4vr4xnSDxMaL">
          <div style="font-size:.74rem;color:#8b8a93;margin-top:.3rem">Voz default quando agente nao define. Veja IDs em <a href="https://elevenlabs.io/app/voice-library" target="_blank" style="color:#0ea5e9">voice-library</a>.</div>
        </div>
      </div>
      <div class="pcard-foot"><button type="submit" class="btn-save">Salvar</button></div>
    </form>
  </div>

  <!-- Asaas (pagamentos) -->
  <div class="pcard" style="margin-bottom:2rem">
    <div class="pcard-head">
      <div class="provider-icon" style="background:#6366f118;color:#6366f1">$</div>
      <div>
        <div style="font-size:1rem;font-weight:700;color:#18181b;margin-bottom:.2rem">Asaas · Pagamentos</div>
        <div style="font-size:.8rem;color:#8b8a93">Gateway PIX, Cartão e Boleto para billing dos tenants</div>
      </div>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="provider" value="asaas">
      <div class="pcard-body" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <div>
          <label class="field-label">API Key Asaas</label>
          <input class="field-input" type="password" name="asaas_key" value="<?= htmlspecialchars(setting_get('asaas.api_key') ? '••••••••' : '') ?>" placeholder="$aact_...">
        </div>
        <div>
          <label class="field-label">Ambiente</label>
          <select name="asaas_env" class="field-input" style="background:#fff">
            <option value="sandbox" <?= setting_get('asaas.env','sandbox')==='sandbox'?'selected':'' ?>>Sandbox (testes)</option>
            <option value="prod"    <?= setting_get('asaas.env','sandbox')==='prod'   ?'selected':'' ?>>Produção</option>
          </select>
        </div>
        <div style="grid-column:1/-1">
          <label class="field-label">Webhook Token (Asaas → Newton)</label>
          <input class="field-input" type="text" name="asaas_webhook" value="<?= htmlspecialchars(setting_get('asaas.webhook_token') ?: '') ?>" placeholder="Token gerado no painel Asaas">
        </div>
      </div>
      <div class="pcard-foot">
        <div style="font-size:.78rem;color:#8b8a93">Webhook URL: <code style="font-family:'Geist Mono',monospace"><?= APP_URL ?>/webhooks/asaas.php</code></div>
        <button type="submit" class="btn-save">Salvar</button>
      </div>
    </form>
  </div>

</div>

<script>
function toggleVis(input) {
  input.type = input.type === 'password' ? 'text' : 'password';
}

async function testProvider(provider, btn) {
  const result = document.getElementById('test-result-' + provider);
  btn.textContent = 'Testando...';
  btn.disabled = true;
  result.style.display = 'none';
  try {
    const r = await fetch('/admin/integrations.php?test=' + provider);
    const d = await r.json();
    result.style.display = 'inline';
    if (d.ok) {
      result.style.color = '#16a34a';
      if (provider === 'zapi' && typeof d.instances !== 'undefined') {
        result.textContent = `✓ ${d.instances} instância(s): ${d.web} web, ${d.mobile} mobile`;
      } else {
        result.textContent = '✓ Conectado';
      }
    } else {
      result.style.color = '#dc2626';
      result.textContent = '✗ ' + (d.error || 'sem API key ou inválida');
    }
  } catch(e) {
    result.style.display = 'inline';
    result.style.color = '#dc2626';
    result.textContent = '✗ Erro de rede';
  }
  btn.textContent = 'Testar conexão';
  btn.disabled = false;
}
</script>
<?php });
