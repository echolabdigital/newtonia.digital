<?php
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/_layout.php';

$tid   = (int) $tenant['id'];
$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$agent = $id ? agent_get($id, $tid) : null;
$isNew = !$agent;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    csrf_check();
    if ($agent) { agent_delete($id, $tid); audit_log('agent.deleted','agent',$id); }
    header('Location: /app/agents.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') !== 'delete') {
    csrf_check();
    $name    = trim($_POST['name'] ?? '');
    $prompt  = trim($_POST['prompt'] ?? '');
    $model   = trim($_POST['model'] ?? 'llama-3.3-70b-versatile');
    $status  = in_array($_POST['status'] ?? '', ['active','inactive','draft']) ? $_POST['status'] : 'draft';
    $errors  = [];
    if (!$name)   $errors[] = 'Nome obrigatorio.';
    if (!$prompt) $errors[] = 'Prompt obrigatorio.';
    if (!$errors) {
        $provider = llm_provider_from_model($model);
        if ($isNew) {
            $id    = agent_create($tid, $name, $prompt, $model);
            $agent = agent_get($id, $tid);
            $isNew = false;
            audit_log('agent.created','agent',$id,['name'=>$name]);
        } else {
            agent_update($id, $tid, compact('name','prompt','model','status','provider'));
            $agent = agent_get($id, $tid);
            audit_log('agent.updated','agent',$id);
        }
        $zapiInstance   = trim($_POST['zapi_instance'] ?? '');
        $zapiToken      = trim($_POST['zapi_token'] ?? '');
        $zapiClient     = trim($_POST['zapi_client'] ?? '');
        $zapiMiddleware = in_array($_POST['zapi_middleware'] ?? '', ['web','mobile']) ? $_POST['zapi_middleware'] : 'mobile';
        if ($zapiInstance && $zapiToken && $zapiClient) {
            agent_channel_save($id, $tid, [
                'instance'     => $zapiInstance,
                'token'        => $zapiToken,
                'client_token' => $zapiClient,
                'middleware'   => $zapiMiddleware,
            ]);
        }
        // Widget settings
        $widgetEnabled  = isset($_POST['widget_enabled']) ? 1 : 0;
        $widgetColor    = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['widget_color'] ?? '') ? $_POST['widget_color'] : '#0ea5e9';
        $widgetPosition = in_array($_POST['widget_position'] ?? '', ['bottom-right','bottom-left']) ? $_POST['widget_position'] : 'bottom-right';
        $widgetGreeting = trim($_POST['widget_greeting'] ?? '') ?: 'Ola! Como posso ajudar?';
        $allowedDomains = json_encode(array_values(array_filter(array_map('trim', explode("\n", $_POST['allowed_domains'] ?? '')))));
        agent_update($id, $tid, [
            'widget_enabled'  => $widgetEnabled,
            'widget_color'    => $widgetColor,
            'widget_position' => $widgetPosition,
            'widget_greeting' => $widgetGreeting,
            'allowed_domains' => $allowedDomains,
        ]);
        $agent = agent_get($id, $tid);
        flash('success', 'Agente salvo.');
        header("Location: /app/agent-edit.php?id=$id"); exit;
    }
}

$channel    = $agent ? agent_channel_get((int)$agent['id']) : null;
$chCfg      = $channel ? (json_decode($channel['config_json'], true) ?? []) : [];
$webhookUrl = $channel ? APP_URL.'/webhooks/zapi-synapse.php?channel='.(int)$channel['id'].'&token='.$channel['webhook_token'] : null;

if ($channel && $chCfg) {
    $zapiStatus = zapi_get_status($chCfg['instance']??'',$chCfg['token']??'',$chCfg['client_token']??'');
    $newStatus  = ($zapiStatus['connected']??false) ? 'connected' : 'disconnected';
    if ($newStatus !== $channel['status']) {
        agent_channel_set_status((int)$channel['id'], $newStatus, $zapiStatus['phone']??null);
        $channel['status']          = $newStatus;
        $channel['connected_phone'] = $zapiStatus['phone']??null;
    }
}

$catalog    = llm_catalog();
$embedToken = $agent['embed_token'] ?? '';
$embedUrl   = APP_URL . '/widget.js?agent=' . $embedToken;
$embedCode  = '<script src="' . $embedUrl . '" defer></script>';

$allowedDomainsArr = json_decode($agent['allowed_domains'] ?? '[]', true) ?: [];
$allowedDomainsTxt = implode("\n", $allowedDomainsArr);

$title = $isNew ? 'Novo Agente' : 'Editar &middot; '.htmlspecialchars($agent['name']??'');
app_layout($isNew ? 'Novo Agente' : 'Editar Agente', 'agents', function() use ($agent,$channel,$chCfg,$webhookUrl,$isNew,$title,$tid,$catalog,$embedToken,$embedUrl,$embedCode,$allowedDomainsTxt) {
?>
<style>
.ae-card{background:#fff;border:1px solid #e7e5e0;border-radius:12px;overflow:hidden;margin-bottom:1.5rem}
.ae-head{padding:1.25rem 1.5rem;border-bottom:1px solid #f4f2ed}
.ae-body{padding:1.5rem;display:flex;flex-direction:column;gap:1.1rem}
.ae-label{display:block;font-size:.82rem;font-weight:600;color:#3a3a40;margin-bottom:.4rem}
.ae-input{width:100%;padding:.65rem .85rem;border:1px solid #e7e5e0;border-radius:8px;font-size:.88rem;color:#18181b;outline:none;box-sizing:border-box;transition:border-color .15s}
.ae-input:focus{border-color:#0ea5e9}
.ae-mono{font-family:'Geist Mono',monospace}
.ae-tag{font-family:'Geist Mono',monospace;font-size:.68rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#0ea5e9;margin-bottom:.3rem}
.ae-btn-primary{padding:.65rem 1.3rem;background:#0ea5e9;color:#fff;border:none;border-radius:8px;font-size:.875rem;font-weight:600;cursor:pointer;transition:background .15s}
.ae-btn-primary:hover{background:#0284c7}
.ae-btn-ghost{padding:.65rem 1.2rem;border:1px solid #e7e5e0;border-radius:8px;font-size:.875rem;color:#3a3a40;text-decoration:none;background:#fff}
.ae-toggle-wrap{display:flex;align-items:center;gap:.75rem}
.ae-toggle{width:40px;height:22px;border-radius:99px;background:#d1d5db;position:relative;cursor:pointer;transition:background .2s;flex-shrink:0}
.ae-toggle.on{background:#0ea5e9}
.ae-toggle::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:left .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.ae-toggle.on::after{left:21px}
.ae-code-box{background:#0f172a;border-radius:10px;padding:1rem 1.2rem;position:relative;overflow:hidden}
.ae-code-box code{font-family:'Geist Mono',monospace;font-size:.78rem;color:#e2e8f0;line-height:1.6;white-space:pre-wrap;word-break:break-all;display:block}
.ae-copy-btn{position:absolute;top:.6rem;right:.6rem;padding:.3rem .7rem;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:6px;color:#94a3b8;font-size:.72rem;font-weight:600;cursor:pointer;transition:all .15s;font-family:'Geist Mono',monospace}
.ae-copy-btn:hover{background:rgba(255,255,255,.2);color:#e2e8f0}
.ae-preview-bubble{display:inline-block;padding:9px 14px;border-radius:14px;font-size:13px;line-height:1.45;max-width:220px;word-break:break-word}
</style>
<div style="max-width:760px;margin:0 auto;padding:2rem 1.5rem">

  <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1.5rem;font-size:.82rem;color:#8b8a93">
    <a href="agents.php" style="color:#8b8a93;text-decoration:none">Agentes</a>
    <span>&rsaquo;</span>
    <span style="color:#18181b"><?= $title ?></span>
  </div>

<?php $f = flash(); if ($f): ?>
  <div style="padding:.75rem 1rem;border-radius:8px;margin-bottom:1.2rem;background:<?= $f['type']==='success'?'#f0fdf4':'#fef2f2' ?>;color:<?= $f['type']==='success'?'#16a34a':'#dc2626' ?>;border:1px solid <?= $f['type']==='success'?'#bbf7d0':'#fecaca' ?>;font-size:.875rem">
    <?= htmlspecialchars($f['msg']) ?>
  </div>
<?php endif ?>

  <form method="POST">
    <?= csrf_field() ?>
    <div class="ae-card">
      <div class="ae-head">
        <div class="ae-tag">SYNAPSE &middot; AGENTE</div>
        <h2 style="margin:0;font-size:1.05rem;font-weight:600;color:#18181b">Identidade &amp; Comportamento</h2>
      </div>
      <div class="ae-body">
        <div style="display:grid;grid-template-columns:1fr<?= $isNew ? '' : ' 160px' ?>;gap:1rem">
          <div>
            <label class="ae-label">Nome do agente *</label>
            <input class="ae-input" type="text" name="name" value="<?= htmlspecialchars($agent['name']??'') ?>" placeholder="ex: Secretaria Virtual" required>
          </div>
          <?php if (!$isNew): ?>
          <div>
            <label class="ae-label">Status</label>
            <select name="status" class="ae-input" style="background:#fff">
              <?php foreach (['draft'=>'Rascunho','active'=>'Ativo','inactive'=>'Inativo'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= ($agent['status']??'draft')===$v?'selected':'' ?>><?= $l ?></option>
              <?php endforeach ?>
            </select>
          </div>
          <?php endif ?>
        </div>
        <div>
          <label class="ae-label">Modelo LLM</label>
          <select name="model" class="ae-input ae-mono" style="background:#fff">
            <?php foreach ($catalog as $pKey => $pInfo): ?>
            <optgroup label="<?= htmlspecialchars($pInfo['label']) ?> — <?= htmlspecialchars($pInfo['desc']) ?>">
              <?php foreach ($pInfo['models'] as $mKey => $mLabel): ?>
              <option value="<?= htmlspecialchars($mKey) ?>" <?= ($agent['model']??'llama-3.3-70b-versatile')===$mKey?'selected':'' ?>>
                <?= htmlspecialchars($mKey) ?> · <?= htmlspecialchars($mLabel) ?>
              </option>
              <?php endforeach ?>
            </optgroup>
            <?php endforeach ?>
          </select>
          <div style="font-size:.74rem;color:#8b8a93;margin-top:.3rem">Provedores habilitados pelo admin. Cada modelo tem custo e velocidade diferentes.</div>
        </div>
        <div>
          <label class="ae-label">System Prompt * <span style="font-weight:400;color:#8b8a93;font-size:.78rem">— Persona, objetivo e regras do agente</span></label>
          <textarea name="prompt" rows="10" required class="ae-input ae-mono" style="resize:vertical;line-height:1.6" placeholder="Voce e [nome], assistente de [empresa]. Seu objetivo e [objetivo]. Voce deve [comportamento]. Nao deve [restricoes]..."><?= htmlspecialchars($agent['prompt']??'') ?></textarea>
          <div style="font-size:.75rem;color:#8b8a93;margin-top:.3rem">Seja especifico sobre tom, limitacoes e fluxo esperado. O agente seguira este prompt a risca.</div>
        </div>

        <!-- Widget settings inline -->
        <?php if (!$isNew): ?>
        <div style="border-top:1px solid #f4f2ed;padding-top:1.1rem">
          <div class="ae-tag" style="margin-bottom:.6rem">WIDGET &middot; CHAT EMBED</div>
          <div style="display:flex;flex-direction:column;gap:1rem">
            <div class="ae-toggle-wrap">
              <label style="position:relative;display:block;cursor:pointer">
                <input type="checkbox" name="widget_enabled" <?= ($agent['widget_enabled']??0)?'checked':'' ?> onchange="this.nextElementSibling.classList.toggle('on',this.checked)" style="opacity:0;position:absolute;width:0;height:0">
                <div class="ae-toggle <?= ($agent['widget_enabled']??0)?'on':'' ?>"></div>
              </label>
              <span style="font-size:.875rem;font-weight:500;color:#18181b">Widget habilitado — permite embed no site do cliente</span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
              <div>
                <label class="ae-label">Cor do widget</label>
                <div style="display:flex;align-items:center;gap:.5rem">
                  <input type="color" name="widget_color" value="<?= htmlspecialchars($agent['widget_color']??'#0ea5e9') ?>" style="width:38px;height:38px;border:1px solid #e7e5e0;border-radius:6px;cursor:pointer;padding:2px" onchange="updatePreview()">
                  <input class="ae-input ae-mono" type="text" id="colorHex" value="<?= htmlspecialchars($agent['widget_color']??'#0ea5e9') ?>" style="width:90px" oninput="document.querySelector('[name=widget_color]').value=this.value;updatePreview()">
                </div>
              </div>
              <div>
                <label class="ae-label">Posicao</label>
                <select name="widget_position" class="ae-input" style="background:#fff" onchange="updatePreview()">
                  <option value="bottom-right" <?= ($agent['widget_position']??'bottom-right')==='bottom-right'?'selected':'' ?>>Direita</option>
                  <option value="bottom-left"  <?= ($agent['widget_position']??'bottom-right')==='bottom-left'?'selected':'' ?>>Esquerda</option>
                </select>
              </div>
              <div>
                <label class="ae-label">Preview</label>
                <div id="widgetPreview" style="display:flex;align-items:center;gap:.5rem">
                  <div id="pvBtn" style="width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <svg width="18" height="18" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                  </div>
                  <div id="pvBubble" class="ae-preview-bubble" style="color:#fff;font-size:.75rem">Ola!</div>
                </div>
              </div>
            </div>
            <div>
              <label class="ae-label">Mensagem de boas-vindas</label>
              <input class="ae-input" type="text" name="widget_greeting" value="<?= htmlspecialchars($agent['widget_greeting']??'Ola! Como posso ajudar?') ?>" placeholder="Ola! Como posso ajudar?" oninput="document.getElementById('pvBubble').textContent=this.value||'Ola!'">
            </div>
            <div>
              <label class="ae-label">Dominios autorizados <span style="font-weight:400;color:#8b8a93;font-size:.78rem">— um por linha. Vazio = aceita qualquer dominio</span></label>
              <textarea name="allowed_domains" rows="3" class="ae-input ae-mono" placeholder="meusite.com.br&#10;loja.meusite.com.br" style="resize:vertical;line-height:1.6;font-size:.82rem"><?= htmlspecialchars($allowedDomainsTxt) ?></textarea>
            </div>
          </div>
        </div>
        <?php endif ?>
      </div>
    </div>
    <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-bottom:1.5rem">
      <a href="agents.php" class="ae-btn-ghost">Cancelar</a>
      <button type="submit" class="ae-btn-primary"><?= $isNew ? 'Criar Agente' : 'Salvar' ?></button>
    </div>
  </form>

<?php if (!$isNew): ?>

  <!-- Embed code card -->
  <?php if ($embedToken): ?>
  <div class="ae-card" style="margin-bottom:1.5rem">
    <div class="ae-head" style="display:flex;align-items:center;justify-content:space-between">
      <div>
        <div class="ae-tag">EMBED &middot; SNIPPET</div>
        <h2 style="margin:0;font-size:1.05rem;font-weight:600;color:#18181b">Codigo para o site do cliente</h2>
      </div>
      <span style="font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:99px;background:<?= ($agent['widget_enabled']??0)?'#f0fdf4':'#f8fafc' ?>;color:<?= ($agent['widget_enabled']??0)?'#16a34a':'#8b8a93' ?>;border:1px solid <?= ($agent['widget_enabled']??0)?'#bbf7d0':'#e7e5e0' ?>">
        <?= ($agent['widget_enabled']??0) ? 'Widget ativo' : 'Widget desativado' ?>
      </span>
    </div>
    <div style="padding:1.25rem 1.5rem;display:flex;flex-direction:column;gap:1rem">
      <p style="margin:0;font-size:.85rem;color:#64748b">Cole este snippet antes do <code style="font-family:'Geist Mono',monospace;background:#f4f2ed;padding:1px 5px;border-radius:4px">&lt;/body&gt;</code> no HTML do cliente. O chat aparece automaticamente.</p>
      <div class="ae-code-box">
        <code id="embedSnippet"><?= htmlspecialchars($embedCode) ?></code>
        <button class="ae-copy-btn" onclick="copyEmbed(this)">copiar</button>
      </div>
      <div style="display:flex;gap:.75rem;align-items:center">
        <div style="flex:1">
          <label class="ae-label" style="margin-bottom:.3rem">Verificar dominio</label>
          <div style="display:flex;gap:.5rem">
            <input class="ae-input ae-mono" type="text" id="domainCheck" placeholder="meusite.com.br" style="flex:1;font-size:.82rem">
            <button type="button" onclick="verifyDomain()" style="padding:.6rem 1rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;font-size:.82rem;font-weight:600;color:#0284c7;cursor:pointer;white-space:nowrap">Verificar embed</button>
          </div>
        </div>
      </div>
      <div id="verifyResult" style="display:none;font-size:.82rem;padding:.6rem .9rem;border-radius:8px"></div>
    </div>
  </div>
  <?php endif ?>

  <form method="POST">
    <?= csrf_field() ?>
    <div class="ae-card">
      <div class="ae-head" style="display:flex;align-items:center;justify-content:space-between">
        <div>
          <div class="ae-tag">CANAL &middot; WHATSAPP Z-API</div>
          <h2 style="margin:0;font-size:1.05rem;font-weight:600;color:#18181b">Conectar instancia</h2>
        </div>
        <?php if ($channel): ?>
        <span style="font-size:.75rem;font-weight:600;padding:4px 12px;border-radius:99px;background:<?= $channel['status']==='connected'?'#f0fdf4':'#fef3c7' ?>;color:<?= $channel['status']==='connected'?'#16a34a':'#d97706' ?>">
          <?= $channel['status']==='connected' ? 'Conectado' : 'Desconectado' ?>
        </span>
        <?php endif ?>
      </div>
      <div class="ae-body">
        <?php $curMw = $chCfg['middleware'] ?? 'mobile'; ?>
        <?php if ($channel && $channel['status']==='connected'): ?>
        <div style="padding:.7rem 1rem;background:#f0fdf4;border-radius:8px;border:1px solid #bbf7d0;font-size:.85rem;color:#16a34a;display:flex;align-items:center;gap:.5rem">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
          Numero conectado: <strong><?= htmlspecialchars($channel['connected_phone']??'—') ?></strong>
          <span style="margin-left:auto;font-family:'Geist Mono',monospace;font-size:.66rem;background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-weight:600;letter-spacing:.04em;text-transform:uppercase"><?= $curMw === 'mobile' ? 'Mobile · Autonomo' : 'Web · Manual' ?></span>
        </div>
        <?php endif ?>

        <!-- Toggle modo Mobile vs Web -->
        <div>
          <label class="ae-label">Modo de operacao</label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
            <label style="cursor:pointer">
              <input type="radio" name="zapi_middleware" value="mobile" <?= $curMw==='mobile'?'checked':'' ?> onchange="toggleZapiMode()" style="display:none">
              <div class="ae-mw-card <?= $curMw==='mobile'?'on':'' ?>" data-mw="mobile" style="padding:.85rem 1rem;border:1.5px solid <?= $curMw==='mobile'?'#0ea5e9':'#e7e5e0' ?>;background:<?= $curMw==='mobile'?'#f0f9ff':'#fff' ?>;border-radius:10px;transition:all .15s">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.2rem">
                  <span style="font-size:1.1rem">📱</span>
                  <strong style="font-size:.86rem;color:#18181b">Mobile · SMS</strong>
                </div>
                <div style="font-size:.74rem;color:#64748b;line-height:1.4">Agente autonomo 24/7. Numero dedicado, sem espelho no celular.</div>
              </div>
            </label>
            <label style="cursor:pointer">
              <input type="radio" name="zapi_middleware" value="web" <?= $curMw==='web'?'checked':'' ?> onchange="toggleZapiMode()" style="display:none">
              <div class="ae-mw-card <?= $curMw==='web'?'on':'' ?>" data-mw="web" style="padding:.85rem 1rem;border:1.5px solid <?= $curMw==='web'?'#0ea5e9':'#e7e5e0' ?>;background:<?= $curMw==='web'?'#f0f9ff':'#fff' ?>;border-radius:10px;transition:all .15s">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.2rem">
                  <span style="font-size:1.1rem">💻</span>
                  <strong style="font-size:.86rem;color:#18181b">Web · QR Code</strong>
                </div>
                <div style="font-size:.74rem;color:#64748b;line-height:1.4">Espelha o WhatsApp do celular. Bom pra atendimento humano + IA.</div>
              </div>
            </label>
          </div>
        </div>

        <!-- Auto-discover button -->
        <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:.85rem 1rem;display:flex;align-items:center;gap:.85rem;flex-wrap:wrap">
          <div style="flex:1;min-width:200px">
            <div style="font-size:.84rem;font-weight:600;color:#0c4a6e;margin-bottom:.15rem">🔍 Auto-descobrir instâncias</div>
            <div style="font-size:.78rem;color:#0369a1;line-height:1.45">Listar instâncias Z-API disponíveis na conta da plataforma e preencher os campos automaticamente.</div>
          </div>
          <button type="button" onclick="discoverInstances()" id="discoverBtn" style="padding:.55rem 1rem;background:#fff;color:#0284c7;border:1px solid #bae6fd;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;white-space:nowrap">
            Listar instâncias
          </button>
        </div>
        <div id="discoverList" style="display:none"></div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div>
            <label class="ae-label">Instance ID</label>
            <input class="ae-input ae-mono" type="text" name="zapi_instance" id="fInstance" value="<?= htmlspecialchars($chCfg['instance']??'') ?>" placeholder="3ABC123...">
          </div>
          <div>
            <label class="ae-label">Token</label>
            <input class="ae-input ae-mono" type="text" name="zapi_token" id="fToken" value="<?= htmlspecialchars($chCfg['token']??'') ?>" placeholder="Token da instancia">
          </div>
        </div>
        <div>
          <label class="ae-label">Client Token</label>
          <input class="ae-input ae-mono" type="text" name="zapi_client" id="fClient" value="<?= htmlspecialchars($chCfg['client_token']??'') ?>" placeholder="Painel Z-API > Security > Client Token">
        </div>
        <?php if ($webhookUrl): ?>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.85rem 1rem">
          <div style="font-size:.72rem;font-weight:700;color:#64748b;margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.08em">Webhook URL · auto-configurado ao salvar</div>
          <div class="ae-mono" style="font-size:.74rem;color:#18181b;word-break:break-all"><?= htmlspecialchars($webhookUrl) ?></div>
        </div>
        <?php endif ?>
        <div style="display:flex;justify-content:flex-end">
          <button type="submit" class="ae-btn-primary">Salvar Canal</button>
        </div>
      </div>
    </div>
  </form>

  <?php if ($channel && $chCfg && ($channel['status']??'') !== 'connected'): ?>
    <?php if (($chCfg['middleware'] ?? 'mobile') === 'mobile'): ?>
    <!-- ── Mobile / SMS Flow ────────────────────────────────────────────────── -->
    <div class="ae-card" style="margin-bottom:1.5rem">
      <div class="ae-head" style="display:flex;align-items:center;justify-content:space-between">
        <div>
          <div class="ae-tag">CONEXAO &middot; MOBILE</div>
          <h2 style="margin:0;font-size:1.05rem;font-weight:600;color:#18181b">Conectar via SMS</h2>
        </div>
        <span style="font-size:.66rem;font-family:'Geist Mono',monospace;background:#fef3c7;color:#92400e;padding:3px 8px;border-radius:4px;font-weight:600;letter-spacing:.04em;text-transform:uppercase">Newton IA · Autonomo</span>
      </div>
      <div class="ae-body">
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:.85rem 1rem;font-size:.82rem;color:#92400e;line-height:1.55">
          <strong>⚠ Antes de continuar:</strong> a instancia precisa ser <strong>mobile</strong> no painel Z-API. Crie em <a href="https://app.z-api.io" target="_blank" style="color:#92400e;text-decoration:underline">app.z-api.io</a> &rsaquo; <em>Nova instancia</em> &rsaquo; <em>Mobile</em>. Cole as credenciais acima e salve. So depois siga o fluxo abaixo.
        </div>

        <!-- Pre-flight checklist anti-ban -->
        <div id="preflightBox" style="background:#fff;border:1.5px solid #fecaca;border-radius:10px;padding:1rem 1.1rem">
          <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem">
            <svg width="18" height="18" fill="none" stroke="#dc2626" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            <strong style="font-size:.92rem;color:#991b1b">Checklist anti-banimento</strong>
          </div>
          <div style="font-size:.82rem;color:#7f1d1d;line-height:1.5;margin-bottom:.85rem">
            WhatsApp bane números novos conectados em automação. <strong>Confirme antes de prosseguir:</strong>
          </div>
          <div style="display:flex;flex-direction:column;gap:.65rem">
            <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;font-size:.86rem;color:#18181b;line-height:1.45">
              <input type="checkbox" id="pf-chip" onchange="updatePreflight()" style="margin-top:.2rem;accent-color:#0ea5e9;width:16px;height:16px;cursor:pointer">
              <span>Tenho um <strong>chip exclusivo</strong> pra este agente (não é meu pessoal/empresa)</span>
            </label>
            <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;font-size:.86rem;color:#18181b;line-height:1.45">
              <input type="checkbox" id="pf-24h" onchange="updatePreflight()" style="margin-top:.2rem;accent-color:#0ea5e9;width:16px;height:16px;cursor:pointer">
              <span>O WhatsApp deste número está <strong>ativo há mais de 24 horas</strong> (ideal: 3+ dias com uso humano)</span>
            </label>
            <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;font-size:.86rem;color:#18181b;line-height:1.45">
              <input type="checkbox" id="pf-aware" onchange="updatePreflight()" style="margin-top:.2rem;accent-color:#0ea5e9;width:16px;height:16px;cursor:pointer">
              <span>Entendo que ao conectar aqui, o WhatsApp <strong>sairá do meu celular</strong> (instância mobile = autônoma)</span>
            </label>
          </div>
          <div style="margin-top:.85rem;padding-top:.7rem;border-top:1px dashed #fecaca;font-size:.74rem;color:#7f1d1d;line-height:1.45">
            💡 <strong>Dica de aquecimento:</strong> antes de conectar, use o número manualmente por 2-3 dias enviando mensagens pra contatos diversos. Isso reduz drasticamente o risco de ban.
          </div>
        </div>

        <!-- Step 1: phone -->
        <div id="mobileStep1" style="opacity:.45;pointer-events:none;transition:opacity .2s" data-locked="1">
          <label class="ae-label">Numero do agente (DDD + numero)</label>
          <div style="display:flex;gap:.5rem">
            <input id="mobilePhone" class="ae-input ae-mono" type="text" placeholder="11999999999" maxlength="15" style="flex:1;font-size:.95rem;letter-spacing:.02em">
            <select id="mobileMethod" class="ae-input" style="background:#fff;width:120px">
              <option value="sms">SMS</option>
              <option value="voice">Ligacao</option>
              <option value="wa_old">WhatsApp antigo</option>
            </select>
          </div>
          <div style="font-size:.75rem;color:#8b8a93;margin-top:.3rem">Codigo do pais (55) sera adicionado automaticamente. Use o numero que sera dedicado ao agente.</div>
          <div style="margin-top:1rem;display:flex;gap:.5rem;align-items:center">
            <button type="button" onclick="mobileRequestCode()" id="mobileRequestBtn" class="ae-btn-primary" disabled>
              Solicitar codigo
            </button>
            <span id="mobileWait" style="font-size:.8rem;color:#8b8a93;display:none"></span>
            <span id="preflightHint" style="font-size:.78rem;color:#dc2626;font-weight:500">Confirme o checklist acima primeiro</span>
          </div>
        </div>

        <!-- Step 2: confirm code -->
        <div id="mobileStep2" style="display:none;border-top:1px solid #f4f2ed;padding-top:1.1rem">
          <label class="ae-label">Codigo recebido (6 digitos)</label>
          <input id="mobileCode" class="ae-input ae-mono" type="text" placeholder="000000" maxlength="6" style="font-size:1.4rem;text-align:center;letter-spacing:.4em;font-weight:600">
          <div style="margin-top:1rem;display:flex;gap:.5rem;align-items:center;justify-content:space-between">
            <button type="button" onclick="mobileBackStep1()" class="ae-btn-ghost">&larr; Voltar</button>
            <button type="button" onclick="mobileConfirmCode()" id="mobileConfirmBtn" class="ae-btn-primary">
              Confirmar codigo
            </button>
          </div>
        </div>

        <!-- Status messages -->
        <div id="mobileMsg" style="display:none;padding:.7rem 1rem;border-radius:8px;font-size:.85rem"></div>
      </div>
    </div>

    <?php else: ?>
    <!-- ── Web / QR Flow ────────────────────────────────────────────────────── -->
    <div class="ae-card" style="margin-bottom:1.5rem">
      <div class="ae-head"><h2 style="margin:0;font-size:1rem;font-weight:600;color:#18181b">Conectar via QR Code</h2></div>
      <div style="padding:1.5rem;text-align:center">
        <button onclick="loadQR()" style="padding:.65rem 1.3rem;background:#f0f9ff;color:#0ea5e9;border:1px solid #bae6fd;border-radius:8px;font-size:.875rem;font-weight:600;cursor:pointer">Gerar QR Code</button>
        <div id="qrImg" style="margin-top:1rem;display:none">
          <img id="qrSrc" src="" alt="QR" style="max-width:240px;border-radius:8px;border:1px solid #e7e5e0">
          <p style="font-size:.82rem;color:#8b8a93;margin:.75rem 0 .3rem">WhatsApp &rsaquo; Aparelhos Conectados &rsaquo; Conectar aparelho</p>
          <button onclick="loadQR()" style="font-size:.8rem;color:#0ea5e9;background:none;border:none;cursor:pointer;text-decoration:underline">Atualizar QR</button>
        </div>
      </div>
    </div>
    <?php endif ?>
  <?php endif ?>

  <div style="display:flex;gap:.75rem;margin-bottom:1.5rem;flex-wrap:wrap">
    <a href="agent-train.php?id=<?= (int)$agent['id'] ?>" style="flex:1;min-width:180px;padding:.75rem;background:linear-gradient(135deg,#ecfeff,#dbeafe);border:1px solid #bae6fd;border-radius:10px;text-align:center;color:#0284c7;text-decoration:none;font-size:.875rem;font-weight:600">
      🧠 Treino · KB e gatilhos
    </a>
    <a href="agent-test.php?id=<?= (int)$agent['id'] ?>" style="flex:1;min-width:140px;padding:.75rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;text-align:center;color:#0284c7;text-decoration:none;font-size:.875rem;font-weight:600">
      Testar no browser
    </a>
    <a href="conversations.php?agent_id=<?= (int)$agent['id'] ?>" style="flex:1;min-width:140px;padding:.75rem;background:#f8fafc;border:1px solid #e7e5e0;border-radius:10px;text-align:center;color:#3a3a40;text-decoration:none;font-size:.875rem;font-weight:600">
      Ver conversas
    </a>
  </div>

  <form method="POST" onsubmit="return confirm('Deletar este agente? Esta acao nao pode ser desfeita.')">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="delete">
    <div style="background:#fff;border:1px solid #fecaca;border-radius:12px;padding:1.25rem 1.5rem;display:flex;align-items:center;justify-content:space-between">
      <div>
        <div style="font-size:.875rem;font-weight:600;color:#dc2626;margin-bottom:.15rem">Deletar agente</div>
        <div style="font-size:.8rem;color:#8b8a93">Remove o agente, canal e historico de conversas permanentemente.</div>
      </div>
      <button type="submit" style="padding:.6rem 1.1rem;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer">Deletar</button>
    </div>
  </form>

<?php endif ?>
</div>

<script>
// Widget preview
function updatePreview(){
  var color = document.querySelector('[name=widget_color]').value;
  document.getElementById('pvBtn').style.background = color;
  document.getElementById('pvBubble').style.background = color;
  document.getElementById('colorHex').value = color;
}
updatePreview();

<?php if ($channel): ?>
function loadQR(){
  fetch('/app/agent-qr.php?id=<?= (int)$channel['id'] ?>')
    .then(r=>r.json()).then(d=>{
      if(d.qr){document.getElementById('qrSrc').src='data:image/png;base64,'+d.qr;document.getElementById('qrImg').style.display='block';}
      else alert('Erro ao gerar QR. Verifique as credenciais Z-API.');
    });
}

// ── Toggle Mobile/Web visual ──────────────────────────────────────────────
function toggleZapiMode(){
  var sel = document.querySelector('input[name=zapi_middleware]:checked');
  if(!sel) return;
  document.querySelectorAll('.ae-mw-card').forEach(function(card){
    var on = card.dataset.mw === sel.value;
    card.classList.toggle('on', on);
    card.style.borderColor = on ? '#0ea5e9' : '#e7e5e0';
    card.style.background  = on ? '#f0f9ff' : '#fff';
  });
}

// ── Mobile (SMS) connection flow ──────────────────────────────────────────
var _channelId = <?= (int)$channel['id'] ?>;

function _mobileMsg(type, text){
  var box = document.getElementById('mobileMsg');
  if(!box) return;
  box.style.display = 'block';
  var colors = {
    ok:    ['#f0fdf4','#16a34a','#bbf7d0'],
    err:   ['#fef2f2','#dc2626','#fecaca'],
    info:  ['#f0f9ff','#0284c7','#bae6fd'],
  }[type] || ['#f8fafc','#64748b','#e7e5e0'];
  box.style.background = colors[0];
  box.style.color      = colors[1];
  box.style.border     = '1px solid ' + colors[2];
  box.textContent      = text;
}

async function mobileRequestCode(){
  var phone  = document.getElementById('mobilePhone').value.replace(/\D/g,'');
  var method = document.getElementById('mobileMethod').value;
  var btn    = document.getElementById('mobileRequestBtn');
  if(phone.length < 10){ _mobileMsg('err','Numero invalido. Use DDD + numero.'); return; }
  btn.disabled = true; btn.textContent = 'Solicitando...';
  _mobileMsg('info','Z-API enviando codigo via ' + method.toUpperCase() + '...');
  try {
    var fd = new FormData();
    fd.append('_csrf', <?= json_encode(csrf_token()) ?>);
    fd.append('channel_id', _channelId);
    fd.append('phone',  phone);
    fd.append('method', method);
    fd.append('pf_chip',  document.getElementById('pf-chip').checked ? '1' : '0');
    fd.append('pf_24h',   document.getElementById('pf-24h').checked ? '1' : '0');
    fd.append('pf_aware', document.getElementById('pf-aware').checked ? '1' : '0');
    var r = await fetch('/app/agent-mobile-request.php', { method:'POST', body:fd }).then(r=>r.json());
    if(r.ok){
      _mobileMsg('ok','Codigo enviado! Aguarde a chegada e confirme abaixo.');
      document.getElementById('mobileStep1').style.display = 'none';
      document.getElementById('mobileStep2').style.display = 'block';
      document.getElementById('mobileCode').focus();
      if(r.retryAfter){
        document.getElementById('mobileWait').style.display = 'inline';
        var t = parseInt(r.retryAfter);
        var tick = setInterval(function(){
          t--;
          if(t<=0){ clearInterval(tick); document.getElementById('mobileWait').style.display='none'; }
          else document.getElementById('mobileWait').textContent = 'Aguarde ' + t + 's pra reenviar';
        }, 1000);
      }
    } else {
      _mobileMsg('err', r.error || 'Erro ao solicitar codigo.');
    }
  } catch(e){ _mobileMsg('err','Erro de conexao: '+e.message); }
  finally { btn.disabled=false; btn.textContent='Solicitar codigo'; }
}

function mobileBackStep1(){
  document.getElementById('mobileStep1').style.display = 'block';
  document.getElementById('mobileStep2').style.display = 'none';
  document.getElementById('mobileMsg').style.display = 'none';
}

// ── Preflight checklist anti-ban ──────────────────────────────────────────
function updatePreflight(){
  var chip  = document.getElementById('pf-chip');
  var h24   = document.getElementById('pf-24h');
  var aware = document.getElementById('pf-aware');
  if (!chip || !h24 || !aware) return;
  var allOk = chip.checked && h24.checked && aware.checked;
  var step1 = document.getElementById('mobileStep1');
  var btn   = document.getElementById('mobileRequestBtn');
  var hint  = document.getElementById('preflightHint');
  var box   = document.getElementById('preflightBox');
  if (allOk) {
    step1.style.opacity = '1';
    step1.style.pointerEvents = 'auto';
    step1.dataset.locked = '0';
    btn.disabled = false;
    hint.style.display = 'none';
    box.style.borderColor = '#bbf7d0';
    box.style.background  = '#f0fdf4';
    box.querySelector('strong').style.color = '#166534';
    document.getElementById('mobilePhone').focus();
  } else {
    step1.style.opacity = '.45';
    step1.style.pointerEvents = 'none';
    step1.dataset.locked = '1';
    btn.disabled = true;
    hint.style.display = 'inline';
    box.style.borderColor = '#fecaca';
    box.style.background  = '#fff';
    box.querySelector('strong').style.color = '#991b1b';
  }
}

async function discoverInstances(){
  var btn  = document.getElementById('discoverBtn');
  var list = document.getElementById('discoverList');
  btn.disabled = true; btn.textContent = 'Buscando...';
  list.style.display = 'block';
  list.innerHTML = '<div style="padding:.85rem;font-size:.82rem;color:#64748b">Consultando Z-API Partner API...</div>';
  try {
    var mw = (document.querySelector('input[name=zapi_middleware]:checked') || {value:'mobile'}).value;
    var r = await fetch('/app/agent-discover.php?middleware=' + mw).then(r=>r.json());
    if (!r.ok) {
      list.innerHTML = '<div style="padding:.85rem;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:.82rem;color:#991b1b">✗ ' + (r.error || 'Erro ao buscar') + '</div>';
      return;
    }
    if (!r.instances.length) {
      list.innerHTML = '<div style="padding:.85rem;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:.82rem;color:#92400e">⚠ Nenhuma instância ' + mw + ' encontrada. Crie no painel Z-API primeiro.</div>';
      return;
    }
    var html = '<div style="border:1px solid #e7e5e0;border-radius:10px;overflow:hidden">';
    r.instances.forEach(function(i, idx){
      var inUse  = i.in_use && !i.in_use_by_me;
      var thisAgent = i.in_use_by_me;
      var phone = i.phone_connected ? '📱 ' + i.phone_connected : '<span style="color:#94a3b8">não conectado</span>';
      var badge = '';
      if (inUse)     badge = '<span style="font-size:.66rem;background:#fef2f2;color:#dc2626;padding:2px 7px;border-radius:4px;font-weight:600;font-family:Geist Mono,monospace;letter-spacing:.04em">Em uso por outro tenant</span>';
      else if (thisAgent) badge = '<span style="font-size:.66rem;background:#f0fdf4;color:#16a34a;padding:2px 7px;border-radius:4px;font-weight:600;font-family:Geist Mono,monospace;letter-spacing:.04em">Em uso aqui</span>';
      else               badge = '<span style="font-size:.66rem;background:#f0f9ff;color:#0284c7;padding:2px 7px;border-radius:4px;font-weight:600;font-family:Geist Mono,monospace;letter-spacing:.04em">Disponível</span>';

      html += '<div style="display:flex;align-items:center;gap:.85rem;padding:.85rem 1rem;border-bottom:' + (idx < r.instances.length-1 ? '1px solid #f4f2ed' : 'none') + ';background:' + (inUse ? '#fafafa' : '#fff') + ';opacity:' + (inUse ? '.55' : '1') + '">';
      html += '  <div style="flex:1;min-width:0">';
      html += '    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.2rem">';
      html += '      <strong style="font-size:.86rem;color:#18181b">' + (i.name || 'Sem nome') + '</strong>';
      html += '      ' + badge;
      html += '      <span style="font-size:.62rem;font-family:Geist Mono,monospace;background:#f4f2ed;color:#64748b;padding:1px 6px;border-radius:3px;text-transform:uppercase;letter-spacing:.04em">' + i.middleware + '</span>';
      html += '    </div>';
      html += '    <div style="font-size:.74rem;color:#8b8a93;font-family:Geist Mono,monospace">' + i.id.substring(0,20) + '... · ' + phone + '</div>';
      html += '  </div>';
      if (!inUse) {
        html += '  <button type="button" onclick="useInstance(\'' + i.id + '\', \'' + i.token + '\', \'' + r.client_token + '\')" style="padding:.45rem .85rem;background:#0ea5e9;color:#fff;border:none;border-radius:7px;font-size:.78rem;font-weight:600;cursor:pointer;white-space:nowrap">Usar essa</button>';
      }
      html += '</div>';
    });
    html += '</div>';
    list.innerHTML = html;
  } catch(e) {
    list.innerHTML = '<div style="padding:.85rem;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:.82rem;color:#991b1b">✗ Erro: ' + e.message + '</div>';
  } finally {
    btn.disabled = false; btn.textContent = 'Listar instâncias';
  }
}

function useInstance(id, token, clientToken){
  document.getElementById('fInstance').value = id;
  document.getElementById('fToken').value    = token;
  if (clientToken && !document.getElementById('fClient').value) {
    document.getElementById('fClient').value = clientToken;
  }
  // Highlight
  ['fInstance','fToken','fClient'].forEach(function(fid){
    var el = document.getElementById(fid);
    el.style.borderColor = '#22c55e';
    setTimeout(function(){ el.style.borderColor = ''; }, 1500);
  });
  document.getElementById('discoverList').style.display = 'none';
  window.scrollTo({ top: document.getElementById('fInstance').offsetTop - 100, behavior: 'smooth' });
}

async function mobileConfirmCode(){
  var code = document.getElementById('mobileCode').value.replace(/\D/g,'');
  var btn  = document.getElementById('mobileConfirmBtn');
  if(code.length !== 6){ _mobileMsg('err','Codigo deve ter 6 digitos.'); return; }
  btn.disabled = true; btn.textContent = 'Confirmando...';
  _mobileMsg('info','Confirmando codigo...');
  try {
    var fd = new FormData();
    fd.append('_csrf', <?= json_encode(csrf_token()) ?>);
    fd.append('channel_id', _channelId);
    fd.append('code', code);
    var r = await fetch('/app/agent-mobile-confirm.php', { method:'POST', body:fd }).then(r=>r.json());
    if(r.ok){
      _mobileMsg('ok','✓ Conectado! Recarregando...');
      setTimeout(function(){ location.reload(); }, 1500);
    } else if (r.confirmSecurityCode) {
      _mobileMsg('err','Codigo de seguranca/PIN exigido. Verifique no celular do numero.');
    } else if (r.deviceConfirm) {
      _mobileMsg('info','Aguardando confirmacao em outro aparelho. Verifique o celular.');
      setTimeout(function(){ location.reload(); }, 8000);
    } else {
      _mobileMsg('err', r.error || 'Codigo invalido ou expirado.');
    }
  } catch(e){ _mobileMsg('err','Erro de conexao: '+e.message); }
  finally { btn.disabled=false; btn.textContent='Confirmar codigo'; }
}
<?php endif ?>

function copyEmbed(btn){
  var text = document.getElementById('embedSnippet').textContent;
  navigator.clipboard.writeText(text).then(function(){
    btn.textContent = 'copiado!';
    btn.style.color = '#22c55e';
    setTimeout(function(){ btn.textContent = 'copiar'; btn.style.color = ''; }, 2000);
  });
}

function verifyDomain(){
  var domain = document.getElementById('domainCheck').value.trim();
  if(!domain){ alert('Informe um dominio.'); return; }
  var result = document.getElementById('verifyResult');
  result.style.display = 'block';
  result.style.background = '#f8fafc';
  result.style.color = '#64748b';
  result.style.border = '1px solid #e7e5e0';
  result.textContent = 'Verificando ' + domain + '...';

  fetch('/app/agent-verify-domain.php?id=<?= (int)($agent['id']??0) ?>&domain=' + encodeURIComponent(domain))
    .then(function(r){ return r.json(); })
    .then(function(d){
      if(d.found){
        result.style.background = '#f0fdf4';
        result.style.color = '#16a34a';
        result.style.border = '1px solid #bbf7d0';
        result.textContent = '✓ Widget encontrado em ' + domain;
      } else {
        result.style.background = '#fef2f2';
        result.style.color = '#dc2626';
        result.style.border = '1px solid #fecaca';
        result.textContent = '✗ Widget nao detectado em ' + domain + (d.error ? ' — ' + d.error : '. Verifique se o snippet foi instalado.');
      }
    })
    .catch(function(){
      result.style.background = '#fef2f2';
      result.style.color = '#dc2626';
      result.style.border = '1px solid #fecaca';
      result.textContent = '✗ Erro de conexao ao verificar dominio.';
    });
}
</script>
<?php });
