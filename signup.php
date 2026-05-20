<?php
/**
 * Newton IA — Signup self-service
 * Cria user + tenant + trial ou redireciona pro checkout Asaas.
 *
 * URL params:
 *   ?plan=trial|starter|pro|business  (default: pro)
 *   ?cycle=monthly|yearly              (default: monthly)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/plans.php';
require_once __DIR__ . '/core/emails.php';

if (auth_user_id()) {
    header('Location: /app/');
    exit;
}

plans_ensure_hermes_schema();

$selected_plan  = $_GET['plan']  ?? 'trial';
$selected_cycle = $_GET['cycle'] ?? 'monthly';
if (!in_array($selected_plan,  ['trial','starter','pro','business'], true)) $selected_plan  = 'trial';
if (!in_array($selected_cycle, ['monthly','yearly'],                true)) $selected_cycle = 'monthly';

$plans = newton_plans_list();

$error       = '';
$success_url = null;

// ── POST: processar cadastro ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name']     ?? '');
    $company   = trim($_POST['company']  ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $password  = $_POST['password'] ?? '';
    $tier_code = $_POST['plan']     ?? 'trial';
    $cycle     = $_POST['cycle']    ?? 'monthly';
    $accept    = !empty($_POST['accept_terms']);

    if (!$name)            $error = 'Informe seu nome completo.';
    elseif (!$company)     $error = 'Informe o nome da empresa.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'E-mail inválido.';
    elseif (strlen($password) < 8) $error = 'Senha precisa ter no mínimo 8 caracteres.';
    elseif (!$accept)      $error = 'Você precisa aceitar os termos.';
    elseif (!in_array($tier_code, ['trial','starter','pro','business'], true)) $error = 'Plano inválido.';

    if (!$error) {
        $exists = (int) db_val('SELECT COUNT(*) FROM users WHERE email = ?', [$email]);
        if ($exists) $error = 'Este e-mail já tem cadastro. Faça login ou recupere a senha.';
    }

    if (!$error) {
        $plan = newton_plan_by_code($tier_code);
        if (!$plan) { $error = 'Plano não encontrado.'; }

        try {
            db()->beginTransaction();

            $userId = db_insert('users', [
                'name'          => $name,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'is_super_admin'=> 0,
            ]);

            $slug_base = preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $company)));
            $slug_base = trim($slug_base, '-') ?: 'tenant';
            $slug = $slug_base;
            $slug_n = 1;
            while ((int) db_val('SELECT COUNT(*) FROM tenants WHERE slug = ?', [$slug]) > 0) {
                $slug = $slug_base . '-' . (++$slug_n);
            }

            $tenantId = db_insert('tenants', [
                'name'              => $company,
                'slug'              => $slug,
                'brand_name'        => $company,
                'brand_color'       => '#0ea5e9',
                'plan_id'           => (int) $plan['id'],
                'status'            => 'pending',
                'trial_started_at'  => $tier_code === 'trial' ? date('Y-m-d H:i:s') : null,
            ]);

            db_q('INSERT INTO tenant_users (tenant_id, user_id, role) VALUES (?, ?, ?)',
                 [$tenantId, $userId, 'owner']);

            audit_log('signup', 'tenant', $tenantId, [
                'email' => $email, 'plan' => $tier_code, 'cycle' => $cycle,
            ]);

            db()->commit();

            try {
                [$subj, $body] = email_boas_vindas($name, $company, $plan['name'] ?? 'Trial', $tier_code === 'trial');
                hermes_mail($email, $subj, $body);
            } catch (\Throwable $e) {
                error_log('[signup] email boas-vindas: ' . $e->getMessage());
            }

            auth_start_session();
            $_SESSION['user_id']        = (int) $userId;
            $_SESSION['user_email']     = $email;
            $_SESSION['user_name']      = $name;
            $_SESSION['is_super_admin'] = false;
            $_SESSION['user_tenants']   = [[
                'id' => (int)$tenantId, 'slug' => $slug, 'name' => $company,
                'brand_name' => $company, 'brand_color' => '#0ea5e9', 'role' => 'owner',
            ]];
            $_SESSION['tenant_id'] = (int) $tenantId;

            if ($tier_code !== 'trial' && (int)$plan['price_cents'] > 0) {
                require_once __DIR__ . '/core/billing.php';
                $asaas_cycle = $cycle === 'yearly' ? 'YEARLY' : 'MONTHLY';
                $r = billing_create_subscription((int)$tenantId, (int)$plan['id'], 'PIX', $asaas_cycle);
                $success_url = $r['ok']
                    ? '/app/billing.php?signup=ok'
                    : '/app/billing.php?signup_pending=1&err=' . urlencode($r['error'] ?? 'unknown');
            } else {
                db_q("UPDATE tenants SET status = 'trial' WHERE id = ?", [$tenantId]);
                $success_url = '/app/?welcome=1';
            }

        } catch (\Throwable $e) {
            db()->rollBack();
            $error = 'Erro ao criar conta: ' . $e->getMessage();
        }
    }

    if ($success_url) {
        header('Location: ' . $success_url);
        exit;
    }
}

$selected        = newton_plan_by_code($selected_plan);
$displayed_plans = array_filter($plans, fn($p) => in_array($p['tier_code'], ['starter','pro','business'], true));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>Comece grátis — Newton IA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --newton:#0ea5e9; --newton-2:#0284c7; --indigo:#3d3dff; --coral:#be123c;
    --ink:#0a0a0f; --fg-2:#4a4a55; --mute:#8b8a93; --line:#e4e4dd; --bg:#fafaf7;
    --font-display:'Geist',system-ui,sans-serif; --font-mono:'Geist Mono','SF Mono',monospace;
    --radius:10px; --radius-sm:6px;
  }
  body { font-family: var(--font-display); background: var(--bg); color: var(--ink); min-height: 100vh; padding: 20px; -webkit-font-smoothing: antialiased; letter-spacing: -0.01em; }

  .wrap { max-width: 1060px; margin: 0 auto; padding: 20px 0; }

  .top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; }
  .brand { display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit; }
  .brand-icon { width: 38px; height: 38px; border-radius: 10px; background: var(--newton); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 1px 6px rgba(14,165,233,.25); }
  .brand-name { font-family: var(--font-mono); font-weight: 700; font-size: 1rem; letter-spacing: -0.01em; }
  .brand-name .ia { color: var(--newton); }
  .top .login-link { font-size: .88rem; color: var(--mute); text-decoration: none; }
  .top .login-link a { color: var(--newton); font-weight: 600; text-decoration: none; }
  .top .login-link a:hover { color: var(--newton-2); }

  .grid { display: grid; grid-template-columns: 1fr 360px; gap: 24px; align-items: start; }
  @media (max-width: 860px) { .grid { grid-template-columns: 1fr; } }

  .form-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 28px 30px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
  .form-card h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 6px; letter-spacing: -0.025em; }
  .form-card .sub { color: var(--mute); font-size: .9rem; margin-bottom: 22px; line-height: 1.6; }
  .form-card .sub strong { color: var(--ink); }

  .field { margin-bottom: 14px; }
  .field label { display: block; font-family: var(--font-mono); font-size: .62rem; color: var(--mute); text-transform: uppercase; letter-spacing: .08em; font-weight: 600; margin-bottom: 6px; }
  .field input { width: 100%; padding: 11px 13px; border: 1px solid var(--line); border-radius: var(--radius-sm); font-size: .92rem; font-family: inherit; background: #fff; color: var(--ink); transition: border-color .15s, box-shadow .15s; }
  .field input:focus { outline: none; border-color: var(--newton); box-shadow: 0 0 0 3px rgba(14,165,233,.12); }
  .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  @media (max-width: 540px) { .field-row { grid-template-columns: 1fr; } }
  .field small { color: var(--mute); font-size: .74rem; margin-top: 4px; display: block; }

  .terms { display: flex; gap: 8px; align-items: flex-start; margin: 18px 0 22px; font-size: .82rem; color: var(--fg-2); }
  .terms input[type=checkbox] { margin-top: 3px; accent-color: var(--newton); flex-shrink: 0; }
  .terms a { color: var(--newton); text-decoration: none; font-weight: 500; }

  .submit { width: 100%; padding: 14px; background: var(--ink); color: #fff; border: none; border-radius: var(--radius); font-size: .95rem; font-weight: 600; cursor: pointer; font-family: var(--font-mono); letter-spacing: .02em; transition: background .18s; }
  .submit:hover { background: var(--newton); }

  .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 11px 14px; border-radius: var(--radius-sm); font-size: .85rem; margin-bottom: 18px; }

  /* Plan picker */
  .plan-picker { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,.06); position: sticky; top: 20px; }
  .plan-picker h3 { font-size: .95rem; font-weight: 600; margin-bottom: 4px; letter-spacing: -0.01em; }
  .plan-picker .sub { color: var(--mute); font-size: .8rem; margin-bottom: 14px; }

  .plan-opt { display: flex; align-items: center; gap: 10px; padding: 12px; border: 2px solid var(--line); border-radius: var(--radius); cursor: pointer; margin-bottom: 8px; transition: all .15s; position: relative; }
  .plan-opt:hover { border-color: #7dd3fc; background: rgba(14,165,233,.03); }
  .plan-opt.selected { border-color: var(--newton); background: rgba(14,165,233,.06); }
  .plan-opt.selected::after { content:'✓'; position: absolute; right: 12px; top: 14px; color: var(--newton); font-weight: 700; }
  .plan-opt input { position: absolute; opacity: 0; pointer-events: none; }
  .plan-opt .pl-name { font-weight: 600; font-size: .92rem; }
  .plan-opt .pl-popular { font-family: var(--font-mono); font-size: .54rem; background: var(--newton); color: #fff; padding: 2px 6px; border-radius: 4px; margin-left: 6px; letter-spacing: .06em; text-transform: uppercase; }
  .plan-opt .pl-meta { font-size: .72rem; color: var(--mute); margin-top: 3px; }
  .plan-opt .pl-price { font-family: var(--font-mono); font-weight: 700; font-size: .9rem; margin-left: auto; padding-right: 20px; color: var(--ink); white-space: nowrap; }
  .plan-opt.trial .pl-price { color: var(--newton); }

  .cycle-row { display: flex; gap: 6px; margin: 14px 0 12px; padding: 4px; background: var(--bg); border-radius: 8px; }
  .cycle-row label { flex: 1; cursor: pointer; padding: 7px 10px; text-align: center; font-size: .8rem; font-weight: 500; color: var(--mute); border-radius: 6px; }
  .cycle-row input { position: absolute; opacity: 0; pointer-events: none; }
  .cycle-row input:checked + .cycle-label { background: #fff; color: var(--ink); box-shadow: 0 1px 3px rgba(0,0,0,.08); font-weight: 600; display: block; }
  .cycle-row .cycle-label { display: block; }
  .save-badge { font-family: var(--font-mono); font-size: .52rem; background: var(--newton); color: #fff; padding: 1px 5px; border-radius: 3px; margin-left: 4px; }

  .picker-help { background: var(--bg); padding: 10px 12px; border-radius: 8px; font-size: .78rem; color: var(--fg-2); margin-top: 14px; line-height: 1.5; }
  .picker-help strong { color: var(--newton); }

  .trust { display: flex; gap: 12px; margin-top: 14px; flex-wrap: wrap; font-size: .75rem; color: var(--mute); }
  .trust .item { display: flex; align-items: center; gap: 5px; }
</style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <a class="brand" href="https://newtonia.digital">
      <div class="brand-icon">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="2" fill="currentColor" stroke="none"/>
          <ellipse cx="12" cy="12" rx="9" ry="3.5"/>
          <ellipse cx="12" cy="12" rx="9" ry="3.5" transform="rotate(60 12 12)"/>
          <ellipse cx="12" cy="12" rx="9" ry="3.5" transform="rotate(120 12 12)"/>
        </svg>
      </div>
      <div class="brand-name">Newton <span class="ia">IA</span></div>
    </a>
    <div class="login-link">Já tem conta? <a href="/login.php">Entrar</a></div>
  </div>

  <div class="grid">

    <!-- COL 1: Formulário -->
    <div class="form-card">
      <h1>Comece em 2 minutos</h1>
      <p class="sub">Crie sua conta e lance seus primeiros agentes de IA no WhatsApp. <strong>7 dias grátis, sem cartão</strong> no Trial.</p>

      <?php if ($error): ?>
        <div class="error">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" id="signup-form">
        <div class="field-row">
          <div class="field">
            <label>Seu nome completo</label>
            <input type="text" name="name" required autofocus value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Nome da empresa</label>
            <input type="text" name="company" required value="<?= htmlspecialchars($_POST['company'] ?? '') ?>">
          </div>
        </div>

        <div class="field">
          <label>E-mail</label>
          <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="seu@email.com">
          <small>Será usado como login.</small>
        </div>

        <div class="field">
          <label>Senha (mín. 8 caracteres)</label>
          <input type="password" name="password" required minlength="8" placeholder="••••••••">
        </div>

        <input type="hidden" name="plan"  id="hf-plan"  value="<?= htmlspecialchars($selected_plan) ?>">
        <input type="hidden" name="cycle" id="hf-cycle" value="<?= htmlspecialchars($selected_cycle) ?>">

        <label class="terms">
          <input type="checkbox" name="accept_terms" required>
          <span>Concordo com os <a href="/terms.php" target="_blank">Termos de Uso</a> e <a href="/privacy.php" target="_blank">Política de Privacidade</a> do Newton IA.</span>
        </label>

        <button type="submit" class="submit" id="submit-btn">
          <span id="submit-label">Criar conta e começar</span>
        </button>

        <div class="trust">
          <span class="item">🔒 SSL · dados criptografados</span>
          <span class="item">💳 Asaas · pagamento seguro</span>
          <span class="item">✓ Cancele quando quiser</span>
        </div>
      </form>
    </div>

    <!-- COL 2: Plan picker -->
    <div class="plan-picker">
      <h3>Escolha seu plano</h3>
      <p class="sub">Pode trocar a qualquer momento.</p>

      <?php $isTrial = $selected_plan === 'trial'; ?>
      <label class="plan-opt trial <?= $isTrial ? 'selected' : '' ?>" data-plan="trial">
        <input type="radio" name="plan_radio" value="trial" <?= $isTrial ? 'checked' : '' ?>>
        <div>
          <div class="pl-name">🆓 Trial</div>
          <div class="pl-meta">7 dias · 1 agente · sem cartão</div>
        </div>
        <div class="pl-price">Grátis</div>
      </label>

      <div class="cycle-row" id="cycle-row" style="<?= $isTrial ? 'display:none' : '' ?>">
        <label>
          <input type="radio" name="cycle_radio" value="monthly" <?= $selected_cycle === 'monthly' ? 'checked' : '' ?>>
          <span class="cycle-label">Mensal</span>
        </label>
        <label>
          <input type="radio" name="cycle_radio" value="yearly" <?= $selected_cycle === 'yearly' ? 'checked' : '' ?>>
          <span class="cycle-label">Anual <span class="save-badge">−16%</span></span>
        </label>
      </div>

      <?php foreach ($displayed_plans as $p):
        $isSelected = $selected_plan === $p['tier_code'];
        $isPopular  = !empty($p['popular']);
        $agents     = (int)$p['limit_cnpj_monthly'];
        $agentsLabel = $agents >= 999 ? 'Ilimitado' : $agents . ' agente' . ($agents > 1 ? 's' : '');
        $users      = (int)$p['users_limit'];
      ?>
      <label class="plan-opt <?= $isSelected ? 'selected' : '' ?>" data-plan="<?= htmlspecialchars($p['tier_code']) ?>">
        <input type="radio" name="plan_radio" value="<?= htmlspecialchars($p['tier_code']) ?>" <?= $isSelected ? 'checked' : '' ?>>
        <div style="flex:1">
          <div class="pl-name">
            <?= htmlspecialchars($p['name']) ?>
            <?php if ($isPopular): ?><span class="pl-popular">Popular</span><?php endif; ?>
          </div>
          <div class="pl-meta"><?= $agentsLabel ?> · <?= $users ?> user<?= $users > 1 ? 's' : '' ?></div>
        </div>
        <div class="pl-price">
          <span class="price-monthly" data-cents="<?= (int)$p['price_cents'] ?>">R$ <?= number_format($p['price_cents']/100, 0, ',', '.') ?></span>
          <span class="price-yearly"  data-cents="<?= (int)$p['annual_price_cents'] ?>" style="display:none">R$ <?= number_format($p['annual_price_cents']/100, 0, ',', '.') ?></span>
        </div>
      </label>
      <?php endforeach; ?>

      <div class="picker-help" id="picker-help">
        <strong>Trial:</strong> 7 dias grátis com 1 agente, sem precisar de cartão. Cancele quando quiser.
      </div>
    </div>

  </div>
</div>

<script>
const planOpts    = document.querySelectorAll('.plan-opt');
const cycleInputs = document.querySelectorAll('input[name="cycle_radio"]');
const cycleRow    = document.getElementById('cycle-row');
const hfPlan      = document.getElementById('hf-plan');
const hfCycle     = document.getElementById('hf-cycle');
const submitLabel = document.getElementById('submit-label');
const help        = document.getElementById('picker-help');

function updateUI() {
  const plan  = hfPlan.value;
  const cycle = hfCycle.value;
  cycleRow.style.display = plan === 'trial' ? 'none' : 'flex';
  document.querySelectorAll('.price-monthly').forEach(el => el.style.display = cycle === 'monthly' ? '' : 'none');
  document.querySelectorAll('.price-yearly').forEach(el  => el.style.display = cycle === 'yearly'  ? '' : 'none');
  if (plan === 'trial') {
    submitLabel.textContent = 'Começar grátis (7 dias)';
    help.innerHTML = '<strong>Trial:</strong> 7 dias grátis com 1 agente, sem precisar de cartão. Cancele quando quiser.';
  } else {
    submitLabel.textContent = 'Criar conta e continuar para pagamento';
    help.innerHTML = '<strong>Plano pago:</strong> Após confirmar, você é redirecionado ao Asaas para escolher Cartão ou PIX.';
  }
}

planOpts.forEach(opt => {
  opt.addEventListener('click', () => {
    const plan = opt.dataset.plan;
    hfPlan.value = plan;
    planOpts.forEach(o => o.classList.toggle('selected', o.dataset.plan === plan));
    document.querySelector(`input[name=plan_radio][value="${plan}"]`).checked = true;
    updateUI();
  });
});

cycleInputs.forEach(inp => {
  inp.addEventListener('change', () => {
    hfCycle.value = inp.value;
    updateUI();
  });
});

updateUI();
</script>
<?php require_once __DIR__ . '/core/lgpd_banner.php'; ?>
</body>
</html>
