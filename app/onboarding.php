<?php
/**
 * HERMES.b2b — Onboarding wizard pós-pagamento
 * Exibido uma única vez após a primeira ativação do plano.
 * Progresso salvo em user_preferences['onboarding_step'] e ['onboarding_completed'].
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_layout.php';

$tenant = require_tenant();
$tid    = (int) $tenant['id'];
$uid    = (int) auth_user_id();

// Se já completou, redireciona pro painel
if (user_pref_get($uid, 'onboarding_completed', '0') === '1') {
    header('Location: /app/');
    exit;
}

// Ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step'])) {
    require_csrf(); // CSRF obrigatório
    $step = (int) $_POST['step'];

    if ($step === 1) {
        // Salva nome fantasia + vertical preferida
        $brand = trim($_POST['brand_name'] ?? '');
        $vert  = trim($_POST['vertical'] ?? '');
        if ($brand) {
            db_q('UPDATE tenants SET brand_name = ? WHERE id = ?', [$brand, $tid]);
        }
        if ($vert) {
            user_pref_set($uid, 'radar_default_vertical', $vert);
        }
        user_pref_set($uid, 'onboarding_step', '2');
        header('Location: onboarding.php?step=2');
        exit;
    }

    if ($step === 2) {
        // Garante colunas padrão no Pipeline e avança
        $cols = crm_ensure_columns($tid);
        user_pref_set($uid, 'onboarding_step', '3');
        header('Location: onboarding.php?step=3');
        exit;
    }

    if ($step === 3) {
        // Marca onboarding como completo
        user_pref_set($uid, 'onboarding_completed', '1');
        user_pref_set($uid, 'onboarding_step', '3');
        audit_log('user.onboarding_completed', 'user', $uid);
        header('Location: /app/cnpj.php');
        exit;
    }
}

$current_step = (int) ($_GET['step'] ?? user_pref_get($uid, 'onboarding_step', '1') ?: 1);
if ($current_step < 1) $current_step = 1;
if ($current_step > 3) $current_step = 3;

// Lista de verticais para o select
require_once __DIR__ . '/../core/cnpj_verticais.php';
$verticais = cnpj_verticais_list();

$plan = $tenant['plan_id'] ? db_one('SELECT name FROM plans WHERE id = ?', [$tenant['plan_id']]) : null;
$plan_name = $plan['name'] ?? 'HERMES.b2b';

app_layout("Bem-vindo ao HERMES.b2b", 'onboarding', function() use ($current_step, $tenant, $plan_name, $verticais, $uid) {
?>
<style>
  .ob-wrap  { max-width:680px; margin:0 auto; padding:8px 0 40px; }
  .ob-steps { display:flex; gap:0; margin-bottom:36px; }
  .ob-step  { flex:1; display:flex; align-items:center; flex-direction:column; gap:6px; position:relative; }
  .ob-step::after { content:''; position:absolute; top:18px; left:calc(50% + 18px); right:calc(-50% + 18px); height:2px; background:var(--line); z-index:0; }
  .ob-step:last-child::after { display:none; }
  .ob-step-dot { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.88rem; font-family:'Geist Mono',monospace; z-index:1; border:2px solid var(--line); background:#fff; color:var(--mute); transition:all .2s; }
  .ob-step.done .ob-step-dot  { background:var(--hermes); border-color:var(--hermes); color:#fff; }
  .ob-step.active .ob-step-dot { background:var(--ink); border-color:var(--ink); color:#fff; }
  .ob-step.done::after  { background:var(--hermes); }
  .ob-step-label { font-size:.72rem; font-family:'Geist Mono',monospace; color:var(--mute); text-transform:uppercase; letter-spacing:.06em; text-align:center; }
  .ob-step.active .ob-step-label { color:var(--ink); font-weight:700; }
  .ob-step.done  .ob-step-label { color:var(--hermes); }

  .ob-card  { background:#fff; border:1px solid var(--line); border-radius:16px; padding:32px 36px; box-shadow:0 2px 12px rgba(0,0,0,.04); }
  .ob-card h2 { font-size:1.5rem; font-weight:800; margin:0 0 6px; letter-spacing:-.03em; }
  .ob-card p  { color:var(--mute); font-size:.92rem; line-height:1.6; margin:0 0 26px; }

  .ob-field { margin-bottom:18px; }
  .ob-field label { display:block; font-family:'Geist Mono',monospace; font-size:.64rem; color:var(--mute); text-transform:uppercase; letter-spacing:.06em; font-weight:600; margin-bottom:7px; }
  .ob-field input, .ob-field select { width:100%; padding:12px 14px; border:1px solid var(--line); border-radius:10px; font-size:.94rem; font-family:inherit; background:#fff; color:var(--ink); transition:all .15s; box-sizing:border-box; }
  .ob-field input:focus, .ob-field select:focus { outline:none; border-color:var(--hermes); box-shadow:0 0 0 3px rgba(16,185,129,.12); }
  .ob-field .hint { font-size:.76rem; color:var(--mute); margin-top:5px; }

  .ob-btn { width:100%; padding:14px; background:var(--hermes); color:#fff; border:none; border-radius:10px; font-size:1rem; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; margin-top:8px; display:flex; align-items:center; justify-content:center; gap:8px; }
  .ob-btn:hover { background:#0ea371; transform:translateY(-1px); box-shadow:0 4px 12px rgba(16,185,129,.3); }
  .ob-skip { display:block; text-align:center; margin-top:14px; font-size:.84rem; color:var(--mute); cursor:pointer; text-decoration:none; }
  .ob-skip:hover { color:var(--ink); }

  .ob-feature-list { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin:20px 0 28px; }
  .ob-feature { background:var(--bone); border-radius:10px; padding:14px 16px; display:flex; align-items:flex-start; gap:10px; }
  .ob-feature .ic { font-size:1.2rem; flex-shrink:0; }
  .ob-feature strong { display:block; font-size:.88rem; margin-bottom:2px; }
  .ob-feature span  { font-size:.78rem; color:var(--mute); line-height:1.4; }

  .ob-pipe-preview { display:flex; gap:10px; margin:20px 0; overflow:hidden; }
  .ob-pipe-col { background:var(--bone); border-radius:10px; padding:12px; flex:1; min-width:0; }
  .ob-pipe-col-head { font-family:'Geist Mono',monospace; font-size:.66rem; font-weight:700; color:var(--mute); text-transform:uppercase; margin-bottom:8px; display:flex; align-items:center; gap:6px; }
  .ob-pipe-col-head span { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
  .ob-pipe-card { background:#fff; border-radius:8px; padding:10px 12px; font-size:.8rem; margin-bottom:7px; border:1px solid var(--line); color:var(--ink-2); }
  .ob-pipe-card:last-child { margin-bottom:0; }
</style>

<div class="ob-wrap">
  <!-- Steps indicator -->
  <div class="ob-steps">
    <div class="ob-step <?= $current_step > 1 ? 'done' : ($current_step === 1 ? 'active' : '') ?>">
      <div class="ob-step-dot"><?= $current_step > 1 ? '✓' : '1' ?></div>
      <div class="ob-step-label">Sua empresa</div>
    </div>
    <div class="ob-step <?= $current_step > 2 ? 'done' : ($current_step === 2 ? 'active' : '') ?>">
      <div class="ob-step-dot"><?= $current_step > 2 ? '✓' : '2' ?></div>
      <div class="ob-step-label">Pipeline</div>
    </div>
    <div class="ob-step <?= $current_step === 3 ? 'active' : '' ?>">
      <div class="ob-step-dot">3</div>
      <div class="ob-step-label">Primeiro radar</div>
    </div>
  </div>

  <!-- ── STEP 1: Sua empresa ─────────────────────────────────────────────── -->
  <?php if ($current_step === 1): ?>
  <div class="ob-card">
    <div style="font-family:'Geist Mono',monospace;font-size:.66rem;color:var(--hermes);text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin-bottom:8px">// plano <?= htmlspecialchars($plan_name) ?> ativo</div>
    <h2>Bem-vindo ao HERMES.b2b 👋</h2>
    <p>Em 3 passos rápidos você configura sua conta e faz a primeira prospecção. Leva menos de 2 minutos.</p>

    <div class="ob-feature-list">
      <div class="ob-feature"><span class="ic">🎯</span><div><strong>Radar Leads</strong><span>Pesquise entre 70M empresas por vertical, cidade, porte e score de qualificação</span></div></div>
      <div class="ob-feature"><span class="ic">📋</span><div><strong>Pipeline</strong><span>Kanban visual para acompanhar seus contatos e fechar negócios</span></div></div>
      <div class="ob-feature"><span class="ic">✉️</span><div><strong>Mail Lab</strong><span>Envie e-mails diretamente para os leads encontrados no Radar</span></div></div>
      <div class="ob-feature"><span class="ic">📊</span><div><strong>Score HERMES</strong><span>Pontuação inteligente de qualificação baseada em sinais reais do CNPJ</span></div></div>
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
      <div class="ob-field">
        <label>Vertical de negócios que você mais prospecta</label>
        <select name="vertical">
          <option value="">— Selecione (opcional) —</option>
          <?php foreach ($verticais as $v): ?>
            <option value="<?= htmlspecialchars($v['vertical_id']) ?>"><?= htmlspecialchars($v['vertical_nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint">Vamos sugerir filtros relevantes no Radar com base nisso.</div>
      </div>
      <button type="submit" class="ob-btn">Continuar → <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></button>
    </form>
    <a href="onboarding.php?step=2" class="ob-skip">Pular esta etapa →</a>
  </div>

  <!-- ── STEP 2: Pipeline ────────────────────────────────────────────────── -->
  <?php elseif ($current_step === 2): ?>
  <div class="ob-card">
    <div style="font-family:'Geist Mono',monospace;font-size:.66rem;color:var(--hermes);text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin-bottom:8px">// step 2 de 3</div>
    <h2>Seu Pipeline está pronto 📋</h2>
    <p>Criamos colunas padrão para você começar a organizar seus contatos. Você pode personalizar nomes e cores a qualquer momento.</p>

    <div class="ob-pipe-preview">
      <div class="ob-pipe-col">
        <div class="ob-pipe-col-head"><span style="background:#94a3b8"></span>Leads</div>
        <div class="ob-pipe-card">🏢 Empresa ABC Ltda</div>
        <div class="ob-pipe-card">🏢 XYZ Comércio</div>
      </div>
      <div class="ob-pipe-col">
        <div class="ob-pipe-col-head"><span style="background:#f59e0b"></span>Contato Feito</div>
        <div class="ob-pipe-card">🏢 Padaria Sol</div>
      </div>
      <div class="ob-pipe-col">
        <div class="ob-pipe-col-head"><span style="background:#3b82f6"></span>Proposta</div>
        <div class="ob-pipe-card">🏢 Tech Startup</div>
      </div>
      <div class="ob-pipe-col">
        <div class="ob-pipe-col-head"><span style="background:#10b981"></span>Fechado ✓</div>
      </div>
    </div>

    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="2">
      <button type="submit" class="ob-btn">Criar Pipeline e continuar → <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></button>
    </form>
    <a href="onboarding.php?step=3" class="ob-skip">Pular →</a>
  </div>

  <!-- ── STEP 3: Primeiro Radar ──────────────────────────────────────────── -->
  <?php elseif ($current_step === 3): ?>
  <div class="ob-card">
    <div style="font-family:'Geist Mono',monospace;font-size:.66rem;color:var(--hermes);text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin-bottom:8px">// tudo certo!</div>
    <h2>Pronto para prospectar 🚀</h2>
    <p>Sua conta está configurada. Agora vá para o <strong>Radar Leads</strong> e faça sua primeira busca entre mais de 70 milhões de empresas brasileiras.</p>

    <div style="background:var(--bone);border-radius:12px;padding:20px 22px;margin:20px 0">
      <div style="font-family:'Geist Mono',monospace;font-size:.66rem;color:var(--mute);text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px">// dicas rápidas</div>
      <div style="display:flex;flex-direction:column;gap:10px">
        <div style="display:flex;align-items:center;gap:10px;font-size:.88rem">
          <span style="background:#dcfce7;color:#166534;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0">1</span>
          Filtre por <strong>vertical</strong> + <strong>UF</strong> para começar focado
        </div>
        <div style="display:flex;align-items:center;gap:10px;font-size:.88rem">
          <span style="background:#dcfce7;color:#166534;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0">2</span>
          Use o <strong>Score HERMES</strong> para priorizar — foque nos 🔥 Quentes primeiro
        </div>
        <div style="display:flex;align-items:center;gap:10px;font-size:.88rem">
          <span style="background:#dcfce7;color:#166534;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0">3</span>
          Clique <strong>+ Pipeline</strong> nos cards para salvar leads promissores
        </div>
        <div style="display:flex;align-items:center;gap:10px;font-size:.88rem">
          <span style="background:#dcfce7;color:#166534;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0">4</span>
          Baixe a <strong>lista CSV</strong> para enviar por e-mail em massa
        </div>
      </div>
    </div>

    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="3">
      <button type="submit" class="ob-btn" style="font-size:1.05rem;padding:16px">
        🎯 Ir para o Radar Leads
      </button>
    </form>
  </div>
  <?php endif; ?>

</div>
<?php
});
