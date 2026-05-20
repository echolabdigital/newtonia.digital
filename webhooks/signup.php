<?php
/**
 * Newton IA — Signup self-service
 * Fluxo público de cadastro: cria user + tenant + tenant_users e:
 *   - se TRIAL: auto-login e redireciona pra /app/
 *   - se plano pago: cria Asaas customer + subscription e redireciona pro checkout
 *
 * URL params (vindos da LP):
 *   ?plan=trial|starter|pro|business  (default: pro)
 *   ?cycle=monthly|yearly              (default: monthly)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/plans.php';
require_once __DIR__ . '/core/emails.php';

// Já logado → manda pra app
if (auth_user_id()) {
    header('Location: /app/');
    exit;
}

plans_ensure_hermes_schema();

$selected_plan = $_GET['plan'] ?? 'pro';
$selected_cycle = $_GET['cycle'] ?? 'monthly';
if (!in_array($selected_plan, ['trial','starter','pro','business'], true)) $selected_plan = 'pro';
if (!in_array($selected_cycle, ['monthly','yearly'], true)) $selected_cycle = 'monthly';

$plans = hermes_plans_list(); // todos (inclui trial)

$error = '';
$success_url = null;

// ── POST: processar cadastro ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? '');
    $company     = trim($_POST['company'] ?? '');
    $email       = strtolower(trim($_POST['email'] ?? ''));
    $password    = $_POST['password'] ?? '';
    $cnpj        = preg_replace('/\D/', '', $_POST['cnpj'] ?? '');
    $tier_code   = $_POST['plan'] ?? 'pro';
    $cycle       = $_POST['cycle'] ?? 'monthly';
    $accept      = !empty($_POST['accept_terms']);

    // Validações básicas
    if (!$name)            $error = 'Informe seu nome completo.';
    elseif (!$company)     $error = 'Informe o nome da empresa.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'E-mail inválido.';
    elseif (strlen($password) < 8) $error = 'Senha precisa ter no mínimo 8 caracteres.';
    elseif (!$accept)      $error = 'Você precisa aceitar os termos.';
    elseif (!in_array($tier_code, ['trial','starter','pro','business'], true)) $error = 'Plano inválido.';

    // E-mail já existe?
    if (!$error) {
        $exists = (int) db_val('SELECT COUNT(*) FROM users WHERE email = ?', [$email]);
        if ($exists) $error = 'Este e-mail já tem cadastro. Faça login ou recupere a senha.';
    }

    if (!$error) {
        $plan = hermes_plan_by_code($tier_code);
        if (!$plan) { $error = 'Plano não encontrado.'; }

        try {
            db()->beginTransaction();

            // 1) Cria user
            $userId = db_insert('users', [
                'name'          => $name,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'is_super_admin'=> 0,
            ]);

            // 2) Cria tenant com slug único
            $slug_base = preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $company)));
            $slug_base = trim($slug_base, '-');
            $slug = $slug_base;
            $slug_n = 1;
            while ((int) db_val('SELECT COUNT(*) FROM tenants WHERE slug = ?', [$slug]) > 0) {
                $slug = $slug_base . '-' . (++$slug_n);
            }

            // status: trial = pending (3 dias), pago = pending (até webhook confirmar)
            $tenant_status = $tier_code === 'trial' ? 'pending' : 'pending';

            $tenantId = db_insert('tenants', [
                'name'        => $company,
                'slug'        => $slug,
                'brand_name'  => $company,
                'brand_color' => '#0ea5e9',
                'plan_id'     => (int) $plan['id'],
                'status'      => $tenant_status,
            ]);

            // 3) Vincula user → tenant como owner
            db_q('INSERT INTO tenant_users (tenant_id, user_id, role) VALUES (?, ?, ?)',
                 [$tenantId, $userId, 'owner']);

            // Audit
            audit_log('signup', 'tenant', $tenantId, [
                'email' => $email,
                'plan' => $tier_code,
                'cycle' => $cycle,
            ]);

            // Salva CPF ou CNPJ no tenant (tenta — ignora se coluna não existir)
            if ($cnpj && (strlen($cnpj) === 11 || strlen($cnpj) === 14)) {
                try { db_q('UPDATE tenants SET cnpj = ? WHERE id = ?', [$cnpj, $tenantId]); } catch (\Throwable $e) {}
            }

            db()->commit();

            // E-mail de boas-vindas (após commit — não bloqueia se falhar)
            try {
                require_once __DIR__ . '/core/emails.php';
                [$subj, $body] = email_boas_vindas($name, $company, $plan['name'] ?? 'Trial', $tier_code === 'trial');
                hermes_mail($email, $subj, $body);
            } catch (\Throwable $e) {
                error_log('[signup] Falha ao enviar boas-vindas para ' . $email . ': ' . $e->getMessage());
            }

            // 4) Auto-login
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

            // 5) Se plano pago, cria Asaas customer + subscription
            if ($tier_code !== 'trial' && (int)$plan['price_cents'] > 0) {
                require_once __DIR__ . '/core/billing.php';
                $billing_type = 'PIX'; // default — usuário pode trocar pra cartão na página de billing
                $asaas_cycle = $cycle === 'yearly' ? 'YEARLY' : 'MONTHLY';
                $r = billing_create_subscription((int)$tenantId, (int)$plan['id'], $billing_type, $asaas_cycle);

                if (!$r['ok']) {
                    // Não bloqueia o signup, mas avisa que tem que ir pro billing manualmente
                    $success_url = '/app/billing.php?signup_pending=1&err=' . urlencode($r['error'] ?? 'unknown');
                } else {
                    // Asaas retorna invoice URL na 1ª subscription? Não — subscription gera payment, e payment tem invoiceUrl.
                    // Por enquanto manda pra billing.php que mostra status + link da fatura
                    $success_url = '/app/billing.php?signup=ok';
                }
            } else {
                // Trial: vai direto pra app
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

// Plano selecionado pra render
$selected = hermes_plan_by_code($selected_plan);
$displayed_plans = array_filter($plans, fn($p) => in_array($p['tier_code'], ['starter','pro','business'], true));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>Comece grátis — Newton IA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --newton:#0ea5e9; --indigo:#3d3dff; --coral:#be123c;
    --ink:#18181b; --mute:#8b8a93; --line:#e7e5e0; --paper:#fafaf7; --bone:#f6f4ef;
  }
  body { font-family: 'Geist', system-ui, sans-serif; background: var(--bone); color: var(--ink); min-height: 100vh; padding: 20px; -webkit-font-smoothing: antialiased; letter-spacing: -0.01em; }

  .wrap { max-width: 1080px; margin: 0 auto; padding: 20px 0; }

  /* Header */
  .top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; }
  .brand { display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit; }
  .brand-icon { width: 38px; height: 38px; border-radius: 9px; background: var(--hermes); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 1px 3px rgba(16,185,129,.25); }
  .brand-text { font-family: 'Geist Mono', monospace; font-weight: 700; font-size: 1rem; line-height: 1.1; }
  .brand-text .b2b { color: var(--hermes); font-size: .76em; }
  .top .login-link { font-size: .88rem; color: var(--mute); text-decoration: none; }
  .top .login-link a { color: var(--hermes); font-weight: 600; }

  /* Layout 2 colunas */
  .grid { display: grid; grid-template-columns: 1fr 380px; gap: 28px; align-items: start; }
  @media (max-width: 880px) { .grid { grid-template-columns: 1fr; } }

  /* Form */
  .form-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 28px 30px; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
  .form-card h1 { font-size: 1.55rem; font-weight: 700; margin-bottom: 6px; letter-spacing: -0.02em; }
  .form-card .sub { color: var(--mute); font-size: .9rem; margin-bottom: 22px; }
  .form-card .sub strong { color: var(--ink); }

  .field { margin-bottom: 14px; }
  .field label { display: block; font-family: 'Geist Mono', monospace; font-size: .64rem; color: var(--mute); text-transform: uppercase; letter-spacing: .06em; font-weight: 600; margin-bottom: 6px; }
  .field input { width: 100%; padding: 11px 13px; border: 1px solid var(--line); border-radius: 8px; font-size: .92rem; font-family: inherit; background: #fff; color: var(--ink); transition: all .15s; }
  .field input:focus { outline: none; border-color: var(--hermes); box-shadow: 0 0 0 3px rgba(16,185,129,.1); }
  .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  @media (max-width: 540px) { .field-row { grid-template-columns: 1fr; } }
  .field small { color: var(--mute); font-size: .74rem; margin-top: 4px; display: block; }

  .terms { display: flex; gap: 8px; align-items: flex-start; margin: 18px 0 22px; font-size: .82rem; color: var(--ink-2, #3a3a40); }
  .terms input[type=checkbox] { margin-top: 3px; accent-color: var(--hermes); flex-shrink: 0; }
  .terms a { color: var(--hermes); text-decoration: none; font-weight: 500; }

  .submit { width: 100%; padding: 14px; background: var(--hermes); color: #fff; border: none; border-radius: 10px; font-size: 1rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: background .15s; }
  .submit:hover { background: #0ea371; }

  .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 11px 14px; border-radius: 8px; font-size: .85rem; margin-bottom: 18px; }

  /* Plan picker (lado direito) */
  .plan-picker { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 22px; box-shadow: 0 1px 4px rgba(0,0,0,.04); position: sticky; top: 20px; }
  .plan-picker h3 { font-size: .95rem; font-weight: 700; margin-bottom: 4px; }
  .plan-picker .sub { color: var(--mute); font-size: .8rem; margin-bottom: 14px; }

  .plan-opt { display: flex; align-items: center; gap: 10px; padding: 12px; border: 2px solid var(--line); border-radius: 10px; cursor: pointer; margin-bottom: 8px; transition: all .15s; position: relative; }
  .plan-opt:hover { border-color: #a7f3d0; background: rgba(16,185,129,.03); }
  .plan-opt.selected { border-color: var(--hermes); background: rgba(16,185,129,.06); }
  .plan-opt.selected::after { content:'✓'; position: absolute; right: 12px; top: 14px; color: var(--hermes); font-weight: 700; }
  .plan-opt input { position: absolute; opacity: 0; pointer-events: none; }
  .plan-opt .pl-name { font-weight: 600; font-size: .92rem; }
  .plan-opt .pl-popular { font-family: 'Geist Mono', monospace; font-size: .54rem; background: var(--hermes); color: #fff; padding: 1px 6px; border-radius: 3px; margin-left: 6px; letter-spacing: .06em; }
  .plan-opt .pl-meta { font-size: .72rem; color: var(--mute); margin-top: 2px; }
  .plan-opt .pl-price { font-family: 'Geist Mono', monospace; font-weight: 700; font-size: .9rem; margin-left: auto; padding-right: 18px; }
  .plan-opt.trial .pl-price { color: var(--hermes); }

  .cycle-row { display: flex; gap: 6px; margin: 14px 0 12px; padding: 4px; background: var(--bone); border-radius: 8px; }
  .cycle-row label { flex: 1; cursor: pointer; padding: 7px 10px; text-align: center; font-size: .8rem; font-weight: 500; color: var(--mute); border-radius: 6px; font-family: inherit; }
  .cycle-row input { position: absolute; opacity: 0; pointer-events: none; }
  .cycle-row input:checked + .cycle-label { background: #fff; color: var(--ink); box-shadow: 0 1px 3px rgba(0,0,0,.06); font-weight: 600; }
  .cycle-row .save-badge { font-family: 'Geist Mono', monospace; font-size: .54rem; background: var(--hermes); color: #fff; padding: 1px 5px; border-radius: 3px; margin-left: 4px; }

  .picker-help { background: var(--bone); padding: 10px 12px; border-radius: 8px; font-size: .78rem; color: var(--ink-2, #3a3a40); margin-top: 14px; line-height: 1.5; }
  .picker-help strong { color: var(--hermes); }

  /* Trust */
  .trust { display: flex; gap: 14px; margin-top: 14px; flex-wrap: wrap; font-size: .76rem; color: var(--mute); }
  .trust .item { display: flex; align-items: center; gap: 5px; }
</style>
</head>
<body>
<div class="wrap">

  <!-- Header -->
  <div class="top">
    <a class="brand" href="/">
      <div class="brand-icon">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
          <path d="M5 7l5 5-5 5"/><line x1="13" y1="17" x2="20" y2="17"/>
        </svg>
      </div>
      <div class="brand-text">HERMES<span class="b2b">.b2b</span></div>
    </a>
    <div class="login-link">Já tem conta? <a href="/login.php">Entrar</a></div>
  </div>

  <div class="grid">

    <!-- COL 1: Formulário -->
    <div class="form-card">
      <h1>Comece em 3 minutos</h1>
      <p class="sub">Crie sua conta e comece a prospectar empresas qualificadas hoje. <strong>3 dias grátis sem cartão</strong> no plano Trial.</p>

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
          <label>E-mail corporativo</label>
          <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          <small>Esse e-mail será seu login.</small>
        </div>

        <div class="field-row">
          <div class="field">
            <label>Senha (mín. 8 caracteres)</label>
            <input type="password" name="password" required minlength="8">
          </div>
          <div class="field">
            <label>CPF ou CNPJ <small style="display:inline">(opcional)</small></label>
            <input type="text" name="cnpj" maxlength="18" placeholder="000.000.000-00 ou 00.000.000/0000-00" value="<?= htmlspecialchars($_POST['cnpj'] ?? '') ?>">
          </div>
        </div>

        <input type="hidden" name="plan"  id="hf-plan"  value="<?= htmlspecialchars($selected_plan) ?>">
        <input type="hidden" name="cycle" id="hf-cycle" value="<?= htmlspecialchars($selected_cycle) ?>">

        <label class="terms">
          <input type="checkbox" name="accept_terms" required>
          <span>Concordo com os <a href="#" target="_blank">Termos de Uso</a> e <a href="#" target="_blank">Política de Privacidade</a> da Newton IA.</span>
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

      <!-- Trial -->
      <?php $isTrial = $selected_plan === 'trial'; ?>
      <label class="plan-opt trial <?= $isTrial ? 'selected' : '' ?>" data-plan="trial">
        <input type="radio" name="plan_radio" value="trial" <?= $isTrial ? 'checked' : '' ?>>
        <div>
          <div class="pl-name">🆓 Trial</div>
          <div class="pl-meta">3 dias · 30 extrações · sem cartão</div>
        </div>
        <div class="pl-price">Grátis</div>
      </label>

      <!-- Cycle (mensal/anual) — só visível quando não é trial -->
      <div class="cycle-row" id="cycle-row" style="<?= $isTrial ? 'display:none' : '' ?>">
        <label>
          <input type="radio" name="cycle_radio" value="monthly" <?= $selected_cycle === 'monthly' ? 'checked' : '' ?>>
          <span class="cycle-label" style="display:block">Mensal</span>
        </label>
        <label>
          <input type="radio" name="cycle_radio" value="yearly" <?= $selected_cycle === 'yearly' ? 'checked' : '' ?>>
          <span class="cycle-label" style="display:block">Anual <span class="save-badge">−16%</span></span>
        </label>
      </div>

      <!-- Planos pagos -->
      <?php foreach ($displayed_plans as $p):
        $isSelected = $selected_plan === $p['tier_code'];
        $isPopular  = !empty($p['popular']);
      ?>
      <label class="plan-opt <?= $isSelected ? 'selected' : '' ?>" data-plan="<?= htmlspecialchars($p['tier_code']) ?>">
        <input type="radio" name="plan_radio" value="<?= htmlspecialchars($p['tier_code']) ?>" <?= $isSelected ? 'checked' : '' ?>>
        <div style="flex:1">
          <div class="pl-name">
            <?= htmlspecialchars($p['name']) ?>
            <?php if ($isPopular): ?><span class="pl-popular">Popular</span><?php endif; ?>
          </div>
          <div class="pl-meta"><?= number_format((int)$p['limit_cnpj_monthly'], 0, ',', '.') ?> extrações · <?= (int)$p['users_limit'] ?> user<?= (int)$p['users_limit'] > 1 ? 's' : '' ?></div>
        </div>
        <div class="pl-price">
          <span class="price-monthly" data-cents="<?= (int)$p['price_cents'] ?>">R$ <?= number_format($p['price_cents']/100, 0, ',', '.') ?></span>
          <span class="price-yearly"  data-cents="<?= (int)$p['annual_price_cents'] ?>" style="display:none">R$ <?= number_format($p['annual_price_cents']/100, 0, ',', '.') ?></span>
        </div>
      </label>
      <?php endforeach; ?>

      <div class="picker-help" id="picker-help">
        <strong>Plano pago?</strong> Após confirmar, você é redirecionado pro Asaas pra escolher Cartão ou PIX.
      </div>
    </div>

  </div>
</div>

<script>
const planOpts = document.querySelectorAll('.plan-opt');
const cycleInputs = document.querySelectorAll('input[name="cycle_radio"]');
const cycleRow = document.getElementById('cycle-row');
const hfPlan = document.getElementById('hf-plan');
const hfCycle = document.getElementById('hf-cycle');
const submitLabel = document.getElementById('submit-label');
const help = document.getElementById('picker-help');

function updateUI() {
  const plan = hfPlan.value;
  const cycle = hfCycle.value;
  // Esconde/mostra cycle row
  cycleRow.style.display = plan === 'trial' ? 'none' : 'flex';
  // Atualiza preços visíveis
  document.querySelectorAll('.price-monthly').forEach(el => el.style.display = cycle === 'monthly' ? '' : 'none');
  document.querySelectorAll('.price-yearly').forEach(el => el.style.display = cycle === 'yearly' ? '' : 'none');
  // Atualiza label do botão
  if (plan === 'trial') {
    submitLabel.textContent = 'Começar grátis (3 dias)';
    help.innerHTML = '<strong>Trial:</strong> 3 dias grátis com 30 extrações, sem cartão. Cancele quando quiser.';
  } else {
    submitLabel.textContent = 'Criar conta e pagar';
    help.innerHTML = '<strong>Plano pago:</strong> Após confirmar, você é redirecionado pro Asaas pra escolher Cartão ou PIX.';
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

// CPF / CNPJ mask dinâmica (≤11 dígitos vira CPF, >11 vira CNPJ)
const cnpjInput = document.querySelector('input[name="cnpj"]');
if (cnpjInput) {
  cnpjInput.addEventListener('input', e => {
    let v = e.target.value.replace(/\D/g, '').slice(0, 14);
    if (v.length > 11) {
      v = v.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})/, '$1.$2.$3/$4-$5');
    } else if (v.length > 9) {
      v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{0,2})/, '$1.$2.$3-$4');
    } else if (v.length > 6) {
      v = v.replace(/(\d{3})(\d{3})(\d{0,3})/, '$1.$2.$3');
    } else if (v.length > 3) {
      v = v.replace(/(\d{3})(\d{0,3})/, '$1.$2');
    }
    e.target.value = v;
  });
}

updateUI();
</script>
<?php require_once __DIR__ . '/core/lgpd_banner.php'; ?>
</body>
</html>
