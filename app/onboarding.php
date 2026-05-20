<?php
/**
 * Newton IA — Onboarding wizard pós-ativação
 * Exibido uma única vez após o primeiro acesso ao plano ativo.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_layout.php';

$tenant = require_tenant();
$tid    = (int) $tenant['id'];
$uid    = (int) auth_user_id();

if (user_pref_get($uid, 'onboarding_completed', '0') === '1') {
    header('Location: /app/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step'])) {
    require_csrf();
    $step = (int) $_POST['step'];

    if ($step === 1) {
        $brand = trim($_POST['brand_name'] ?? '');
        if ($brand) {
            db_q('UPDATE tenants SET brand_name = ? WHERE id = ?', [$brand, $tid]);
        }
        user_pref_set($uid, 'onboarding_step', '2');
        header('Location: onboarding.php?step=2');
        exit;
    }

    if ($step === 2) {
        // Nada a salvar — step informativo
        user_pref_set($uid, 'onboarding_step', '3');
        header('Location: onboarding.php?step=3');
        exit;
    }

    if ($step === 3) {
        user_pref_set($uid, 'onboarding_completed', '1');
        user_pref_set($uid, 'onboarding_step', '3');
        audit_log('user.onboarding_completed', 'user', $uid);
        header('Location: /app/agents.php');
        exit;
    }
}

$current_step = (int) ($_GET['step'] ?? user_pref_get($uid, 'onboarding_step', '1') ?: 1);
if ($current_step < 1) $current_step = 1;
if ($current_step > 3) $current_step = 3;

$plan = $tenant['plan_id'] ? db_one('SELECT name FROM plans WHERE id = ?', [$tenant['plan_id']]) : null;
$plan_name = $plan['name'] ?? 'Newton IA';

app_layout("Bem-vindo ao Newton IA", 'onboarding', function() use ($current_step, $tenant, $plan_name, $uid) {
?>
<style>
  .ob-wrap  { max-width:680px; margin:0 auto; padding:8px 0 40px; }
  .ob-steps { display:flex; gap:0; margin-bottom:36px; }
  .ob-step  { flex:1; display:flex; align-items:center; flex-direction:column; gap:6px; position:relative; }
  .ob-step::after { content:''; position:absolute; top:18px; left:calc(50% + 18px); right:calc(-50% + 18px); height:2px; background:var(--line); z-index:0; }
  .ob-step:last-child::after { display:none; }
  .ob-step-dot { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.88rem; font-family:'Geist Mono',monospace; z-index:1; border:2px solid var(--line); background:#fff; color:var(--mute); transition:all .2s; }
  .ob-step.done .ob-step-dot  { background:var(--newton); border-color:var(--newton); color:#fff; }
  .ob-step.active .ob-step-dot { background:var(--ink); border-color:var(--ink); color:#fff; }
  .ob-step.done::after  { background:var(--newton); }
  .ob-step-label { font-size:.72rem; font-family:'Geist Mono',monospace; color:var(--mute); text-transform:uppercase; letter-spacing:.06em; text-align:center; }
  .ob-step.active .ob-step-label { color:var(--ink); font-weight:700; }
  .ob-step.done  .ob-step-label { color:var(--newton); }

  .ob-card  { background:#fff; border:1px solid var(--line); border-radius:16px; padding:32px 36px; box-shadow:0 2px 12px rgba(0,0,0,.04); }
  .ob-card h2 { font-size:1.5rem; font-weight:800; margin:0 0 6px; letter-spacing:-.03em; }
  .ob-card p  { color:var(--mute); font-size:.92rem; line-height:1.6; margin:0 0 26px; }

  .ob-field { margin-bottom:18px; }
  .ob-field label { display:block; font-family:'Geist Mono',monospace; font-size:.64rem; color:var(--mute); text-transform:uppercase; letter-spacing:.06em; font-weight:600; margin-bottom:7px; }
  .ob-field input { width:100%; padding:12px 14px; border:1px solid var(--line); border-radius:10px; font-size:.94rem; font-family:inherit; background:#fff; color:var(--ink); transition:all .15s; box-sizing:border-box; }
  .ob-field input:focus { outline:none; border-color:var(--newton); box-shadow:0 0 0 3px rgba(14,165,233,.12); }
  .ob-field .hint { font-size:.76rem; color:var(--mute); margin-top:5px; }

  .ob-btn { width:100%; padding:14px; background:var(--newton); color:#fff; border:none; border-radius:10px; font-size:1rem; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; margin-top:8px; display:flex; align-items:center; justify-content:center; gap:8px; }
  .ob-btn:hover { background:#0284c7; transform:translateY(-1px); box-shadow:0 4px 12px rgba(14,165,233,.3); }
  .ob-skip { display:block; text-align:center; margin-top:14px; font-size:.84rem; color:var(--mute); cursor:pointer; text-decoration:none; }
  .ob-skip:hover { color:var(--ink); }

  .ob-feature-list { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin:20px 0 28px; }
  .ob-feature { background:var(--bone); border-radius:10px; padding:14px 16px; display:flex; align-items:flex-start; gap:10px; }
  .ob-feature .ic { font-size:1.2rem; flex-shrink:0; }
  .ob-feature strong { display:block; font-size:.88rem; margin-bottom:2px; }
  .ob-feature span  { font-size:.78rem; color:var(--mute); line-height:1.4; }

  .ob-channel-list { display:flex; flex-direction:column; gap:10px; margin:20px 0 24px; }
  .ob-channel { background:var(--bone); border-radius:10px; padding:14px 16px; display:flex; align-items:center; gap:14px; }
  .ob-channel .ch-icon { width:40px; height:40px; border-radius:10px; background:#25d366; color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
  .ob-channel strong { font-size:.92rem; display:block; margin-bottom:2px; }
  .ob-channel span { font-size:.78rem; color:var(--mute); }
  .ob-channel .ch-badge { margin-left:auto; font-family:'Geist Mono',monospace; font-size:.6rem; background:var(--bone); border:1px solid var(--line); color:var(--mute); padding:3px 8px; border-radius:4px; font-weight:600; text-transform:uppercase; flex-shrink:0; }
</style>

<div class="ob-wrap">
  <div class="ob-steps">
    <div class="ob-step <?= $current_step > 1 ? 'done' : ($current_step === 1 ? 'active' : '') ?>">
      <div class="ob-step-dot"><?= $current_step > 1 ? '✓' : '1' ?></div>
      <div class="ob-step-label">Sua empresa</div>
    </div>
    <div class="ob-step <?= $current_step > 2 ? 'done' : ($current_step === 2 ? 'active' : '') ?>">
      <div class="ob-step-dot"><?= $current_step > 2 ? '✓' : '2' ?></div>
      <div class="ob-step-label">Agentes IA</div>
    </div>
    <div class="ob-step <?= $current_step === 3 ? 'active' : '' ?>">
      <div class="ob-step-dot">3</div>
      <div class="ob-step-label">Conectar canal</div>
    </div>
  </div>

  <?php if ($current_step === 1): ?>
  <div class="ob-card">
    <div style="font-family:'Geist Mono',monospace;font-size:.66rem;color:var(--newton);text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin-bottom:8px">// plano <?= htmlspecialchars($plan_name) ?> ativo</div>
    <h2>Bem-vindo ao Newton IA 👋</h2>
    <p>Em 3 passos você configura sua conta e cria seu primeiro agente de IA. Leva menos de 2 minutos.</p>

    <div class="ob-feature-list">
      <div class="ob-feature"><span class="ic">🤖</span><div><strong>Agentes de IA</strong><span>Crie agentes personalizados com prompts, modelo e contexto próprios</span></div></div>
      <div class="ob-feature"><span class="ic">💬</span><div><strong>WhatsApp · Z-API</strong><span>Conecte canais WhatsApp e deixe seus agentes atender automaticamente</span></div></div>
      <div class="ob-feature"><span class="ic">📥</span><div><strong>Inbox Unificado</strong><span>Handoff humano quando necessário — sua equipe assume a conversa</span></div></div>
      <div class="ob-feature"><span class="ic">📊</span><div><strong>Conversas & Histórico</strong><span>Acompanhe todas as interações em tempo real com métricas completas</span></div></div>
    </div>

    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="1">
      <div class="ob-field">
        <label>Como podemos chamar sua empresa? (nome comercial)</label>
        <input type="text" name="brand_name"
               value="<?= htmlspecialchars($tenant['brand_name'] ?? $tenant['name'] ?? '') ?>"
               placeholder="Ex: Acme Soluções" maxlength="120">
        <div class="hint">Aparece no header e nos relatórios. Pode mudar depois em Configurações.</div>
      </div>
      <button type="submit" class="ob-btn">Continuar → <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></button>
    </form>
    <a href="onboarding.php?step=2" class="ob-skip">Pular esta etapa →</a>
  </div>

  <?php elseif ($current_step === 2): ?>
  <div class="ob-card">
    <div style="font-family:'Geist Mono',monospace;font-size:.66rem;color:var(--newton);text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin-bottom:8px">// step 2 de 3</div>
    <h2>Criando seu primeiro agente 🤖</h2>
    <p>Um agente é uma IA com personalidade, tom de voz e base de conhecimento próprios. Você pode criar quantos quiser dentro do seu plano.</p>

    <div style="background:var(--bone);border-radius:12px;padding:20px 22px;margin:0 0 24px">
      <div style="font-family:'Geist Mono',monospace;font-size:.62rem;color:var(--mute);text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px">// como funciona</div>
      <div style="display:flex;flex-direction:column;gap:12px">
        <div style="display:flex;align-items:flex-start;gap:12px;font-size:.88rem">
          <span style="background:rgba(14,165,233,.12);color:var(--newton);border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-family:'Geist Mono',monospace;font-size:.8rem">1</span>
          <div><strong>Crie um agente</strong> — dê um nome, defina o prompt de sistema e escolha o modelo de LLM (Llama, GPT, etc.)</div>
        </div>
        <div style="display:flex;align-items:flex-start;gap:12px;font-size:.88rem">
          <span style="background:rgba(14,165,233,.12);color:var(--newton);border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-family:'Geist Mono',monospace;font-size:.8rem">2</span>
          <div><strong>Conecte um canal</strong> — vincule uma instância WhatsApp via Z-API para o agente atender</div>
        </div>
        <div style="display:flex;align-items:flex-start;gap:12px;font-size:.88rem">
          <span style="background:rgba(14,165,233,.12);color:var(--newton);border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-family:'Geist Mono',monospace;font-size:.8rem">3</span>
          <div><strong>Ative e monitore</strong> — o agente responde automaticamente e você acompanha no painel</div>
        </div>
      </div>
    </div>

    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="2">
      <button type="submit" class="ob-btn">Entendi → ir para Agentes <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></button>
    </form>
    <a href="onboarding.php?step=3" class="ob-skip">Pular →</a>
  </div>

  <?php elseif ($current_step === 3): ?>
  <div class="ob-card">
    <div style="font-family:'Geist Mono',monospace;font-size:.66rem;color:var(--newton);text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin-bottom:8px">// tudo certo!</div>
    <h2>Pronto pra conectar 🚀</h2>
    <p>Sua conta está configurada. Agora crie seu primeiro agente e conecte um canal WhatsApp.</p>

    <div class="ob-channel-list">
      <div class="ob-channel">
        <div class="ch-icon">📱</div>
        <div>
          <strong>WhatsApp via Z-API</strong>
          <span>Conecte um número WhatsApp e deixe seu agente atender 24/7</span>
        </div>
        <span class="ch-badge">Disponível</span>
      </div>
      <div class="ob-channel" style="opacity:.55">
        <div class="ch-icon" style="background:#1877f2">📘</div>
        <div>
          <strong>Instagram · Messenger</strong>
          <span>Atendimento via direct e Messenger</span>
        </div>
        <span class="ch-badge">Em breve</span>
      </div>
      <div class="ob-channel" style="opacity:.55">
        <div class="ch-icon" style="background:#0284c7">💬</div>
        <div>
          <strong>Widget Web</strong>
          <span>Chat no seu site em minutos</span>
        </div>
        <span class="ch-badge">Em breve</span>
      </div>
    </div>

    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="3">
      <button type="submit" class="ob-btn" style="font-size:1.05rem;padding:16px">
        🤖 Criar meu primeiro agente →
      </button>
    </form>
  </div>
  <?php endif; ?>

</div>
<?php
});
