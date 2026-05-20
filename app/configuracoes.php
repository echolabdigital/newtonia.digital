<?php
/**
 * Newton IA — Configurações do usuário
 * Preferências de perfil, notificações, módulos e privacidade.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../core/user_prefs.php';
require_once __DIR__ . '/../core/billing.php';

$tenant  = require_tenant();
$tid     = (int) $tenant['id'];
$uid     = (int) auth_user_id();
$user    = db_one('SELECT id, name, email FROM users WHERE id = ?', [$uid]);
$prefs   = user_prefs_with_defaults($uid);

// ── AJAX actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // Valida CSRF
    if (!csrf_check()) { echo json_encode(['ok'=>false,'error'=>'Sessão expirada. Recarregue a página.']); exit; }

    $action = $_POST['action'];

    // ── Salva preferências (qualquer grupo) ──────────────────────────────────
    if ($action === 'save_prefs') {
        $allowed = array_keys(newton_pref_defaults());
        $toSave  = [];
        foreach ($allowed as $key) {
            if (isset($_POST[$key])) {
                $toSave[$key] = trim($_POST[$key]);
            }
        }
        if (empty($toSave)) { echo json_encode(['ok'=>false,'error'=>'Nada para salvar.']); exit; }
        user_prefs_set_many($uid, $toSave);
        audit_log('user.prefs_updated', 'user', $uid, ['keys' => array_keys($toSave)]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── Atualizar nome ───────────────────────────────────────────────────────
    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        if (strlen($name) < 2)  { echo json_encode(['ok'=>false,'error'=>'Nome deve ter pelo menos 2 caracteres.']); exit; }
        if (strlen($name) > 120){ echo json_encode(['ok'=>false,'error'=>'Nome muito longo.']); exit; }
        db_q('UPDATE users SET name = ? WHERE id = ?', [$name, $uid]);
        $_SESSION['user_name'] = $name;
        audit_log('user.profile_updated', 'user', $uid);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── Trocar senha ─────────────────────────────────────────────────────────
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new1    = $_POST['new_password']     ?? '';
        $new2    = $_POST['confirm_password'] ?? '';

        $full = db_one('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!$full || !password_verify($current, $full['password_hash'])) {
            echo json_encode(['ok'=>false,'error'=>'Senha atual incorreta.']); exit;
        }
        if (strlen($new1) < 8)   { echo json_encode(['ok'=>false,'error'=>'Nova senha deve ter pelo menos 8 caracteres.']); exit; }
        if ($new1 !== $new2)     { echo json_encode(['ok'=>false,'error'=>'As senhas não coincidem.']); exit; }
        if ($new1 === $current)  { echo json_encode(['ok'=>false,'error'=>'A nova senha deve ser diferente da atual.']); exit; }

        $hash = password_hash($new1, PASSWORD_BCRYPT, ['cost' => 12]);
        db_q('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $uid]);
        audit_log('user.password_changed', 'user', $uid);
        echo json_encode(['ok'=>true, 'msg'=>'Senha alterada com sucesso!']);
        exit;
    }

    // ── Solicitar exclusão de dados ──────────────────────────────────────────
    if ($action === 'request_data_deletion') {
        $reason = trim($_POST['reason'] ?? 'não informado');
        $subject = 'Solicitação de exclusão de dados — Newton IA';
        $body    = '<pre style="font-family:monospace;font-size:.85rem;color:#18181b">'
                 . "Solicitação de exclusão de dados recebida via painel.\n\n"
                 . "Usuário: {$user['name']} &lt;{$user['email']}&gt; (ID {$uid})\n"
                 . "Tenant ID: {$tid}\n"
                 . "Motivo: {$reason}\n"
                 . "Data: " . date('d/m/Y H:i:s') . "\n\n"
                 . "Processar em até 15 dias úteis conforme LGPD art. 18."
                 . '</pre>';
        hermes_mail('privacidade@newtonia.digital', $subject, $body);
        audit_log('user.data_deletion_requested', 'user', $uid, ['reason' => $reason]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Ação desconhecida.']); exit;
}

// ── Busca dados extras pra exibir ────────────────────────────────────────────
$active_sub  = billing_active_subscription($tid);
$plan        = $tenant['plan_id'] ? db_one('SELECT name, tier_code FROM plans WHERE id = ?', [$tenant['plan_id']]) : null;

// Colunas do Pipeline deste tenant (pra mostrar no select)
require_once __DIR__ . '/../core/crm.php';
$crm_cols = db_all('SELECT id, name FROM crm_columns WHERE tenant_id = ? ORDER BY position', [$tid]);

app_layout('Configurações', 'config', function() use ($user, $prefs, $plan, $active_sub, $crm_cols, $tid, $uid) {
?>
<style>
/* ── Layout config ─────────────────────────────────────────────────────────── */
.cfg-wrap { display: grid; grid-template-columns: 220px 1fr; gap: 24px; max-width: 1000px; }
@media (max-width: 780px) { .cfg-wrap { grid-template-columns: 1fr; } }

/* Nav lateral */
.cfg-nav { position: sticky; top: 20px; align-self: start; }
.cfg-nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 8px;
  font-size: .85rem; font-weight: 500; color: var(--mute); text-decoration: none; cursor: pointer;
  transition: all .12s; margin-bottom: 2px; }
.cfg-nav-item:hover { background: var(--line); color: var(--ink); }
.cfg-nav-item.active { background: #dcfce7; color: #166534; font-weight: 600; }
.cfg-nav-item svg { width: 16px; height: 16px; flex-shrink: 0; opacity: .7; }
.cfg-nav-item.active svg { opacity: 1; }
.cfg-nav-divider { height: 1px; background: var(--line); margin: 8px 0; }

/* Cards de seção */
.cfg-section { margin-bottom: 24px; scroll-margin-top: 24px; }
.cfg-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; overflow: hidden; }
.cfg-card-head { padding: 16px 20px; border-bottom: 1px solid var(--line); display: flex; align-items: center; gap: 10px; }
.cfg-card-head .section-icon { width: 32px; height: 32px; border-radius: 8px; background: #eff6ff; color: var(--newton); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.cfg-card-head h2 { font-size: .95rem; font-weight: 700; color: var(--ink); margin: 0; }
.cfg-card-head p { font-size: .78rem; color: var(--mute); margin: 2px 0 0; line-height: 1.4; }
.cfg-card-body { padding: 20px; }

/* Campos */
.cfg-field { margin-bottom: 18px; }
.cfg-field:last-child { margin-bottom: 0; }
.cfg-field label { display: block; font-family: 'Geist Mono', monospace; font-size: .6rem; color: var(--mute); text-transform: uppercase; letter-spacing: .07em; font-weight: 600; margin-bottom: 6px; }
.cfg-field input[type=text],
.cfg-field input[type=email],
.cfg-field input[type=password],
.cfg-field select {
  width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px;
  font-size: .88rem; font-family: inherit; background: #fff; color: var(--ink);
  transition: border-color .15s, box-shadow .15s;
}
.cfg-field input:focus, .cfg-field select:focus {
  outline: none; border-color: var(--newton); box-shadow: 0 0 0 3px rgba(14,165,233,.1);
}
.cfg-field .hint { font-size: .74rem; color: var(--mute); margin-top: 5px; line-height: 1.4; }
.cfg-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 560px) { .cfg-row { grid-template-columns: 1fr; } }

/* Toggles */
.cfg-toggle-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
  padding: 14px 0; border-bottom: 1px solid var(--line); }
.cfg-toggle-row:last-child { border-bottom: none; padding-bottom: 0; }
.cfg-toggle-row:first-child { padding-top: 0; }
.cfg-toggle-info { flex: 1; }
.cfg-toggle-info strong { font-size: .88rem; font-weight: 600; color: var(--ink); display: block; margin-bottom: 3px; }
.cfg-toggle-info span { font-size: .78rem; color: var(--mute); line-height: 1.4; }

.toggle-sw { position: relative; flex-shrink: 0; width: 42px; height: 24px; margin-top: 2px; }
.toggle-sw input { opacity: 0; width: 0; height: 0; position: absolute; }
.toggle-sw .slider {
  position: absolute; inset: 0; background: var(--line); border-radius: 24px; cursor: pointer;
  transition: background .2s;
}
.toggle-sw .slider::before {
  content: ''; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px;
  background: #fff; border-radius: 50%; transition: transform .2s;
  box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.toggle-sw input:checked + .slider { background: var(--newton); }
.toggle-sw input:checked + .slider::before { transform: translateX(18px); }

/* Botões */
.cfg-btn { display: inline-flex; align-items: center; gap: 7px; padding: 10px 18px; border-radius: 8px;
  font-size: .86rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all .15s; border: none; }
.cfg-btn-primary { background: var(--newton); color: #fff; }
.cfg-btn-primary:hover { background: #0ea371; }
.cfg-btn-ghost { background: #fff; color: var(--ink); border: 1px solid var(--line); }
.cfg-btn-ghost:hover { background: var(--bone); }
.cfg-btn-danger { background: #fff; color: #be123c; border: 1px solid #fecaca; }
.cfg-btn-danger:hover { background: #fef2f2; }
.cfg-btn:disabled { opacity: .5; cursor: not-allowed; }
.cfg-footer { padding: 14px 20px; border-top: 1px solid var(--line); background: var(--bone); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.cfg-footer .save-status { font-size: .78rem; color: var(--mute); }

/* Toast inline */
.cfg-toast { display: none; padding: 8px 14px; border-radius: 7px; font-size: .8rem; font-weight: 600;
  margin-top: 10px; }
.cfg-toast.success { background: #eff6ff; border: 1px solid #86efac; color: #166534; display: block; }
.cfg-toast.error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; display: block; }

/* Info badge do plano */
.plan-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px;
  font-family: 'Geist Mono', monospace; font-size: .6rem; text-transform: uppercase; letter-spacing: .08em;
  font-weight: 600; background: #dcfce7; color: #166534; }

/* Seção de risco */
.cfg-danger-zone { border-color: #fecaca; }
.cfg-danger-zone .cfg-card-head { border-bottom-color: #fecaca; }
.cfg-danger-zone .section-icon { background: #fef2f2; color: #be123c; }
</style>

<div class="cfg-wrap">

  <!-- ── Navegação lateral ─────────────────────────────────────────────────── -->
  <div class="cfg-nav">
    <a class="cfg-nav-item active" href="#perfil" onclick="scrollTo('#perfil',this)">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z"/></svg>
      Perfil
    </a>
    <a class="cfg-nav-item" href="#notificacoes" onclick="scrollTo('#notificacoes',this)">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
      Notificações
    </a>
    <a class="cfg-nav-item" href="#painel" onclick="scrollTo('#painel',this)">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Painel
    </a>
    <a class="cfg-nav-item" href="#radar" onclick="scrollTo('#radar',this)">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm0 0v10l6 4"/></svg>
      Radar Leads
    </a>
    <a class="cfg-nav-item" href="#pipeline" onclick="scrollTo('#pipeline',this)">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
      Pipeline
    </a>
    <div class="cfg-nav-divider"></div>
    <a class="cfg-nav-item" href="#privacidade" onclick="scrollTo('#privacidade',this)">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      Privacidade
    </a>
  </div>

  <!-- ── Conteúdo ──────────────────────────────────────────────────────────── -->
  <div>

    <!-- PERFIL -->
    <div id="perfil" class="cfg-section">
      <div class="cfg-card">
        <div class="cfg-card-head">
          <div class="section-icon">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z"/></svg>
          </div>
          <div>
            <h2>Perfil</h2>
            <p>Seu nome e credenciais de acesso</p>
          </div>
          <?php if ($plan): ?>
            <span class="plan-badge" style="margin-left:auto"><?= e($plan['name']) ?></span>
          <?php endif; ?>
        </div>
        <div class="cfg-card-body">
          <!-- Nome -->
          <div class="cfg-field">
            <label>Nome de exibição</label>
            <input type="text" id="prof-name" value="<?= e($user['name'] ?? '') ?>"
                   maxlength="120" placeholder="Seu nome completo">
          </div>
          <div class="cfg-field">
            <label>E-mail</label>
            <input type="email" value="<?= e($user['email']) ?>" disabled
                   style="background:var(--bone);color:var(--mute);cursor:not-allowed">
            <div class="hint">Para alterar o e-mail, contate o suporte.</div>
          </div>
          <div id="prof-toast" class="cfg-toast"></div>
        </div>
        <div class="cfg-footer">
          <span class="save-status" id="prof-status"></span>
          <button class="cfg-btn cfg-btn-primary" onclick="savePerfil()">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2zM17 21v-8H7v8M7 3v5h8"/></svg>
            Salvar nome
          </button>
        </div>
      </div>

      <!-- Troca de senha -->
      <div class="cfg-card" style="margin-top:14px">
        <div class="cfg-card-head">
          <div class="section-icon">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
          </div>
          <div>
            <h2>Senha</h2>
            <p>Troque sua senha de acesso</p>
          </div>
        </div>
        <div class="cfg-card-body">
          <div class="cfg-row">
            <div class="cfg-field">
              <label>Senha atual</label>
              <input type="password" id="pw-current" autocomplete="current-password">
            </div>
            <div></div>
          </div>
          <div class="cfg-row">
            <div class="cfg-field">
              <label>Nova senha</label>
              <input type="password" id="pw-new" autocomplete="new-password" minlength="8"
                     oninput="checkPwStrength(this.value)">
              <div class="hint">Mínimo 8 caracteres</div>
              <div style="height:3px;border-radius:2px;background:var(--line);margin-top:6px;overflow:hidden">
                <div id="pw-strength-bar" style="height:100%;width:0;transition:all .3s"></div>
              </div>
            </div>
            <div class="cfg-field">
              <label>Confirmar nova senha</label>
              <input type="password" id="pw-confirm" autocomplete="new-password">
            </div>
          </div>
          <div id="pw-toast" class="cfg-toast"></div>
        </div>
        <div class="cfg-footer">
          <span class="save-status" id="pw-status"></span>
          <button class="cfg-btn cfg-btn-primary" onclick="changeSenha()">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Alterar senha
          </button>
        </div>
      </div>
    </div>

    <!-- NOTIFICAÇÕES -->
    <div id="notificacoes" class="cfg-section">
      <div class="cfg-card">
        <div class="cfg-card-head">
          <div class="section-icon">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
          </div>
          <div>
            <h2>Notificações por e-mail</h2>
            <p>Escolha quais e-mails você quer receber</p>
          </div>
        </div>
        <div class="cfg-card-body">
          <div class="cfg-toggle-row">
            <div class="cfg-toggle-info">
              <strong>Cobranças e faturas</strong>
              <span>Confirmação de pagamento, fatura gerada, cobrança vencendo</span>
            </div>
            <label class="toggle-sw">
              <input type="checkbox" id="notif_billing"
                     <?= $prefs['notif_billing'] === '1' ? 'checked' : '' ?>
                     onchange="savePref('notif_billing', this.checked?'1':'0','notif-toast')">
              <span class="slider"></span>
            </label>
          </div>
          <div class="cfg-toggle-row">
            <div class="cfg-toggle-info">
              <strong>Alertas de cota</strong>
              <span>Aviso quando atingir 80% e 90% da cota mensal do Radar Leads</span>
            </div>
            <label class="toggle-sw">
              <input type="checkbox" id="notif_quota_alert"
                     <?= $prefs['notif_quota_alert'] === '1' ? 'checked' : '' ?>
                     onchange="savePref('notif_quota_alert', this.checked?'1':'0','notif-toast')">
              <span class="slider"></span>
            </label>
          </div>
          <div class="cfg-toggle-row">
            <div class="cfg-toggle-info">
              <strong>Novidades do Newton IA</strong>
              <span>Novas funcionalidades, atualizações de módulos e dicas de uso</span>
            </div>
            <label class="toggle-sw">
              <input type="checkbox" id="notif_news"
                     <?= $prefs['notif_news'] === '1' ? 'checked' : '' ?>
                     onchange="savePref('notif_news', this.checked?'1':'0','notif-toast')">
              <span class="slider"></span>
            </label>
          </div>
          <div id="notif-toast" class="cfg-toast" style="margin-top:12px"></div>
        </div>
      </div>
    </div>

    <!-- PAINEL -->
    <div id="painel" class="cfg-section">
      <div class="cfg-card">
        <div class="cfg-card-head">
          <div class="section-icon">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
          </div>
          <div>
            <h2>Preferências do painel</h2>
            <p>Comportamento geral ao usar o Newton IA</p>
          </div>
        </div>
        <div class="cfg-card-body">
          <div class="cfg-row">
            <div class="cfg-field">
              <label>Módulo ao entrar</label>
              <select id="default_module" onchange="savePref('default_module', this.value, 'painel-toast')">
                <option value="overview"  <?= $prefs['default_module']==='overview' ?'selected':'' ?>>Visão Geral (padrão)</option>
                <option value="crm"       <?= $prefs['default_module']==='crm'      ?'selected':'' ?>>Pipeline</option>
                <option value="cnpj"      <?= $prefs['default_module']==='cnpj'     ?'selected':'' ?>>Radar Leads</option>
                <option value="maillab"   <?= $prefs['default_module']==='maillab'  ?'selected':'' ?>>Mail Lab</option>
              </select>
              <div class="hint">Para qual módulo ir logo após o login</div>
            </div>
          </div>
          <div class="cfg-toggle-row" style="margin-top:8px">
            <div class="cfg-toggle-info">
              <strong>Sidebar fixada</strong>
              <span>Manter a barra lateral sempre expandida (sem precisar passar o mouse)</span>
            </div>
            <label class="toggle-sw">
              <input type="checkbox" id="sidebar_pinned"
                     <?= $prefs['sidebar_pinned'] === '1' ? 'checked' : '' ?>
                     onchange="savePref('sidebar_pinned', this.checked?'1':'0','painel-toast'); toggleSidebarPin(this.checked)">
              <span class="slider"></span>
            </label>
          </div>
          <div id="painel-toast" class="cfg-toast" style="margin-top:12px"></div>
        </div>
      </div>
    </div>

    <!-- RADAR LEADS -->
    <div id="radar" class="cfg-section">
      <div class="cfg-card">
        <div class="cfg-card-head">
          <div class="section-icon" style="background:#dcfce7;color:#059669">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm0 0v10l6 4"/></svg>
          </div>
          <div>
            <h2>Radar Leads</h2>
            <p>Preferências de prospecção e visualização de CNPJs</p>
          </div>
        </div>
        <div class="cfg-card-body">
          <div class="cfg-row">
            <div class="cfg-field">
              <label>Resultados por página</label>
              <select id="radar_per_page" onchange="savePref('radar_per_page', this.value, 'radar-toast')">
                <option value="10"  <?= $prefs['radar_per_page']==='10'  ?'selected':'' ?>>10 por página</option>
                <option value="25"  <?= $prefs['radar_per_page']==='25'  ?'selected':'' ?>>25 por página (padrão)</option>
                <option value="50"  <?= $prefs['radar_per_page']==='50'  ?'selected':'' ?>>50 por página</option>
              </select>
            </div>
            <div class="cfg-field">
              <label>Ordenação padrão</label>
              <select id="radar_default_sort" onchange="savePref('radar_default_sort', this.value, 'radar-toast')">
                <option value="score_desc" <?= $prefs['radar_default_sort']==='score_desc'?'selected':'' ?>>Melhor score primeiro</option>
                <option value="name_asc"   <?= $prefs['radar_default_sort']==='name_asc'  ?'selected':'' ?>>Nome A → Z</option>
                <option value="city_asc"   <?= $prefs['radar_default_sort']==='city_asc'  ?'selected':'' ?>>Cidade A → Z</option>
              </select>
            </div>
          </div>
          <div class="cfg-toggle-row" style="margin-top:8px">
            <div class="cfg-toggle-info">
              <strong>Mostrar breakdown do Radar Score</strong>
              <span>Exibir os 5 componentes do score (Reach, BuyingPower, Fit, Stability, Brand) por padrão</span>
            </div>
            <label class="toggle-sw">
              <input type="checkbox" id="radar_show_score_detail"
                     <?= $prefs['radar_show_score_detail'] === '1' ? 'checked' : '' ?>
                     onchange="savePref('radar_show_score_detail', this.checked?'1':'0','radar-toast')">
              <span class="slider"></span>
            </label>
          </div>
          <div id="radar-toast" class="cfg-toast" style="margin-top:12px"></div>
        </div>
      </div>
    </div>

    <!-- PIPELINE -->
    <div id="pipeline" class="cfg-section">
      <div class="cfg-card">
        <div class="cfg-card-head">
          <div class="section-icon" style="background:#eff6ff;color:#059669">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
          </div>
          <div>
            <h2>Pipeline</h2>
            <p>CRM Kanban — campos, produto, agenda e exibição</p>
          </div>
        </div>
        <div class="cfg-card-body">

          <!-- Visualização -->
          <div style="font-family:'Geist Mono',monospace;font-size:.64rem;color:var(--mute);text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:12px">// Visualização</div>
          <div class="cfg-row">
            <div class="cfg-field">
              <label>Visualização padrão</label>
              <select id="pipeline_default_view" onchange="savePref('pipeline_default_view', this.value, 'pipeline-toast')">
                <option value="kanban" <?= $prefs['pipeline_default_view']==='kanban'?'selected':'' ?>>Kanban (colunas)</option>
                <option value="list"   <?= $prefs['pipeline_default_view']==='list'  ?'selected':'' ?>>Lista</option>
              </select>
            </div>
            <div class="cfg-field">
              <label>Limite de cards por coluna</label>
              <select id="pipeline_cards_per_col" onchange="savePref('pipeline_cards_per_col', this.value, 'pipeline-toast')">
                <option value="20"  <?= $prefs['pipeline_cards_per_col']==='20' ?'selected':'' ?>>20 cards</option>
                <option value="50"  <?= $prefs['pipeline_cards_per_col']==='50' ?'selected':'' ?>>50 cards (padrão)</option>
                <option value="100" <?= $prefs['pipeline_cards_per_col']==='100'?'selected':'' ?>>100 cards</option>
              </select>
              <div class="hint">Afeta performance com muitos cards</div>
            </div>
          </div>
          <div class="cfg-field" style="margin-bottom:14px">
            <label>Colorir cards por</label>
            <select id="pipeline_card_color" onchange="savePref('pipeline_card_color', this.value, 'pipeline-toast')">
              <option value="score"  <?= $prefs['pipeline_card_color']==='score' ?'selected':'' ?>>Score Newton (🔥 Quente / ⭐ Bom / 🌱 Médio / ❄ Frio)</option>
              <option value="column" <?= $prefs['pipeline_card_color']==='column'?'selected':'' ?>>Cor da coluna</option>
              <option value="mono"   <?= $prefs['pipeline_card_color']==='mono'  ?'selected':'' ?>>Monocromático (sem cor)</option>
            </select>
          </div>

          <!-- Campos dos cards -->
          <div style="font-family:'Geist Mono',monospace;font-size:.64rem;color:var(--mute);text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin:20px 0 12px">// Campos dos cards</div>
          <div class="cfg-toggle-row">
            <div class="cfg-toggle-info">
              <strong>Valor do negócio (R$)</strong>
              <span>Mostra campo de valor monetário em cada card do Pipeline</span>
            </div>
            <label class="toggle-sw">
              <input type="checkbox" id="pipeline_show_value"
                     <?= $prefs['pipeline_show_value'] === '1' ? 'checked' : '' ?>
                     onchange="savePref('pipeline_show_value', this.checked?'1':'0','pipeline-toast')">
              <span class="slider"></span>
            </label>
          </div>
          <div class="cfg-toggle-row">
            <div class="cfg-toggle-info">
              <strong>Prazo / data de fechamento</strong>
              <span>Campo de data por card para controlar deadlines de negócios</span>
            </div>
            <label class="toggle-sw">
              <input type="checkbox" id="pipeline_show_due_date"
                     <?= $prefs['pipeline_show_due_date'] === '1' ? 'checked' : '' ?>
                     onchange="savePref('pipeline_show_due_date', this.checked?'1':'0','pipeline-toast')">
              <span class="slider"></span>
            </label>
          </div>
          <div class="cfg-toggle-row">
            <div class="cfg-toggle-info">
              <strong>Mostrar cards arquivados</strong>
              <span>Incluir cards arquivados na visualização do Kanban por padrão</span>
            </div>
            <label class="toggle-sw">
              <input type="checkbox" id="pipeline_show_archived"
                     <?= $prefs['pipeline_show_archived'] === '1' ? 'checked' : '' ?>
                     onchange="savePref('pipeline_show_archived', this.checked?'1':'0','pipeline-toast')">
              <span class="slider"></span>
            </label>
          </div>

          <!-- Produto / Serviço -->
          <div style="font-family:'Geist Mono',monospace;font-size:.64rem;color:var(--mute);text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin:20px 0 12px">// Produto / Serviço</div>
          <div class="cfg-field">
            <label>Rótulo do produto que você vende</label>
            <input type="text" id="pipeline_product_label"
                   value="<?= e($prefs['pipeline_product_label']) ?>"
                   placeholder="Ex: Consultoria, Software, Assinatura mensal, Projeto gráfico…"
                   maxlength="80"
                   oninput="scheduleSave('pipeline_product_label', this.value, 'pipeline-toast')">
            <div class="hint">Aparece como rótulo do campo produto no card. Deixe vazio pra não exibir.</div>
          </div>

          <!-- Notificações de prazo -->
          <div style="font-family:'Geist Mono',monospace;font-size:.64rem;color:var(--mute);text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin:20px 0 12px">// Agenda</div>
          <div class="cfg-toggle-row">
            <div class="cfg-toggle-info">
              <strong>Notificar quando prazo vence</strong>
              <span>Receber e-mail no dia em que o prazo de um card do Pipeline expirar</span>
            </div>
            <label class="toggle-sw">
              <input type="checkbox" id="pipeline_notify_due"
                     <?= $prefs['pipeline_notify_due'] === '1' ? 'checked' : '' ?>
                     onchange="savePref('pipeline_notify_due', this.checked?'1':'0','pipeline-toast')">
              <span class="slider"></span>
            </label>
          </div>
          <div style="background:var(--bone);border-radius:8px;padding:12px 14px;font-size:.8rem;color:var(--mute);margin-top:10px">
            📅 Com <strong style="color:var(--ink)">prazo ativo</strong>, cada card do Pipeline ganha um campo de data. O cron diário verifica os prazos e envia um e-mail resumo com os negócios vencendo hoje. Configure os prazos diretamente nos cards do Pipeline.
          </div>

          <div id="pipeline-toast" class="cfg-toast" style="margin-top:12px"></div>
        </div>
      </div>
    </div>

    <!-- PRIVACIDADE & DADOS -->
    <div id="privacidade" class="cfg-section">
      <div class="cfg-card cfg-danger-zone">
        <div class="cfg-card-head">
          <div class="section-icon">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </div>
          <div>
            <h2>Privacidade & Dados</h2>
            <p>Seus direitos conforme a LGPD</p>
          </div>
          <a href="/privacy.php" target="_blank"
             style="margin-left:auto;font-size:.78rem;color:var(--newton);text-decoration:none;white-space:nowrap">
            Ver política →
          </a>
        </div>
        <div class="cfg-card-body">

          <!-- Links de direitos -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px">
            <a href="/privacy.php#direitos" target="_blank"
               style="display:flex;align-items:center;gap:8px;padding:12px 14px;background:var(--bone);border:1px solid var(--line);border-radius:8px;text-decoration:none;color:var(--ink);font-size:.83rem;font-weight:500;transition:all .12s"
               onmouseover="this.style.borderColor='var(--newton)'" onmouseout="this.style.borderColor='var(--line)'">
              <svg width="14" height="14" fill="none" stroke="#0ea5e9" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8zM12 9v6M12 6v.01"/></svg>
              Meus direitos (LGPD art. 18)
            </a>
            <a href="mailto:privacidade@newtonia.digital?subject=Portabilidade de dados&body=Olá, gostaria de solicitar a portabilidade dos meus dados. Meu e-mail é: <?= e($user['email']) ?>"
               style="display:flex;align-items:center;gap:8px;padding:12px 14px;background:var(--bone);border:1px solid var(--line);border-radius:8px;text-decoration:none;color:var(--ink);font-size:.83rem;font-weight:500;transition:all .12s"
               onmouseover="this.style.borderColor='var(--newton)'" onmouseout="this.style.borderColor='var(--line)'">
              <svg width="14" height="14" fill="none" stroke="#0ea5e9" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
              Solicitar portabilidade
            </a>
          </div>

          <!-- Solicitar exclusão -->
          <div style="padding:16px;border:1px solid #fecaca;border-radius:10px;background:#fff">
            <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:14px">
              <div style="width:32px;height:32px;border-radius:8px;background:#fef2f2;color:#be123c;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6M10 11v6M14 11v6M9 6V4h6v2"/></svg>
              </div>
              <div>
                <strong style="font-size:.88rem;color:#991b1b;display:block;margin-bottom:3px">Solicitar exclusão de dados</strong>
                <span style="font-size:.78rem;color:var(--mute);line-height:1.45">
                  Sua conta e todos os dados associados serão removidos em até 15 dias úteis. Dados fiscais podem ser retidos por obrigação legal (5 anos). Esta ação não cancela a assinatura automaticamente.
                </span>
              </div>
            </div>
            <div class="cfg-field">
              <label>Motivo (opcional)</label>
              <input type="text" id="deletion-reason" placeholder="Ex: não uso mais o serviço" maxlength="200">
            </div>
            <div id="deletion-toast" class="cfg-toast"></div>
            <button class="cfg-btn cfg-btn-danger" onclick="requestDeletion()" style="margin-top:10px">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
              Solicitar exclusão de dados
            </button>
          </div>

        </div>
      </div>
    </div>

  </div><!-- /conteúdo -->
</div><!-- /cfg-wrap -->

<script>
const _csrf = <?= json_encode(csrf_token()) ?>;

// ── Helpers ──────────────────────────────────────────────────────────────────
function showToast(id, msg, type) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = msg;
  el.className = 'cfg-toast ' + type;
  clearTimeout(el._t);
  el._t = setTimeout(() => { el.className = 'cfg-toast'; }, 3200);
}

async function postAction(data) {
  data._csrf = _csrf;
  const fd = new FormData();
  for (const [k, v] of Object.entries(data)) fd.append(k, v);
  const r = await fetch('configuracoes.php', { method:'POST', body:fd });
  return r.json();
}

// ── Navegação lateral (scroll suave + active) ─────────────────────────────
function scrollTo(hash, el) {
  event.preventDefault();
  document.querySelectorAll('.cfg-nav-item').forEach(a => a.classList.remove('active'));
  if (el) el.classList.add('active');
  const target = document.querySelector(hash);
  if (target) target.scrollIntoView({ behavior:'smooth', block:'start' });
}

// Atualiza nav lateral ao rolar
const sections = ['perfil','notificacoes','painel','radar','pipeline','privacidade'];
window.addEventListener('scroll', () => {
  let current = sections[0];
  sections.forEach(id => {
    const el = document.getElementById(id);
    if (el && el.getBoundingClientRect().top <= 80) current = id;
  });
  document.querySelectorAll('.cfg-nav-item').forEach(a => {
    a.classList.toggle('active', a.getAttribute('href') === '#' + current);
  });
}, { passive:true });

// ── Salvar pref individual (toggles/selects) ─────────────────────────────
async function savePref(key, value, toastId) {
  const r = await postAction({ action:'save_prefs', [key]: value });
  showToast(toastId, r.ok ? '✓ Salvo' : '⚠ ' + (r.error||'Erro'), r.ok ? 'success' : 'error');
}

// ── Salvar com debounce (campos de texto, 900ms após última tecla) ───────────
const _saveTimers = {};
function scheduleSave(key, value, toastId, delay = 900) {
  clearTimeout(_saveTimers[key]);
  _saveTimers[key] = setTimeout(() => savePref(key, value, toastId), delay);
}

// ── Sidebar pin (aplica imediatamente na sidebar) ─────────────────────────
function toggleSidebarPin(checked) {
  const sb = document.querySelector('.sidebar');
  if (!sb) return;
  if (checked) sb.classList.add('pinned');
  else sb.classList.remove('pinned');
}
// Aplica pref de sidebar ao carregar
(function() {
  const pinned = <?= json_encode($prefs['sidebar_pinned'] === '1') ?>;
  if (pinned) { const sb = document.querySelector('.sidebar'); if (sb) sb.classList.add('pinned'); }
})();

// ── Força módulo padrão ao navegar (guarda na sessão via pref) ───────────

// ── Salvar perfil (nome) ──────────────────────────────────────────────────
async function savePerfil() {
  const name = document.getElementById('prof-name').value.trim();
  if (!name || name.length < 2) { showToast('prof-toast','Nome inválido.','error'); return; }
  const r = await postAction({ action:'update_profile', name });
  showToast('prof-toast', r.ok ? '✓ Nome atualizado!' : '⚠ ' + (r.error||'Erro'), r.ok ? 'success' : 'error');
  if (r.ok) {
    // Atualiza avatar inicial na sidebar
    const av = document.querySelector('.avatar');
    if (av) av.textContent = name.charAt(0).toUpperCase();
  }
}

// ── Força Enter no campo nome ─────────────────────────────────────────────
document.getElementById('prof-name').addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); savePerfil(); }
});

// ── Strength bar de senha ─────────────────────────────────────────────────
function checkPwStrength(v) {
  let s = 0;
  if (v.length >= 8)  s++;
  if (v.length >= 12) s++;
  if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++;
  if (/\d/.test(v)) s++;
  if (/[^A-Za-z0-9]/.test(v)) s++;
  const bar = document.getElementById('pw-strength-bar');
  const cols = ['#ef4444','#f97316','#eab308','#0ea5e9','#0ea5e9'];
  bar.style.background = cols[s-1] || 'transparent';
  bar.style.width = (s * 20) + '%';
}

// ── Trocar senha ─────────────────────────────────────────────────────────
async function changeSenha() {
  const cur  = document.getElementById('pw-current').value;
  const np   = document.getElementById('pw-new').value;
  const conf = document.getElementById('pw-confirm').value;
  if (!cur)     { showToast('pw-toast','Informe a senha atual.','error'); return; }
  if (np.length < 8) { showToast('pw-toast','Nova senha: mínimo 8 caracteres.','error'); return; }
  if (np !== conf)   { showToast('pw-toast','Senhas não coincidem.','error'); return; }

  const r = await postAction({ action:'change_password', current_password:cur, new_password:np, confirm_password:conf });
  showToast('pw-toast', r.ok ? '✓ ' + (r.msg||'Senha alterada!') : '⚠ ' + (r.error||'Erro'), r.ok ? 'success' : 'error');
  if (r.ok) {
    document.getElementById('pw-current').value = '';
    document.getElementById('pw-new').value = '';
    document.getElementById('pw-confirm').value = '';
    document.getElementById('pw-strength-bar').style.width = '0';
  }
}

// ── Solicitar exclusão ────────────────────────────────────────────────────
let deletionConfirmed = false;
async function requestDeletion() {
  if (!deletionConfirmed) {
    deletionConfirmed = true;
    showToast('deletion-toast', '⚠ Clique novamente para confirmar a solicitação de exclusão.', 'error');
    setTimeout(() => { deletionConfirmed = false; }, 5000);
    return;
  }
  deletionConfirmed = false;
  const reason = document.getElementById('deletion-reason').value.trim() || 'não informado';
  const r = await postAction({ action:'request_data_deletion', reason });
  showToast('deletion-toast',
    r.ok ? '✓ Solicitação enviada. Responderemos em até 15 dias úteis.' : '⚠ ' + (r.error||'Erro'),
    r.ok ? 'success' : 'error');
}
</script>
<?php
});
