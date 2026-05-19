<?php
/**
 * Newton AI — Newton CNPJ: busca e prospecção de empresas
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_layout.php';
@set_time_limit(90);
ini_set('memory_limit', '512M');
$tenant    = require_tenant();
$tenant_id = (int) $tenant['id'];

$f = array_map('trim', array_filter($_GET, 'is_string'));
unset($f['page'], $f['sort'], $f['per']);

// NÃO busca automático — só quando o usuário clica "Buscar" (envia ?submitted=1)
$has_submitted = isset($_GET['submitted']) || !empty($f);
$is_default = false;

// Sempre 100 por batch — leve no client e na rede
$per     = 100;
$sort    = $_GET['sort'] ?? 'qualified';
$results = ['rows' => [], 'total' => 0, 'total_more' => false];
if ($has_submitted) {
    try {
        $results = cnpj_search($f, 1, $per, $sort);
    } catch (\Throwable $exSearch) {
        $results['error'] = $exSearch->getMessage();
    }
}

$total_label = cnpj_nfmt_br($results['total']) . (($results['total_more'] ?? false) ? '+' : '');
$has_more    = !empty($results['rows']) && (count($results['rows']) >= $per || ($results['total_more'] ?? false));

// Quota info
$q_limit = cnpj_monthly_limit($tenant_id);
$q_used  = cnpj_monthly_used($tenant_id);
$q_pct   = cnpj_usage_pct($q_used, $q_limit);
$q_addon = (int) db_val(
    'SELECT cnpj_addon_credits FROM tenants WHERE id = ?',
    [$tenant_id]
);
$q_bar_class = $q_pct >= 90 ? 'danger' : ($q_pct >= 50 ? 'warn' : 'ok');

// ── Alerta automático de cota ─────────────────────────────────────────────────
// Dispara e-mail UMA vez por threshold por mês. Não bloqueia a página.
$q_alert_banner = null; // será exibido no HTML se preenchido
(function() use ($q_pct, $q_used, $q_limit, $tenant, $tenant_id, &$q_alert_banner) {
    $uid = (int) auth_user_id();
    if (!$uid || $q_limit <= 0) return;

    $month = date('Y-m');

    // Determina threshold atual
    $threshold = null;
    if ($q_pct >= 100) $threshold = 100;
    elseif ($q_pct >= 90) $threshold = 90;
    elseif ($q_pct >= 80) $threshold = 80;
    if (!$threshold) return;

    // Define banner a exibir
    $q_alert_banner = $threshold;

    // Verifica se e-mail já foi enviado neste mês para este threshold
    $pref_key = "quota_alert_{$threshold}_sent";
    $sent_month = user_pref_get($uid, $pref_key, '');
    if ($sent_month === $month) return; // já enviou este mês

    // Marca como enviado ANTES de tentar enviar (evita loop em erro de mail)
    user_pref_set($uid, $pref_key, $month);

    // Busca e-mail do usuário
    $user = db_one('SELECT name, email FROM users WHERE id = ?', [$uid]);
    if (!$user || !$user['email']) return;

    $pct_label    = number_format($q_pct, 0) . '%';
    $used_label   = number_format($q_used, 0, ',', '.');
    $limit_label  = number_format($q_limit, 0, ',', '.');
    $tenant_name  = htmlspecialchars($tenant['name'] ?? 'sua conta');
    $billing_url  = rtrim(APP_URL, '/') . '/app/billing.php';

    if ($threshold === 100) {
        $subject = 'HERMES.b2b — Cota Radar esgotada 🚫';
        $headline = 'Sua cota de extrações Radar foi <strong>esgotada</strong>.';
        $color    = '#dc2626';
        $icon     = '🚫';
        $action   = 'Para continuar prospectando, adquira um <strong>Lead Pack</strong> ou faça upgrade de plano.';
    } elseif ($threshold === 90) {
        $subject = 'HERMES.b2b — Cota Radar em 90% ⚠';
        $headline = 'Você usou <strong>90%</strong> da cota Radar deste mês.';
        $color    = '#d97706';
        $icon     = '⚠';
        $action   = 'Restam poucas extrações. Considere um Lead Pack antes de atingir o limite.';
    } else {
        $subject = 'HERMES.b2b — Cota Radar em 80% 📊';
        $headline = 'Você usou <strong>80%</strong> da cota Radar deste mês.';
        $color    = '#ca8a04';
        $icon     = '📊';
        $action   = 'Boa prospecção! Se precisar de mais extrações, Lead Packs estão disponíveis.';
    }

    $body = <<<HTML
<!DOCTYPE html><html lang="pt-BR"><body style="font-family:sans-serif;color:#18181b;padding:24px;max-width:560px;margin:0 auto">
<div style="margin-bottom:18px"><strong style="font-size:1.1rem;color:#10b981">HERMES<span style="color:#18181b">.b2b</span></strong></div>
<div style="background:{$color}15;border-left:4px solid {$color};padding:14px 18px;border-radius:8px;margin-bottom:18px">
  <p style="margin:0;font-size:1rem;color:{$color};font-weight:700">{$icon} {$headline}</p>
</div>
<p><strong>{$tenant_name}</strong> · {$used_label} de {$limit_label} extrações usadas este mês ({$pct_label})</p>
<p style="margin:12px 0">{$action}</p>
<p style="margin:20px 0">
  <a href="{$billing_url}" style="background:#10b981;color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block">Ver planos e Lead Packs →</a>
</p>
<hr style="margin:20px 0;border:none;border-top:1px solid #e7e5e0">
<p style="font-size:.78rem;color:#8b8a93">Cota reinicia no 1º dia do próximo mês. Para mais informações, acesse o painel HERMES.b2b.</p>
</body></html>
HTML;

    hermes_mail($user['email'], $subject, $body);
})();

// Active filter chips (label, key, value)
$active_chips = [];
if (!empty($f['q']))           $active_chips[] = ['Busca: ' . $f['q'], 'q'];
if (!empty($f['uf']))          $active_chips[] = ['UF: ' . $f['uf'], 'uf'];
if (!empty($f['municipio'])) {
    // Tenta resolver o nome do município
    $mun_label = 'Município';
    try {
        $mun_nome = cnpj_val('SELECT descricao FROM rf_municipios WHERE TRIM(codigo::text) = ?', [trim($f['municipio'])]);
        if ($mun_nome) $mun_label = 'Cidade: ' . $mun_nome;
        else $mun_label = 'Cidade: cód ' . $f['municipio'];
    } catch (\Throwable $e) {}
    $active_chips[] = [$mun_label, 'municipio'];
}
if (!empty($f['vertical'])) {
    // Nome amigável
    $vname = '';
    foreach (cnpj_verticais_list() as $vrow) {
        if ($vrow['vertical_id'] === $f['vertical']) { $vname = $vrow['vertical_nome']; break; }
    }
    $active_chips[] = ['Vertical: ' . ($vname ?: $f['vertical']), 'vertical'];
}
if (!empty($f['sub_vertical'])) $active_chips[] = ['Sub: ' . $f['sub_vertical'], 'sub_vertical'];
if (!empty($f['cnae']))        $active_chips[] = ['CNAE: ' . $f['cnae'], 'cnae'];
if (!empty($f['situacao']))    $active_chips[] = ['Situação: ' . cnpj_situacao_label($f['situacao']), 'situacao'];
if (!empty($f['porte']))       $active_chips[] = ['Porte: ' . cnpj_porte_label($f['porte']), 'porte'];
if (!empty($f['mf']))          $active_chips[] = [$f['mf'] === '1' ? 'Matriz' : 'Filial', 'mf'];
if (!empty($f['abertura_de'])) $active_chips[] = ['Abertura ≥ ' . cnpj_data_br($f['abertura_de']), 'abertura_de'];
if (!empty($f['abertura_ate']))$active_chips[] = ['Abertura ≤ ' . cnpj_data_br($f['abertura_ate']), 'abertura_ate'];
if (!empty($f['tem_email']))   $active_chips[] = ['Com e-mail', 'tem_email'];
if (!empty($f['tem_tel']))     $active_chips[] = ['Com telefone', 'tem_tel'];
if (!empty($f['simples']))     $active_chips[] = ['Simples Nacional', 'simples'];
if (!empty($f['mei']))         $active_chips[] = ['Apenas MEI', 'mei'];
if (!empty($f['sem_mei']))     $active_chips[] = ['Sem MEI', 'sem_mei'];

function chip_url_remove(array $f, string $key): string {
    unset($f[$key]);
    return '?' . http_build_query($f);
}
function toggle_url(array $f, string $key, string $value = '1'): string {
    if (!empty($f[$key]) && $f[$key] == $value) unset($f[$key]); else $f[$key] = $value;
    // MEI e Sem-MEI são mutualmente exclusivos
    if ($key === 'mei'     && !empty($f['mei']))     unset($f['sem_mei']);
    if ($key === 'sem_mei' && !empty($f['sem_mei'])) unset($f['mei']);
    unset($f['page']);
    return '?' . http_build_query($f);
}

app_layout('Radar Leads · CNPJ', 'cnpj', function () use ($f, $results, $per, $sort, $total_label, $has_more, $is_default, $has_submitted, $q_limit, $q_used, $q_pct, $q_addon, $q_bar_class, $active_chips, $q_alert_banner) {
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HERMES · Radar Leads — CNPJ</title>
<script>
// F5/Ctrl+R com filtros na URL → redireciona para URL limpa ANTES de renderizar o resto.
// Roda o mais cedo possível para minimizar flicker.
(function() {
    if (!location.search) return;
    var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    var isReload = nav ? nav.type === 'reload' : (performance.navigation && performance.navigation.type === 1);
    if (isReload) {
        // Marca para o auto-restore via sessionStorage não disparar na URL limpa
        try { sessionStorage.setItem('cnpj_just_reloaded', '1'); } catch(_) {}
        location.replace('cnpj.php');
    }
})();
</script>
<style>
  /* --cr é herdado de _layout.php (HERMES #10b981 — cor do produto) */
  .cnpj-page        { max-width:1400px; margin:0 auto; }
  .cnpj-results     { width:100%; }
  /* legado (sidebar antiga) — não usar mais */
  .cnpj-wrap, .cnpj-filters { display:block; width:auto; }
  .cnpj-filters     { display:none; }

  .filter-group     { display:flex; flex-direction:column; gap:3px; }
  .filter-group label  { font-size:.65rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; }
  .filter-group input,
  .filter-group select { box-sizing:border-box; padding:7px 10px; border:1px solid #e5e7eb; border-radius:8px; font-size:.85rem; background:#fff; }
  .filter-group input:focus, .filter-group select:focus { outline:none; border-color:var(--cr); box-shadow:0 0 0 3px rgba(16,185,129,.12); }

  .btn-primary      { background:var(--cr); color:#fff; border:none; border-radius:8px; padding:10px 18px; cursor:pointer; font-size:.9rem; font-weight:600; }
  .btn-primary:hover{ background:#4f46e5; }
  .btn-link         { background:none; border:none; color:var(--cr); font-size:.82rem; cursor:pointer; padding:0; }

  /* Header com quota mini e título inline */
  .top-strip        { display:flex; align-items:center; gap:14px; margin-bottom:14px; padding:0 4px; }
  .top-strip h1     { font-size:1.05rem; font-weight:700; color:#111827; margin:0; }
  .top-strip .quota { display:flex; align-items:center; gap:8px; font-size:.78rem; color:#6b7280; margin-left:auto; }
  .top-strip .quota b { color:#111827; }
  .top-strip .quota .bar { width:140px; background:#e5e7eb; border-radius:3px; height:4px; overflow:hidden; }
  .top-strip .quota .bar > i { display:block; height:100%; background:#22c55e; transition:width .4s; }
  .top-strip .quota .bar > i.warn   { background:#f59e0b; }
  .top-strip .quota .bar > i.danger { background:#ef4444; }
  .top-strip a      { color:var(--cr); text-decoration:none; font-weight:600; font-size:.82rem; }

  /* Search box: search-bar + chips integrados em UM card visual */
  .search-box       { background:#fff; border-radius:14px; padding:14px 16px; box-shadow:0 2px 8px rgba(0,0,0,.06); margin-bottom:12px; }
  .search-bar       { display:flex; gap:10px; align-items:center; }
  .search-bar .input-wrap { flex:1; position:relative; }
  .search-bar .input-wrap svg { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#9ca3af; }
  .search-bar input { width:100%; box-sizing:border-box; padding:12px 14px 12px 42px; border:1px solid #e5e7eb; border-radius:10px; font-size:.95rem; background:#fff; }
  .search-bar input:focus { outline:none; border-color:var(--cr); box-shadow:0 0 0 3px rgba(16,185,129,.12); }
  .btn-filters      { background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; padding:10px 16px; border-radius:10px; cursor:pointer; font-size:.88rem; font-weight:600; display:inline-flex; align-items:center; gap:6px; position:relative; white-space:nowrap; }
  .btn-filters:hover{ background:#eef2ff; color:#4338ca; border-color:#a7f3d0; }
  .btn-filters .count { background:var(--cr); color:#fff; border-radius:999px; font-size:.65rem; padding:2px 7px; font-weight:700; }
  .btn-search       { background:var(--cr); color:#fff; border:none; padding:12px 22px; border-radius:10px; cursor:pointer; font-size:.92rem; font-weight:600; display:inline-flex; align-items:center; gap:6px; transition:all .2s; }
  .btn-search:hover { background:#4f46e5; }
  .btn-search.pending { animation:pulse-search 1.4s infinite; box-shadow:0 0 0 0 rgba(16,185,129,.4); }
  @keyframes pulse-search {
    0% { box-shadow:0 0 0 0 rgba(16,185,129,.5); }
    70% { box-shadow:0 0 0 14px rgba(16,185,129,0); }
    100% { box-shadow:0 0 0 0 rgba(16,185,129,0); }
  }

  /* Quick chips (logo abaixo da busca) — LEGADO */
  .quick-chips      { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px; padding:0 2px; }

  /* Chips dentro do search-box: linha separada com gap pequeno */
  .search-chips     { display:grid; grid-template-columns: 1fr auto; gap:12px; align-items:center; margin-top:12px; padding-top:12px; border-top:1px solid #f1f5f9; }
  /* Strip rows: layout grid estável, sort SEMPRE à direita */
  .strip-row        { display:grid; grid-template-columns: 1fr auto; gap:12px; align-items:center; margin-bottom:8px; padding:10px 12px; background:#fff; border-radius:10px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
  .strip-row-chips  { min-height:50px; }
  .strip-row-active { background:#fffbeb; border:1px solid #fde68a; padding:10px 14px; border-radius:10px; margin-bottom:10px; display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
  .strip-chips      { display:flex; gap:5px; flex-wrap:wrap; align-items:center; min-width:0; }
  .strip-sort       { justify-self:end; }
  .strip-sort select { padding:8px 14px; border:1px solid #a7f3d0; border-radius:8px; font-size:.84rem; background:linear-gradient(to bottom, #fff, #f8fafc); cursor:pointer; min-width:200px; font-weight:600; color:#4338ca; font-family:inherit; }
  .strip-sort select:hover { border-color:var(--cr); background:#eef2ff; }
  .strip-sort select:focus { outline:none; box-shadow:0 0 0 3px rgba(16,185,129,.15); }
  .strip-label      { font-size:.75rem; color:#92400e; font-weight:600; }
  .chip-remove      { display:inline-flex; align-items:center; gap:5px; padding:4px 11px; border:1px solid #fcd34d; background:#fffbeb; border-radius:999px; font-size:.75rem; color:#92400e; text-decoration:none; font-weight:500; transition:all .12s; }
  .chip-remove:hover{ background:#fef3c7; }
  .chip-remove.chip-clear-all { background:#fee2e2; color:#991b1b; border-color:#fecaca; margin-left:auto; }
  .chip-remove.chip-clear-all:hover { background:#fecaca; }

  /* Painel de filtros expansível */
  .filters-panel    { background:#fff; border-radius:12px; box-shadow:0 1px 4px rgba(0,0,0,.06); margin-bottom:10px; overflow:hidden; max-height:0; opacity:0; transition:max-height .25s, opacity .15s, padding .15s; padding:0 22px; }
  .filters-panel.open { max-height:900px; opacity:1; padding:20px 22px; }
  .filters-grid     { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
  .filters-grid section,
  .filters-advanced-grid section { display:flex; flex-direction:column; gap:12px; }
  .filters-grid h4,
  .filters-advanced-grid h4 { margin:0; font-family:'Geist Mono',monospace; font-size:.7rem; text-transform:uppercase; letter-spacing:.1em; color:var(--mute); font-weight:600; padding-bottom:8px; border-bottom:1px solid var(--line); display:flex; align-items:center; gap:6px; }
  .filters-grid h4 .tag-rec { background:var(--hermes); color:#fff; font-family:'Geist Mono',monospace; font-size:.54rem; padding:2px 6px; border-radius:3px; letter-spacing:.08em; font-weight:600; }

  /* Busca avançada — toggle limpo, abre Empresa + Tempo */
  .adv-toggle       { display:flex; align-items:center; gap:8px; width:100%; background:var(--bone); border:1px dashed var(--line); border-radius:10px; padding:11px 14px; margin-top:18px; cursor:pointer; font-size:.82rem; font-weight:500; color:var(--ink-2); font-family:inherit; transition:all .15s; }
  .adv-toggle:hover { background:#f1f5f9; border-color:#94a3b8; color:#0f172a; }
  .adv-toggle .adv-chev  { transition:transform .2s; display:inline-block; color:#94a3b8; }
  .adv-toggle.open .adv-chev { transform:rotate(90deg); color:var(--cr); }
  .adv-toggle .adv-label { font-weight:700; }
  .adv-toggle .adv-hint  { font-size:.74rem; font-weight:500; color:#94a3b8; margin-left:auto; }
  .filters-advanced { max-height:0; opacity:0; overflow:hidden; transition:max-height .25s, opacity .15s, margin .15s; margin-top:0; }
  .filters-advanced.open { max-height:600px; opacity:1; margin-top:14px; }
  .filters-advanced-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; }

  /* Mobile — single column, fonts e touch targets ajustados */
  @media (max-width: 880px) {
    .filters-grid, .filters-advanced-grid { grid-template-columns:1fr; gap:16px; }
    .filters-panel.open { max-height:1400px; padding:16px 16px; }
    .filters-advanced.open { max-height:1000px; }
    .adv-toggle .adv-hint { display:none; }

    /* Top strip: quota quebra para baixo do título */
    .top-strip { flex-wrap:wrap; gap:8px; }
    .top-strip .quota { margin-left:0; width:100%; order:2; }

    /* Search bar: input ocupa linha inteira, botões na linha de baixo */
    .search-bar { flex-wrap:wrap; }
    .search-bar .input-wrap { flex-basis:100%; }
    .btn-filters { flex:1; justify-content:center; padding:11px 14px; }
    .btn-search  { flex:2; justify-content:center; padding:13px 16px; }

    /* Chips: scroll horizontal pra não quebrar a tela */
    .search-chips { grid-template-columns:1fr; gap:8px; }
    .strip-chips { flex-wrap:nowrap; overflow-x:auto; padding-bottom:6px; scrollbar-width:thin; -webkit-overflow-scrolling:touch; }
    .strip-chips::-webkit-scrollbar { height:4px; }
    .strip-chips::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:99px; }
    .strip-chips .chip { flex-shrink:0; }
    .strip-sort { justify-self:start; width:100%; }
    .strip-sort select { width:100%; }
  }
  @media (max-width: 640px) {
    .cnpj-page { padding:0 8px; }
    .filter-group label { font-size:.78rem; }
    .filter-group select,
    .filter-group input { font-size:.92rem; min-height:42px; padding:10px 12px; }
    .filters-panel.open { padding:14px; }
    .top-strip h1 { font-size:1rem; }
    .search-box { padding:12px; border-radius:12px; }
    .filters-panel-footer { flex-direction:column; align-items:stretch; gap:10px; }
    .filters-panel-footer .hint { text-align:center; font-size:.74rem; }
    .filters-panel-footer > div { width:100%; }
    .filters-panel-footer .btn-search,
    .filters-panel-footer .btn-secondary { flex:1; justify-content:center; }
  }
  .filter-group     { display:flex; flex-direction:column; gap:5px; }
  .filter-group label  { font-size:.7rem; color:#64748b; font-weight:600; letter-spacing:.02em; text-transform:uppercase; }
  .filter-group input,
  .filter-group select { padding:9px 12px; border:1px solid #e2e8f0; border-radius:8px; font-size:.86rem; background:#fff; transition:all .12s; color:#0f172a; font-family:inherit; min-height:38px; }
  .filter-group select:not(:disabled) { cursor:pointer; }
  .filter-group select:disabled { background:#f8fafc; color:#94a3b8; cursor:not-allowed; }
  .filter-group input:hover, .filter-group select:hover:not(:disabled) { border-color:#cbd5e1; }
  .filter-group input:focus, .filter-group select:focus { outline:none; border-color:var(--cr); box-shadow:0 0 0 3px rgba(16,185,129,.12); }
  .filter-group .field-hint { font-size:.7rem; color:#94a3b8; margin-top:2px; }

  /* Município input com botão X inline para limpar */
  .mun-input-wrap { position:relative; }
  .mun-input-wrap input { padding-right:32px !important; width:100%; }
  #mun-clear { position:absolute; right:8px; top:50%; transform:translateY(-50%); width:22px; height:22px; border-radius:50%; border:none; background:#e2e8f0; color:#475569; font-size:14px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center; padding:0; transition:all .15s; }
  #mun-clear:hover { background:#fca5a5; color:#fff; }
  .filters-panel-footer { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:18px; padding-top:16px; border-top:1px solid #f1f5f9; }
  .filters-panel-footer .hint { font-size:.78rem; color:#94a3b8; }

  /* Sticky search box */
  .search-box.sticky { position:sticky; top:0; z-index:90; box-shadow:0 4px 16px rgba(0,0,0,.08); border-radius:0 0 14px 14px; margin-bottom:0; }
  .search-box { transition:box-shadow .2s; }

  /* Filtros favoritos */
  .saved-filters { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-bottom:10px; padding:8px 14px; background:#fff; border:1px solid var(--line); border-radius:10px; }
  .saved-filters .sf-label { font-family:'Geist Mono',monospace; font-size:.62rem; color:var(--mute); text-transform:uppercase; letter-spacing:.06em; font-weight:600; margin-right:4px; flex-shrink:0; }
  .saved-filter-chip { display:inline-flex; align-items:center; gap:5px; padding:4px 12px; border:1px solid var(--line); border-radius:999px; font-size:.78rem; color:var(--ink-2); background:#fff; cursor:pointer; transition:all .12s; font-family:inherit; }
  .saved-filter-chip:hover { border-color:var(--cr); color:var(--cr); background:#f0fdf4; }
  .saved-filter-chip .sf-del { color:var(--mute); font-size:.7rem; padding:0 2px; cursor:pointer; opacity:0; transition:opacity .15s; }
  .saved-filter-chip:hover .sf-del { opacity:1; }
  .btn-secondary    { background:#fff; color:#6b7280; border:1px solid #e5e7eb; padding:9px 16px; border-radius:8px; cursor:pointer; font-size:.85rem; font-weight:500; text-decoration:none; display:inline-flex; align-items:center; }
  .btn-secondary:hover { background:#f9fafb; color:#374151; }

  /* Ordenação compacta na linha de chips */
  .sort-inline      { margin-left:auto; display:inline-flex; align-items:center; gap:6px; font-size:.78rem; color:#6b7280; }
  .sort-inline select { padding:5px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:.78rem; background:#fff; cursor:pointer; }

  /* Quick chips (checkbox-based, sem reload) */
  .chips            { display:flex; gap:6px; flex-wrap:wrap; }
  .chip-cb          { position:relative; cursor:pointer; user-select:none; }
  .chip-cb input    { position:absolute; opacity:0; pointer-events:none; width:0; height:0; }
  .chip-cb span     { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border:1px solid #e5e7eb; border-radius:999px; font-size:.8rem; color:#374151; background:#fff; transition:all .15s; }
  .chip-cb:hover span        { border-color:var(--cr); color:var(--cr); }
  .chip-cb input:checked + span { background:var(--cr); color:#fff; border-color:var(--cr); }
  /* Grupo de chips mutuamente exclusivos */
  .chip-group       { display:inline-flex; gap:2px; padding:2px; background:#f1f5f9; border-radius:999px; align-items:center; }
  .chip-group .chip-mutex { border:none; background:transparent; padding:4px 12px; font-size:.78rem; }
  .chip-group .chip-mutex.active { background:var(--cr); color:#fff; box-shadow:0 1px 3px rgba(16,185,129,.3); }
  .chip-group .chip-mutex:not(.active):hover { background:#fff; color:#4338ca; }

  .chip             { display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border:1px solid #e5e7eb; border-radius:999px; font-size:.8rem; font-weight:500; color:#374151; text-decoration:none; background:#fff; cursor:pointer; transition:all .12s; font-family:inherit; }
  .chip:hover       { border-color:var(--cr); color:var(--cr); background:#eef2ff; }
  .chip.active      { background:var(--cr); color:#fff; border-color:var(--cr); }
  .chip.active:hover{ background:#4f46e5; }
  .chip-remove      { display:inline-flex; align-items:center; gap:6px; padding:5px 10px; border:1px solid #a7f3d0; background:#eef2ff; border-radius:999px; font-size:.78rem; color:#4338ca; text-decoration:none; }
  .chip-remove:hover{ background:#e0e7ff; }
  .chip-remove span { font-weight:700; }

  /* ── Result cards (limpos) ── */
  .result-list      { display:flex; flex-direction:column; gap:10px; }
  .result-card      { background:#fff; border:1px solid #f1f5f9; border-radius:12px; padding:14px 16px; transition:all .12s; position:relative; }
  .result-card:hover{ border-color:#a7f3d0; box-shadow:0 4px 14px rgba(16,185,129,.08); transform:translateY(-1px); }
  .rc-select        { position:absolute; top:14px; right:14px; cursor:pointer; }
  .rc-select input  { width:17px; height:17px; cursor:pointer; accent-color:var(--cr); }
  .rc-main          { display:flex; gap:14px; align-items:flex-start; padding-right:24px; }
  .rc-avatar        { width:46px; height:46px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:1.15rem; }
  .rc-content       { flex:1; min-width:0; }
  .rc-name          { margin:0; font-size:1.02rem; font-weight:700; color:#0f172a; line-height:1.3; word-break:break-word; }
  .rc-sub           { font-size:.74rem; color:#94a3b8; margin-top:1px; }
  .rc-tags          { display:flex; gap:5px; flex-wrap:wrap; align-items:center; margin-top:7px; }
  .rc-cnpj          { font-family:'Geist Mono','SF Mono',ui-monospace,monospace; font-size:.76rem; color:#64748b; padding:1px 6px; background:#f8fafc; border-radius:5px; cursor:pointer; }
  .rc-cnpj:hover    { background:#eef2ff; color:#4338ca; }
  .rc-info          { display:flex; gap:14px; flex-wrap:wrap; margin-top:9px; font-size:.82rem; color:#475569; }
  .info-item        { display:inline-flex; align-items:center; gap:5px; padding:1px 3px; border-radius:4px; transition:background .12s; }
  .info-item.copyable { cursor:pointer; }
  .info-item.copyable:hover { background:#eef2ff; color:#4338ca; }
  .rc-actions       { display:flex; gap:6px; flex-shrink:0; }
  .rc-btn           { padding:7px 13px; font-size:.78rem; font-weight:600; border:1px solid #e2e8f0; background:#fff; color:#475569; border-radius:7px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; transition:all .12s; }
  .rc-btn:hover     { border-color:#a7f3d0; color:#4338ca; background:#eef2ff; }
  .rc-btn-primary   { background:var(--cr); color:#fff; border-color:var(--cr); }
  .rc-btn-primary:hover { background:#0ea371; color:#fff; }
  .rc-btn-wa        { background:#22c55e; color:#fff; border-color:#22c55e; }
  .rc-btn-wa:hover  { background:#16a34a; color:#fff; }
  /* Mail Lab — botão de e-mail nos cards */
  .rc-btn-mail      { background:#0d9488; color:#fff; border-color:#0d9488; }
  .rc-btn-mail:hover { background:#0f766e; color:#fff; }
  .fav-btn.on       { background:#fef3c7 !important; color:#a16207 !important; border-color:#fcd34d !important; }

  /* legado — mantém pra drawer/etc */
  .result-card      { background:#fff; border-radius:12px; padding:14px 16px; box-shadow:0 1px 4px rgba(0,0,0,.06); transition:box-shadow .15s, transform .1s; border:1px solid transparent; }
  .result-card:hover{ box-shadow:0 4px 16px rgba(0,0,0,.08); border-color:#e0e7ff; }
  .rc-top           { display:flex; gap:12px; align-items:flex-start; }
  .rc-avatar        { width:42px; height:42px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:1.1rem; }
  .rc-body          { flex:1; min-width:0; }
  .rc-head          { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:2px; }
  .rc-cnpj          { font-family:'Geist Mono','SF Mono',Consolas,monospace; font-size:.82rem; color:#6b7280; }
  .rc-razao         { font-size:1rem; font-weight:600; color:#111827; margin:2px 0 1px; line-height:1.3; }
  .rc-fantasia      { font-size:.82rem; color:#6b7280; }
  .rc-meta          { display:flex; gap:14px; flex-wrap:wrap; margin-top:8px; font-size:.82rem; color:#4b5563; }
  .rc-meta span     { display:inline-flex; align-items:center; gap:5px; }
  .rc-meta svg      { width:14px; height:14px; color:#9ca3af; flex-shrink:0; }
  .rc-actions       { display:flex; gap:6px; opacity:0; transition:opacity .15s; flex-shrink:0; }
  .result-card:hover .rc-actions { opacity:1; }
  .rc-actions button{ background:#f3f4f6; border:none; padding:6px 10px; border-radius:6px; cursor:pointer; font-size:.75rem; color:#374151; display:inline-flex; align-items:center; gap:4px; }
  .rc-actions button:hover { background:#e0e7ff; color:var(--cr); }

  /* Newton Score */
  .newton-score     { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:6px; font-size:.74rem; font-weight:700; white-space:nowrap; }
  .newton-score.score-hot   { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; }
  .newton-score.score-warm  { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; }
  .newton-score.score-cool  { background:#dbeafe; color:#1e40af; border:1px solid #93c5fd; }
  .newton-score.score-cold  { background:#f3f4f6; color:#6b7280; border:1px solid #d1d5db; }

  /* Setor badge */
  .badge-setor      { display:inline-flex; align-items:center; gap:4px; padding:2px 9px; border-radius:999px; font-size:.7rem; font-weight:600; white-space:nowrap; }
  /* Categoria de idade */
  .badge.age-novo   { background:#fee2e2; color:#b91c1c; }
  .badge.age-jovem  { background:#fef3c7; color:#92400e; }
  .badge.age-estab  { background:#dcfce7; color:#15803d; }
  .badge.age-tradic { background:#dbeafe; color:#1e40af; }
  /* Bulk select checkbox no canto */
  .rc-select        { position:absolute; top:12px; right:12px; cursor:pointer; z-index:5; }
  .rc-select input  { width:18px; height:18px; cursor:pointer; accent-color:var(--cr); }
  .result-card      { position:relative; }
  /* Copy on click */
  .copyable         { cursor:pointer; transition:background .15s; border-radius:4px; padding:1px 4px; }
  .copyable:hover   { background:#eef2ff; color:#4338ca !important; }
  .copyable.copied  { background:#dcfce7; color:#15803d !important; }
  .copyable.copied::after { content:' ✓'; }
  /* Favorite button */
  .fav-btn          { background:transparent !important; }
  .fav-btn.on       { background:#fef3c7 !important; color:#a16207 !important; }
  /* Bulk action bar (aparece ao selecionar) */
  #bulk-bar         { position:fixed; bottom:18px; left:50%; transform:translateX(-50%) translateY(120px); background:#111827; color:#fff; padding:10px 16px; border-radius:12px; box-shadow:0 10px 40px rgba(0,0,0,.3); display:flex; gap:10px; align-items:center; z-index:80; transition:transform .25s; font-size:.85rem; }
  #bulk-bar.show    { transform:translateX(-50%) translateY(0); }
  #bulk-bar button  { background:#374151; color:#fff; border:none; padding:7px 14px; border-radius:7px; cursor:pointer; font-size:.82rem; font-weight:500; }
  #bulk-bar button:hover { background:#4b5563; }
  #bulk-bar button.primary { background:var(--cr); }
  #bulk-bar button.primary:hover { background:#4f46e5; }
  #bulk-bar .count  { background:var(--cr); padding:3px 10px; border-radius:999px; font-weight:700; }

  /* Links externos abaixo dos meta */
  .rc-links         { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; padding-top:8px; border-top:1px dashed #f3f4f6; }
  .rc-links a       { font-size:.74rem; padding:3px 9px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; color:#4b5563; text-decoration:none; transition:all .12s; }
  .rc-links a:hover { background:#eef2ff; border-color:#a7f3d0; color:#4338ca; }

  /* Badges */
  .badge            { display:inline-block; padding:2px 9px; border-radius:999px; font-size:.7rem; font-weight:600; white-space:nowrap; }
  .badge-green      { background:#d1fae5; color:#065f46; }
  .badge-red        { background:#fee2e2; color:#991b1b; }
  .badge-gray       { background:#f3f4f6; color:#374151; }
  .badge-amber      { background:#fef3c7; color:#92400e; }
  .badge-blue       { background:#dbeafe; color:#1e40af; }
  .badge-purple     { background:#ede9fe; color:#6d28d9; }

  /* Pagination */
  .pagination       { display:flex; gap:5px; align-items:center; padding:14px 0; flex-wrap:wrap; justify-content:center; }
  .pagination a,
  .pagination span  { padding:6px 11px; border-radius:6px; font-size:.85rem; text-decoration:none; border:1px solid #e5e7eb; background:#fff; }
  .pagination a     { color:var(--cr); }
  .pagination a:hover { background:#eef2ff; }
  .pagination span.current { background:var(--cr); color:#fff; border-color:var(--cr); }

  /* Results header */
  .results-header   { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; padding:0 4px; gap:10px; flex-wrap:wrap; }
  .results-count    { font-size:.88rem; color:#475569; }
  .results-count b  { color:#0f172a; font-size:1.05rem; }
  .results-actions  { display:flex; gap:6px; flex-wrap:wrap; }
  .rh-btn           { padding:7px 12px; font-size:.78rem; font-weight:600; border:1px solid #e2e8f0; background:#fff; color:#475569; border-radius:7px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:5px; transition:all .12s; }
  .rh-btn:hover     { border-color:#a7f3d0; color:#4338ca; background:#eef2ff; }
  .rh-btn-primary   { background:#22c55e; color:#fff; border-color:#22c55e; }
  .rh-btn-primary:hover { background:#16a34a; color:#fff; border-color:#16a34a; }

  /* Export */
  .export-btn       { display:inline-flex; align-items:center; gap:6px; background:#22c55e; color:#fff; border:none; border-radius:8px; padding:7px 14px; font-size:.82rem; cursor:pointer; text-decoration:none; font-weight:500; }
  .export-btn:hover { background:#16a34a; }

  /* Empty / no-search */
  .empty-state      { background:#fff; border-radius:14px; padding:60px 24px; text-align:center; color:#6b7280; box-shadow:0 1px 4px rgba(0,0,0,.06); }
  .empty-state h2   { color:#111827; margin:14px 0 6px; font-weight:600; }
  .empty-state .examples { margin-top:18px; display:flex; gap:8px; flex-wrap:wrap; justify-content:center; }
  .empty-state .examples a { background:#f3f4f6; padding:6px 14px; border-radius:999px; color:#374151; text-decoration:none; font-size:.85rem; }
  .empty-state .examples a:hover { background:var(--cr); color:#fff; }

  /* Modal & drawer */
  .modal-bg         { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:100; align-items:center; justify-content:center; }
  .modal-bg.open    { display:flex; }
  .modal            { background:#fff; border-radius:14px; padding:28px; max-width:420px; width:90%; box-shadow:0 10px 40px rgba(0,0,0,.2); }
  .modal h3         { margin:0 0 16px; }
  .modal input, .modal textarea { width:100%; box-sizing:border-box; padding:8px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:.9rem; margin-bottom:10px; }
  .modal-actions    { display:flex; gap:10px; justify-content:flex-end; margin-top:6px; }
  .btn-cancel       { background:#f3f4f6; color:#374151; border:none; border-radius:8px; padding:8px 16px; cursor:pointer; }

  /* Drawer */
  .drawer-bg        { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:90; }
  .drawer-bg.open   { display:block; }
  .drawer           { position:fixed; right:0; top:0; bottom:0; width:560px; max-width:95vw; background:#fff; box-shadow:-4px 0 24px rgba(0,0,0,.15); z-index:91; transform:translateX(100%); transition:transform .25s; display:flex; flex-direction:column; }
  .drawer.open      { transform:translateX(0); }
  .drawer-head      { padding:18px 20px; border-bottom:1px solid #f3f4f6; display:flex; justify-content:space-between; align-items:center; }
  .drawer-head h2   { margin:0; font-size:1.05rem; }
  .drawer-close     { background:none; border:none; font-size:1.5rem; cursor:pointer; color:#6b7280; line-height:1; padding:0 6px; }
  .drawer-body      { flex:1; overflow-y:auto; padding:20px; }
  .drawer-body h4   { font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; margin:18px 0 8px; font-weight:600; }
  .drawer-body h4:first-child { margin-top:0; }
  .dt-grid          { display:grid; grid-template-columns:140px 1fr; gap:6px 14px; font-size:.88rem; }
  .dt-grid dt       { color:#6b7280; }
  .dt-grid dd       { margin:0; color:#111827; }
  .socio-row        { padding:10px 0; border-bottom:1px solid #f3f4f6; font-size:.88rem; }
  .socio-row:last-child { border-bottom:none; }
  .socio-row strong { display:block; color:#111827; }
  .socio-row small  { color:#6b7280; }

  /* Drawer — qualification card */
  .qualify-card     { display:flex; gap:14px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:14px; margin-bottom:14px; }
  .qualify-score    { flex-shrink:0; width:90px; border-radius:10px; padding:10px 8px; text-align:center; display:flex; flex-direction:column; justify-content:center; }
  .qualify-score-hot   { background:#fee2e2; color:#b91c1c; }
  .qualify-score-warm  { background:#fef3c7; color:#92400e; }
  .qualify-score-cool  { background:#dbeafe; color:#1e40af; }
  .qualify-score-cold  { background:#f3f4f6; color:#6b7280; }
  .qs-num           { font-size:1.8rem; font-weight:800; line-height:1; }
  .qs-lbl           { font-size:.68rem; margin-top:6px; font-weight:600; }
  .qs-lbl small     { display:block; opacity:.8; margin-top:2px; font-weight:500; }
  .qualify-info     { flex:1; display:flex; flex-direction:column; gap:8px; }
  .qualify-info > div { display:flex; flex-direction:column; }
  .qi-lbl           { font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; font-weight:600; }
  .qualify-info strong { font-size:.92rem; color:#111827; }
  .qualify-info small  { font-size:.72rem; color:#9ca3af; }

  /* Newton Score Breakdown — explica POR QUE esse score */
  .score-breakdown        { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px; margin-bottom:14px; }
  .sb-header              { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; padding-bottom:8px; border-bottom:1px solid #f1f5f9; }
  .sb-header h4           { margin:0; font-size:.92rem; font-weight:700; color:#111827; }
  .sb-header .sb-total    { font-size:.78rem; color:#6b7280; font-weight:600; }
  .sb-cats                { display:flex; flex-direction:column; gap:10px; }
  .sb-cat                 { background:#f9fafb; border-radius:8px; padding:10px 12px; }
  .sb-cat-header          { display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; }
  .sb-cat-title           { font-size:.78rem; font-weight:700; color:#374151; display:flex; align-items:center; gap:6px; }
  .sb-cat-pts             { font-size:.78rem; font-weight:700; color:#6b7280; }
  .sb-cat-pts strong      { color:#059669; }
  .sb-cat-pts.dim strong  { color:#9ca3af; }
  .sb-bar                 { height:5px; background:#e5e7eb; border-radius:99px; overflow:hidden; margin-bottom:8px; }
  .sb-bar-fill            { height:100%; background:linear-gradient(90deg, #22c55e, #16a34a); border-radius:99px; transition:width .3s; }
  .sb-signals             { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:3px; }
  .sb-signal              { display:flex; align-items:center; justify-content:space-between; font-size:.78rem; padding:3px 0; }
  .sb-signal.hit          { color:#1f2937; }
  .sb-signal.miss         { color:#9ca3af; }
  .sb-signal-pts          { font-weight:600; font-size:.72rem; padding:1px 6px; border-radius:99px; }
  .sb-signal.hit .sb-signal-pts  { background:#dcfce7; color:#166534; }
  .sb-signal.miss .sb-signal-pts { background:#f3f4f6; color:#9ca3af; }
  .sb-redflags            { margin-top:10px; padding:8px 10px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; }
  .sb-redflags-title      { font-size:.74rem; font-weight:700; color:#b91c1c; margin-bottom:4px; }
  .sb-redflag             { font-size:.76rem; color:#991b1b; display:flex; justify-content:space-between; padding:2px 0; }
  .sb-redflag-pts         { font-weight:700; }
  .links-bar        { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
  .links-bar a      { font-size:.78rem; padding:5px 11px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; color:#4b5563; text-decoration:none; }
  .links-bar a:hover{ background:#eef2ff; color:#4338ca; border-color:#a7f3d0; }

  /* Sinais / produtos */
  .btn-signals      { width:100%; background:linear-gradient(135deg,#0891b2,#0d9488); color:#fff; border:none; border-radius:10px; padding:14px; font-size:.92rem; font-weight:600; cursor:pointer; display:flex; flex-direction:column; align-items:center; gap:3px; margin-bottom:10px; transition:opacity .15s; }
  .btn-signals:hover{ opacity:.92; }
  .btn-signals:disabled { opacity:.7; cursor:wait; }
  .btn-signals small { font-weight:400; opacity:.85; font-size:.72rem; }
  .prod-grid        { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:8px; margin-bottom:14px; }
  .prod-card        { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:10px 12px; }
  .prod-card.fit    { background:#ecfdf5; border-color:#86efac; }
  .prod-card.nofit  { opacity:.65; }
  .prod-head        { display:flex; align-items:center; gap:6px; margin-bottom:4px; }
  .prod-emoji       { font-size:1.1rem; }
  .prod-status      { margin-left:auto; font-size:.65rem; font-weight:700; }
  .prod-card.fit  .prod-status { color:#15803d; }
  .prod-card.nofit .prod-status { color:#9ca3af; }
  .prod-card small  { font-size:.72rem; color:#6b7280; }
  .prod-card ul     { margin:6px 0 0 0; padding-left:18px; font-size:.78rem; color:#374151; }
  .prod-card ul li  { margin-bottom:2px; }

  /* Enrichment */
  .btn-enrich       { width:100%; background:linear-gradient(135deg,var(--cr),#8b5cf6); color:#fff; border:none; border-radius:10px; padding:14px; font-size:.95rem; font-weight:600; cursor:pointer; display:flex; flex-direction:column; align-items:center; gap:3px; margin-bottom:14px; transition:opacity .15s; }
  .btn-enrich:hover { opacity:.92; }
  .btn-enrich:disabled { opacity:.7; cursor:wait; }
  .btn-enrich small { font-weight:400; opacity:.85; font-size:.74rem; }
  .enrich-block     { background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; margin-bottom:10px; }
  .enrich-src       { font-size:.72rem; font-weight:700; text-transform:uppercase; color:#4338ca; letter-spacing:.04em; margin-bottom:8px; }
  .enrich-src small { font-weight:500; color:#9ca3af; text-transform:none; letter-spacing:0; margin-left:6px; }
  .enrich-empty     { background:#fef3c7; border:1px solid #fcd34d; border-radius:10px; padding:10px 14px; font-size:.85rem; color:#92400e; margin-bottom:10px; }
  .enrich-empty code { background:#fde68a; padding:1px 6px; border-radius:4px; font-size:.85em; }

  /* Município autocomplete */
  .mun-suggestions { position:absolute; top:100%; left:0; right:0; z-index:50; background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 6px 18px rgba(0,0,0,.1); max-height:280px; overflow-y:auto; margin-top:4px; }
  .mun-suggest-item { padding:10px 12px; cursor:pointer; font-size:.88rem; color:#0f172a; border-bottom:1px solid #f1f5f9; transition:background .12s; }
  .mun-suggest-item:hover, .mun-suggest-item.active { background:#eef2ff; color:#4338ca; }
  .mun-suggest-item:last-child { border-bottom:none; }
  .mun-suggest-loading, .mun-suggest-empty { padding:14px; font-size:.85rem; color:#94a3b8; text-align:center; }


  /* CNAE autocomplete */
  #cnae-suggestions { position:absolute; top:2px; left:0; right:0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,.1); z-index:50; max-height:240px; overflow-y:auto; }
  #cnae-suggestions div { padding:8px 12px; cursor:pointer; font-size:.82rem; border-bottom:1px solid #f3f4f6; }
  #cnae-suggestions div:hover { background:#f0f4ff; }
  #cnae-suggestions div small { color:#6b7280; display:block; }

  /* Filter section toggle */
  .filter-section-title { display:flex; justify-content:space-between; align-items:center; cursor:pointer; padding:8px 0; font-size:.78rem; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.04em; border-top:1px solid #f3f4f6; margin-top:8px; }
  .filter-section-title:first-of-type { border-top:none; margin-top:0; padding-top:0; }
  .filter-section-title.collapsed + .filter-section-body { display:none; }
  .filter-section-title svg { transition:transform .15s; }
  .filter-section-title.collapsed svg { transform:rotate(-90deg); }

  /* Loading state */
  .skeleton         { background:linear-gradient(90deg,#f3f4f6 0%,#e5e7eb 50%,#f3f4f6 100%); background-size:200% 100%; animation:sk 1.4s infinite; border-radius:8px; }
  @keyframes sk { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
  /* Skeleton cards */
  .skeleton-card    { background:#fff; border-radius:12px; padding:14px 16px; margin-bottom:10px; box-shadow:0 1px 3px rgba(0,0,0,.04); display:flex; gap:14px; align-items:flex-start; }
  .sk-avatar        { width:46px; height:46px; border-radius:10px; background:linear-gradient(90deg,#f1f5f9 0%,#e2e8f0 50%,#f1f5f9 100%); background-size:200% 100%; animation:sk 1.4s infinite; flex-shrink:0; }
  .sk-body          { flex:1; display:flex; flex-direction:column; gap:8px; }
  .sk-line          { height:12px; border-radius:5px; background:linear-gradient(90deg,#f1f5f9 0%,#e2e8f0 50%,#f1f5f9 100%); background-size:200% 100%; animation:sk 1.4s infinite; }
  .sk-line-name     { width:50%; height:18px; }
  .sk-line-meta     { width:30%; }
  .sk-line-info     { width:80%; }

  /* Empty state PREMIUM */
  .empty-state-pro     { background:#fff; border-radius:14px; padding:48px 32px; text-align:center; box-shadow:0 1px 4px rgba(0,0,0,.06); animation:fadeUp .3s ease; }
  .empty-state-pro .empty-icon { font-size:3rem; margin-bottom:14px; display:inline-block; animation:floatY 2.4s ease-in-out infinite; }
  .empty-state-pro h3  { margin:0 0 6px; color:#0f172a; font-size:1.1rem; font-weight:700; }
  .empty-state-pro .empty-subtitle { margin:0 0 24px; color:#64748b; font-size:.9rem; }
  .diag-found          { background:#dcfce7; border:1px solid #86efac; border-radius:12px; padding:18px 20px; max-width:560px; margin:0 auto 14px; }
  .diag-label          { font-size:.78rem; color:#166534; margin:0 0 6px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
  .diag-text           { font-size:.95rem; color:#14532d; margin:0; line-height:1.5; }
  .diag-found .suggestion-pill { background:#fff; border-color:#22c55e; color:#15803d; }
  .diag-found .suggestion-pill .x-icon { background:#15803d; }
  .diag-found .suggestion-pill:hover { background:#f0fdf4; }

  .empty-suggestions   { background:#fffbeb; border:1px dashed #fcd34d; border-radius:12px; padding:16px 18px; max-width:560px; margin:0 auto; }
  .empty-suggestions-label { font-size:.85rem; color:#92400e; margin:0 0 10px; font-weight:600; }
  .suggestions-grid    { display:flex; gap:8px; flex-wrap:wrap; justify-content:center; }
  .suggestion-pill     { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; background:#fff; border:1px solid #fcd34d; border-radius:999px; color:#92400e; text-decoration:none; font-size:.82rem; font-weight:500; transition:all .15s; }
  .suggestion-pill:hover { background:#fef3c7; border-color:#f59e0b; transform:translateY(-1px); }
  .suggestion-pill .x-icon { background:#92400e; color:#fff; width:18px; height:18px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700; }

  @keyframes fadeUp  { from {opacity:0; transform:translateY(8px)} to {opacity:1; transform:translateY(0)} }
  @keyframes floatY  { 0%,100% {transform:translateY(0)} 50% {transform:translateY(-6px)} }

  /* Card hover suave + entrada animada */
  .result-card { animation:fadeUp .25s ease backwards; }
  .result-card:nth-child(1) { animation-delay: 0ms; }
  .result-card:nth-child(2) { animation-delay: 30ms; }
  .result-card:nth-child(3) { animation-delay: 60ms; }
  .result-card:nth-child(4) { animation-delay: 90ms; }
  .result-card:nth-child(5) { animation-delay: 120ms; }
  .result-card:nth-child(6) { animation-delay: 150ms; }
  .result-card:nth-child(7) { animation-delay: 180ms; }
  .result-card:nth-child(n+8) { animation-delay: 210ms; }
</style>
</head>
<body>
<div class="cnpj-page">

<?php if ($q_alert_banner === 100): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:14px 18px;margin-bottom:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <span style="font-size:1.4rem">🚫</span>
  <div style="flex:1;min-width:180px">
    <div style="font-weight:700;color:#991b1b;font-size:.96rem">Cota de extrações esgotada este mês</div>
    <div style="font-size:.82rem;color:#b91c1c;margin-top:2px">Você usou <?= number_format($q_used, 0, ',', '.') ?> de <?= number_format($q_limit, 0, ',', '.') ?> extrações. A cota renova no 1º do próximo mês.</div>
  </div>
  <a href="billing.php" style="background:#dc2626;color:#fff;padding:9px 18px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.86rem;white-space:nowrap">Comprar Lead Pack →</a>
</div>
<?php elseif ($q_alert_banner === 90): ?>
<div style="background:#fffbeb;border:1px solid #fbbf24;border-radius:12px;padding:14px 18px;margin-bottom:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <span style="font-size:1.4rem">⚠️</span>
  <div style="flex:1;min-width:180px">
    <div style="font-weight:700;color:#92400e;font-size:.96rem">90% da cota Radar usada</div>
    <div style="font-size:.82rem;color:#b45309;margin-top:2px">Restam <?= number_format(max(0, $q_limit - $q_used), 0, ',', '.') ?> extrações neste mês. Considere um Lead Pack para não parar a prospecção.</div>
  </div>
  <a href="billing.php" style="background:#d97706;color:#fff;padding:9px 18px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.86rem;white-space:nowrap">Ver Lead Packs →</a>
</div>
<?php elseif ($q_alert_banner === 80): ?>
<div style="background:#fefce8;border:1px solid #fde68a;border-radius:12px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <span style="font-size:1.3rem">📊</span>
  <div style="flex:1;min-width:180px">
    <div style="font-weight:600;color:#78350f;font-size:.92rem">80% da cota Radar utilizada — bom ritmo de prospecção!</div>
    <div style="font-size:.8rem;color:#92400e;margin-top:2px">Cota renova em <?= date('d/m', strtotime('first day of next month')) ?>. Lead Packs disponíveis se precisar de mais.</div>
  </div>
  <a href="billing.php" style="background:#ca8a04;color:#fff;padding:8px 14px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.82rem;white-space:nowrap">Lead Packs →</a>
</div>
<?php endif; ?>

<!-- Top strip: título + quota mini + link listas -->
<div class="top-strip">
  <h1>🎯 Prospecção</h1>
  <div class="quota">
    <span><b><?= cnpj_nfmt_br($q_used) ?></b> / <?= cnpj_nfmt_br($q_limit) ?> leads</span>
    <div class="bar"><i class="<?= $q_bar_class ?>" style="width:<?= $q_pct ?>%"></i></div>
    <span><?= $q_pct ?>%</span>
    <?php if ($q_addon > 0): ?><span>· +<?= cnpj_nfmt_br($q_addon) ?> extras</span><?php endif; ?>
  </div>
  <a href="cnpj-lists.php">Minhas listas →</a>
</div>

<?php
// Conta quantos filtros estão ativos (pra badge no botão Filtros)
$filter_keys = ['vertical','sub_vertical','uf','municipio','cnae','porte','situacao','capital_min','idade_min','abertura_de','abertura_ate'];
$active_n = 0;
foreach ($filter_keys as $k) if (!empty($f[$k])) $active_n++;
$open_filters = $active_n > 0;
?>
<form method="get" id="cnpj-form">
<input type="hidden" name="sort" id="sort-input" value="<?= e($sort) ?>">
<input type="hidden" name="submitted" value="1">

<!-- BUSCA: input + filtros + chips em UM card visual -->
<div class="search-box">
  <div class="search-bar">
    <div class="input-wrap">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <input type="text" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Busque por CNPJ, razão social ou nome fantasia…">
    </div>
    <button type="button" class="btn-filters" onclick="toggleFiltersPanel()">
      ⚙ Filtros <?php if ($active_n): ?><span class="count"><?= $active_n ?></span><?php endif; ?>
    </button>
    <button type="submit" class="btn-search" id="btn-buscar">🔍 Buscar</button>
  </div>

  <!-- Chips integrados — logo abaixo do input (mesmo card) -->
  <div class="search-chips">
    <div class="strip-chips">
      <button type="button" class="chip <?= (empty($f['situacao']) || ($f['situacao']??'')==='02') ? 'active' : '' ?>" onclick="toggleAtivasChip(this)" title="Empresas ativas (recomendado)">Ativas</button>
      <button type="button" class="chip <?= !empty($f['tem_email'])?'active':'' ?>" onclick="toggleChip('tem_email','1',this)" title="Apenas com e-mail">Com e-mail</button>
      <button type="button" class="chip <?= !empty($f['tem_tel'])?'active':'' ?>" onclick="toggleChip('tem_tel','1',this)" title="Apenas com telefone">Com telefone</button>
      <span class="chip-group" title="Mutuamente exclusivos">
        <button type="button" class="chip chip-mutex <?= !empty($f['sem_mei'])?'active':'' ?>" onclick="toggleChip('sem_mei','1',this,'mei')" title="Exclui MEI">Sem MEI</button>
        <button type="button" class="chip chip-mutex <?= !empty($f['mei'])?'active':'' ?>" onclick="toggleChip('mei','1',this,'sem_mei')" title="Apenas MEI">Apenas MEI</button>
      </span>
      <button type="button" class="chip <?= !empty($f['simples'])?'active':'' ?>" onclick="toggleChip('simples','1',this)" title="Optantes Simples">Simples</button>
      <span class="chip-group" title="Mutuamente exclusivos">
        <button type="button" class="chip chip-mutex chip-mf-1 <?= ($f['mf']??'')==='1'?'active':'' ?>" onclick="toggleMfChip('1',this)" title="Apenas matrizes">Matriz</button>
        <button type="button" class="chip chip-mutex chip-mf-2 <?= ($f['mf']??'')==='2'?'active':'' ?>" onclick="toggleMfChip('2',this)" title="Apenas filiais">Filial</button>
      </span>
    </div>
    <div class="strip-sort">
      <select id="sort-sel" onchange="document.getElementById('sort-input').value=this.value; scheduleSearch();" title="Ordem dos resultados">
        <option value="qualified"    <?= $sort==='qualified'?'selected':'' ?>>🎯 Radar Score</option>
        <option value="capital_desc" <?= $sort==='capital_desc'?'selected':'' ?>>💰 Maior capital</option>
        <option value="recentes"     <?= $sort==='recentes'?'selected':'' ?>>🆕 Mais recentes</option>
        <option value="antigas"      <?= $sort==='antigas'?'selected':'' ?>>🏛 Mais antigas</option>
        <option value="razao"        <?= $sort==='razao'?'selected':'' ?>>🔤 A-Z</option>
      </select>
    </div>
  </div>
</div>

<!-- Filtros favoritos salvos (renderizado via JS do localStorage) -->
<div id="saved-filters-bar" class="saved-filters" style="display:none">
  <span class="sf-label">🔖 Favoritos:</span>
  <!-- chips injetados via JS -->
</div>

<!-- PAINEL de filtros agrupados (colapsa por padrão) -->
<div class="filters-panel <?= $open_filters ? 'open' : '' ?>" id="filters-panel">
  <div class="filters-grid">

    <section>
      <h4>🎯 Mercado <span class="tag-rec">RECOMENDADO</span></h4>
      <div class="filter-group">
        <label>Vertical de negócio</label>
        <select name="vertical" id="vertical-sel">
          <option value="">Todas as verticais</option>
          <?php foreach (cnpj_verticais_list() as $v): ?>
            <option value="<?= e($v['vertical_id']) ?>" <?= ($f['vertical'] ?? '') === $v['vertical_id'] ? 'selected' : '' ?>>
              <?= e($v['vertical_nome']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="field-hint">Categorias B2B curadas (27 verticais)</div>
      </div>
      <div class="filter-group">
        <label>Atividade específica (CNAE)</label>
        <select name="sub_vertical" id="subvert-sel" <?= empty($f['vertical']) ? 'disabled' : '' ?>>
          <?php if (empty($f['vertical'])): ?>
            <option value="">Selecione uma vertical primeiro</option>
          <?php else:
                  $subs = cnpj_subverticais_list($f['vertical']);
          ?>
            <option value="">Todas as atividades (<?= count($subs) ?>)</option>
            <?php foreach ($subs as $sv): ?>
              <option value="<?= e($sv['sub_vertical']) ?>" <?= ($f['sub_vertical'] ?? '') === $sv['sub_vertical'] ? 'selected' : '' ?>>
                <?= e($sv['sub_vertical']) ?>
              </option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>
      <div class="filter-group" style="position:relative">
        <label>CNAE específico (avançado)</label>
        <input type="text" id="cnae-input" name="cnae" value="<?= e($f['cnae'] ?? '') ?>" placeholder="Código ou nome…" autocomplete="off">
        <div id="cnae-list" style="position:relative"></div>
      </div>
    </section>

    <section>
      <h4>📍 Localização</h4>
      <div class="filter-group">
        <label>UF</label>
        <select name="uf" id="uf-sel" onchange="onUfChange()">
          <option value="">Todas</option>
          <?php foreach (CNPJ_UFS as $uf): ?>
          <option value="<?= $uf ?>" <?= ($f['uf'] ?? '') === $uf ? 'selected' : '' ?>><?= $uf ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group" style="position:relative">
        <label>Município (digite o nome)</label>
        <div class="mun-input-wrap">
          <input type="text" id="mun-input"
                 placeholder="<?= empty($f['uf']) ? 'Selecione UF primeiro' : 'Ex: Santo André' ?>"
                 value="<?php
                    if (!empty($f['municipio'])) {
                        try {
                            $nm = cnpj_val('SELECT descricao FROM rf_municipios WHERE TRIM(codigo::text) = ?', [trim($f['municipio'])]);
                            echo e($nm ?: $f['municipio']);
                        } catch (\Throwable $e) { echo e($f['municipio']); }
                    }
                 ?>"
                 <?= empty($f['uf']) ? 'disabled' : '' ?>
                 autocomplete="off">
          <button type="button" id="mun-clear" title="Limpar cidade"
                  style="<?= empty($f['municipio']) ? 'display:none' : '' ?>"
                  onclick="clearMunSelection()">×</button>
        </div>
        <input type="hidden" name="municipio" id="mun-code" value="<?= e($f['municipio'] ?? '') ?>">
        <div id="mun-suggestions" class="mun-suggestions" style="display:none"></div>
      </div>
    </section>

  </div>

  <?php
    // Se algum filtro avançado está ativo, abre por padrão
    $adv_active = !empty($f['porte']) || !empty($f['situacao']) || !empty($f['capital_min'])
               || !empty($f['idade_min']) || !empty($f['abertura_de']) || !empty($f['abertura_ate']);
  ?>
  <button type="button" class="adv-toggle <?= $adv_active ? 'open' : '' ?>" onclick="toggleAdvanced(this)">
    <span class="adv-chev">▸</span>
    <span class="adv-label">Busca avançada</span>
    <span class="adv-hint">— Porte, Situação, Capital, Idade, Datas</span>
  </button>

  <div class="filters-advanced <?= $adv_active ? 'open' : '' ?>" id="filters-advanced">
    <div class="filters-advanced-grid">
      <section>
        <h4>🏢 Empresa</h4>
        <div class="filter-group">
          <label>Porte</label>
          <select name="porte">
            <option value="">Todos</option>
            <?php foreach (CNPJ_PORTES as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($f['porte'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <label>Situação</label>
          <select name="situacao">
            <option value="">Todas</option>
            <?php foreach (CNPJ_SITUACOES as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($f['situacao'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <label>Capital mínimo</label>
          <select name="capital_min">
            <option value="">Qualquer</option>
            <option value="10000"     <?= ($f['capital_min']??'')==='10000'?'selected':'' ?>>≥ R$ 10 mil</option>
            <option value="100000"    <?= ($f['capital_min']??'')==='100000'?'selected':'' ?>>≥ R$ 100 mil</option>
            <option value="1000000"   <?= ($f['capital_min']??'')==='1000000'?'selected':'' ?>>≥ R$ 1 milhão</option>
            <option value="10000000"  <?= ($f['capital_min']??'')==='10000000'?'selected':'' ?>>≥ R$ 10 milhões</option>
            <option value="100000000" <?= ($f['capital_min']??'')==='100000000'?'selected':'' ?>>≥ R$ 100 milhões</option>
          </select>
        </div>
      </section>

      <section>
        <h4>📅 Tempo</h4>
        <div class="filter-group">
          <label>Idade mínima</label>
          <select name="idade_min">
            <option value="">Qualquer</option>
            <option value="1"  <?= ($f['idade_min']??'')==='1' ?'selected':'' ?>>≥ 1 ano</option>
            <option value="2"  <?= ($f['idade_min']??'')==='2' ?'selected':'' ?>>≥ 2 anos</option>
            <option value="5"  <?= ($f['idade_min']??'')==='5' ?'selected':'' ?>>≥ 5 anos</option>
            <option value="10" <?= ($f['idade_min']??'')==='10'?'selected':'' ?>>≥ 10 anos</option>
          </select>
        </div>
        <div class="filter-group">
          <label>Abertura de</label>
          <input type="date" name="abertura_de" value="<?= e($f['abertura_de'] ?? '') ?>">
        </div>
        <div class="filter-group">
          <label>Abertura até</label>
          <input type="date" name="abertura_ate" value="<?= e($f['abertura_ate'] ?? '') ?>">
        </div>
      </section>
    </div>
  </div>
  <div class="filters-panel-footer">
    <span class="hint">Use <strong>Vertical</strong> + <strong>UF</strong> pra resultados focados e rápidos</span>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button type="button" class="btn-secondary" onclick="saveCurrentFilter()" title="Salvar esta combinação de filtros para uso rápido">
        🔖 Salvar filtro
      </button>
      <a href="cnpj.php?noauto=1" class="btn-secondary">Limpar tudo</a>
      <button type="submit" class="btn-search">Aplicar e buscar</button>
    </div>
  </div>
</div>

<!-- Hidden inputs pros chips (mantêm estado do form) -->
<input type="hidden" id="hf-tem_email" name="tem_email" value="<?= !empty($f['tem_email']) ? '1' : '' ?>">
<input type="hidden" id="hf-tem_tel"   name="tem_tel"   value="<?= !empty($f['tem_tel'])   ? '1' : '' ?>">
<input type="hidden" id="hf-mei"       name="mei"       value="<?= !empty($f['mei'])       ? '1' : '' ?>">
<input type="hidden" id="hf-sem_mei"   name="sem_mei"   value="<?= !empty($f['sem_mei'])   ? '1' : '' ?>">
<input type="hidden" id="hf-simples"   name="simples"   value="<?= !empty($f['simples'])   ? '1' : '' ?>">
<input type="hidden" id="hf-mf"        name="mf"        value="<?= e($f['mf'] ?? '') ?>">


<!-- LINHA 2 (condicional): filtros ativos em linha separada -->
<?php if (!empty($active_chips)): ?>
<div class="strip-row strip-row-active">
  <span class="strip-label">Filtros:</span>
  <?php foreach ($active_chips as $chip):
    [$label, $key] = $chip;
    $href = isset($chip[2]) ? $chip[2] : chip_url_remove($f, $key);
  ?>
    <a class="chip-remove" href="<?= e($href) ?>"><?= e($label) ?> ×</a>
  <?php endforeach; ?>
  <a class="chip-remove chip-clear-all" href="cnpj.php?noauto=1" onclick="return confirm('Limpar todos os filtros e começar nova busca?')">Limpar tudo</a>
</div>
<?php endif; ?>


  <!-- Results -->
  <main class="cnpj-results">
    <?php if ($is_default): ?>
      <div style="background:#eef2ff;border:1px solid #a7f3d0;border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:.85rem;color:#3730a3">
        🎯 <strong>Leads prontos:</strong> mostrando empresas ativas com telefone e e-mail. Use os filtros para refinar.
      </div>
    <?php endif; ?>
    <?php if (!empty($results['error'])): ?>
      <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;padding:14px;margin-bottom:12px;font-size:.88rem;color:#92400e">
        ⚠ <strong>Consulta muito ampla.</strong> Adicione mais filtros pra reduzir o conjunto:
        <ul style="margin:6px 0 0 22px; line-height:1.6">
          <li><strong>UF + Município</strong> juntos (mais específico)</li>
          <li><strong>Vertical OU sub-vertical</strong> específica</li>
          <li><strong>Capital mínimo</strong> (≥ R$ 100 mil filtra muito)</li>
        </ul>
      </div>
    <?php endif; ?>

      <?php if (!$has_submitted): ?>
      <div class="empty-state" style="background:#fff;border-radius:12px;padding:60px 30px;text-align:center;color:#64748b;box-shadow:0 1px 4px rgba(0,0,0,.06)">
        <div style="font-size:3rem;margin-bottom:14px">🎯</div>
        <h3 style="margin:0 0 6px;color:#0f172a;font-size:1.1rem;font-weight:700">Configure seus filtros e clique em Buscar</h3>
        <p style="margin:0 0 6px;font-size:.9rem">Use os <strong>chips</strong> rápidos e o painel de <strong>filtros</strong> pra compor a busca.</p>
        <p style="margin:0 0 22px;font-size:.78rem;color:#94a3b8">💡 Dica: para resultados rápidos, sempre <strong>combine Vertical + UF + Município</strong>. Buscas amplas demoram.</p>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
          <a href="?submitted=1&situacao=02&tem_email=1&tem_tel=1&uf=SP" style="background:#eef2ff;color:#4338ca;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:500">SP ativas com contato</a>
          <a href="?submitted=1&situacao=02&tem_email=1&sem_mei=1&vertical=TEC" style="background:#eef2ff;color:#4338ca;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:500">Tech ativas sem MEI</a>
          <a href="?submitted=1&vertical=FIN&situacao=02&tem_email=1" style="background:#eef2ff;color:#4338ca;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:500">Financeiro com e-mail</a>
        </div>
      </div>
      <?php else: ?>
      <div class="results-header">
        <div class="results-count">
          <b><?= cnpj_nfmt_br($results['total']) ?><?= ($results['total_more'] ?? false) ? '+' : '' ?></b> empresa(s)
          <span style="color:#94a3b8;font-weight:400">·</span>
          <span style="color:#64748b">vendo <span id="loaded-n"><?= cnpj_nfmt_br(count($results['rows'])) ?></span></span>
          <?php if ($results['total_more'] ?? false): ?>
            <span style="color:#cbd5e1;font-size:.78rem">·</span>
            <span style="color:#94a3b8;font-size:.78rem">use filtros pra refinar</span>
          <?php endif; ?>
        </div>
        <?php if (!empty($results['rows'])): ?>
        <div class="results-actions">
          <button type="button" class="rh-btn" onclick="selectVisible()">☑ Marcar visíveis</button>
          <button type="button" class="rh-btn" onclick="selectUpToQuota()">🎯 Marcar até cota (<?= cnpj_nfmt_br(max(0, $q_limit - $q_used)) ?>)</button>
          <button type="button" class="rh-btn" onclick="openSaveModal()">💾 Salvar lista</button>
          <a href="cnpj-export.php?<?= http_build_query($f) ?>" class="rh-btn rh-btn-primary">⬇ Exportar todas</a>
        </div>
        <?php endif; ?>
      </div>

      <?php if (empty($results['rows'])):
        // Diagnóstico: qual filtro está matando a busca?
        $diag_results = function_exists('cnpj_diagnose') ? cnpj_diagnose($f) : [];
        $culpado = null;
        foreach ($diag_results as $d) { if ($d['culpado']) { $culpado = $d; break; } }
      ?>
        <div class="empty-state-pro">
          <div class="empty-icon">🔍</div>
          <h3>Nenhuma empresa encontrada</h3>
          <p class="empty-subtitle">Os filtros aplicados não retornaram resultados.</p>

          <?php if ($culpado): ?>
            <div class="diag-found">
              <p class="diag-label">🎯 Encontramos o problema:</p>
              <p class="diag-text">
                Removendo o filtro <strong>"<?= e($culpado['remove']) ?>"</strong>,
                aparecem <strong><?= cnpj_nfmt_br($culpado['achou']) ?><?= $culpado['achou'] >= 100 ? '+' : '' ?></strong> empresa(s).
              </p>
              <a class="suggestion-pill" href="<?= e(chip_url_remove($f, $culpado['key'])) ?>" style="margin-top:12px">
                <span class="x-icon">×</span> Remover "<?= e($culpado['remove']) ?>"
              </a>
            </div>
          <?php endif; ?>

          <?php if (!empty($active_chips)): ?>
            <div class="empty-suggestions">
              <p class="empty-suggestions-label">Ou tente remover outro filtro:</p>
              <div class="suggestions-grid">
                <?php foreach ($active_chips as $chip):
                  [$label, $key] = $chip;
                  $href = isset($chip[2]) ? $chip[2] : chip_url_remove($f, $key);
                ?>
                  <a class="suggestion-pill" href="<?= e($href) ?>">
                    <span class="x-icon">×</span> <?= e($label) ?>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <div style="margin-top:24px;padding-top:18px;border-top:1px solid #f1f5f9">
            <a href="cnpj.php?noauto=1" style="font-size:.85rem;color:var(--cr);text-decoration:none;font-weight:500">↺ Começar nova busca</a>
          </div>
        </div>
      <?php else: ?>
        <div class="result-list">
          <?php include __DIR__ . '/cnpj-rows.php'; ?>
        </div>

        <!-- Load more -->
        <?php if ($has_more): ?>
        <div id="load-more-wrap" style="text-align:center;padding:20px">
          <button type="button" id="load-more-btn" class="btn-primary" style="width:auto;padding:11px 32px">
            ⬇ Carregar mais 100
          </button>
          <div id="load-more-status" style="font-size:.82rem;color:#6b7280;margin-top:10px"></div>
        </div>
        <?php endif; ?>
      <?php endif; ?>
      <?php endif; /* has_submitted */ ?>
  </main>
</form>

<!-- Save list modal -->
<div class="modal-bg" id="save-modal">
  <div class="modal">
    <h3>Salvar lista de empresas</h3>
    <form method="post" action="cnpj-lists.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_filter">
      <input type="hidden" name="filter_json" value="<?= e(json_encode($f)) ?>">
      <input type="hidden" name="item_count" value="<?= $results['total'] ?>">
      <input type="text" name="name" placeholder="Nome da lista" required>
      <textarea name="description" placeholder="Descrição (opcional)" rows="3"></textarea>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal()">Cancelar</button>
        <button type="submit" class="btn-primary" style="width:auto">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- Detail drawer -->
<div class="drawer-bg" id="drawer-bg" onclick="closeDrawer()"></div>
<aside class="drawer" id="drawer">
  <div class="drawer-head">
    <h2 id="drawer-title">Detalhes</h2>
    <button type="button" class="drawer-close" onclick="closeDrawer()">×</button>
  </div>
  <div class="drawer-body" id="drawer-body">Carregando…</div>
</aside>

<!-- Bulk action bar -->
<div id="bulk-bar">
  <span class="count" id="bulk-count">0</span> selecionadas
  <button type="button" id="bulk-copy">📋 Copiar CNPJs</button>
  <button type="button" id="bulk-save" class="primary">💾 Salvar lista</button>
  <button type="button" id="bulk-clear">Limpar</button>
</div>

</div><!-- /.cnpj-page -->

<script>
// Error handler global — pega erros silenciosos
window.addEventListener('error', e => {
    console.error('[Newton CNPJ]', e.message, 'at', e.filename + ':' + e.lineno);
});
window.addEventListener('unhandledrejection', e => {
    console.error('[Newton CNPJ] Promise rejection:', e.reason);
});

function openSaveModal() { document.getElementById('save-modal').classList.add('open'); }
function closeModal()    { document.getElementById('save-modal').classList.remove('open'); }

// ── Chips toggle: comportamento depende se já fez busca ───────────────────────
// 1ª busca: chips só marcam visualmente, usuário clica "Buscar"
// Buscas seguintes: chips refinam automaticamente (após 600ms)
let _searchTimer = null;
let _countdownTimer = null;
const _alreadySearched = new URLSearchParams(location.search).has('submitted');

function scheduleSearch() {
    if (!_alreadySearched) {
        // Primeira vez — só marca visualmente, não submete
        markPendingFirst();
        return;
    }
    // Já buscou — refina automaticamente (rápido, 600ms)
    if (_searchTimer) clearTimeout(_searchTimer);
    if (_countdownTimer) clearInterval(_countdownTimer);

    showLoadingNotice('⏳ Refinando…');
    _searchTimer = setTimeout(() => {
        showLoadingNotice('🔍 Buscando…');
        document.getElementById('cnpj-form').submit();
    }, 600);
}

function markPendingFirst() {
    const btn = document.getElementById('btn-buscar');
    if (btn && !btn.classList.contains('pending')) {
        btn.classList.add('pending');
        btn.innerHTML = '🔍 Buscar agora →';
    }
    // Pulse no botão pra chamar atenção
}

function showLoadingNotice(msg) {
    let n = document.getElementById('loading-notice');
    if (!n) {
        n = document.createElement('div');
        n.id = 'loading-notice';
        n.style.cssText = 'position:fixed;top:14px;left:50%;transform:translateX(-50%);background:var(--cr);color:#fff;padding:10px 22px;border-radius:8px;font-size:.85rem;font-weight:600;z-index:200;box-shadow:0 4px 14px rgba(16,185,129,.3);';
        document.body.appendChild(n);
    }
    n.innerHTML = msg;
}

function toggleChip(field, value, btn, clearField) {
    const inp = document.getElementById('hf-' + field);
    const isOn = inp.value === value;
    inp.value = isOn ? '' : value;
    btn.classList.toggle('active', !isOn);
    if (clearField && !isOn) {
        const other = document.getElementById('hf-' + clearField);
        if (other && other.value) {
            other.value = '';
            const otherBtn = document.querySelector('[onclick*="toggleChip(\'' + clearField + '\'"]');
            if (otherBtn) otherBtn.classList.remove('active');
        }
    }
    scheduleSearch();
}

function toggleMfChip(value, btn) {
    const inp = document.getElementById('hf-mf');
    const isOn = inp.value === value;
    inp.value = isOn ? '' : value;
    document.querySelectorAll('.chip-mf-1, .chip-mf-2').forEach(b => b.classList.remove('active'));
    if (!isOn) btn.classList.add('active');
    scheduleSearch();
}

function toggleAtivasChip(btn) {
    const sel = document.querySelector('select[name="situacao"]');
    if (!sel) return;
    sel.value = (sel.value === '02') ? '' : '02';
    btn.classList.toggle('active', sel.value === '02');
    scheduleSearch();
}

// Copy on click — universal (qualquer elemento)
function copyText(txt, el) {
    navigator.clipboard.writeText(txt).then(() => {
        if (!el) return;
        el.classList.add('copied');
        setTimeout(() => el.classList.remove('copied'), 1200);
    });
}

// ── Favorites (localStorage) ──────────────────────────────────────────────────
function getFavs() { try { return JSON.parse(localStorage.getItem('newton_favs') || '[]'); } catch(e){ return []; } }
function setFavs(arr) { localStorage.setItem('newton_favs', JSON.stringify(arr)); }
function addToCRM(btn, data) {
    // CRÍTICO: previne qualquer submit do form (evitar reload da busca)
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = '⏳';
    const fd = new FormData();
    fd.append('action', 'add_from_cnpj');
    fd.append('_csrf', <?= json_encode(csrf_token()) ?>);
    fd.append('data', JSON.stringify(data));
    fetch('crm.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(r => {
            if (r.ok) {
                btn.textContent = '✓ CRM';
                btn.style.background = '#dcfce7';
                btn.style.color = '#15803d';
                btn.style.borderColor = '#86efac';
                const msg = r.credit_consumed
                    ? '✓ Lead salvo no Pipeline — 1 crédito consumido'
                    : '✓ Lead salvo no Pipeline (crédito já contabilizado)';
                showToast(msg);
            } else if (r.error === 'quota_exceeded') {
                btn.textContent = orig;
                btn.disabled = false;
                showToast('🚫 Cota esgotada — adquira um Lead Pack para continuar', true);
            } else {
                btn.textContent = orig;
                btn.disabled = false;
                showToast('⚠ Erro: ' + (r.error || 'falha'), true);
            }
        }).catch(() => {
            btn.textContent = orig; btn.disabled = false;
            showToast('⚠ Erro de rede', true);
        });
    return false; // evita propagação que possa submeter form
}

// Toast pequeno (substitui alerts que travam)
function showToast(msg, error) {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:' +
        (error ? '#fef2f2' : '#0f172a') + ';color:' + (error ? '#b91c1c' : '#fff') +
        ';padding:12px 18px;border-radius:8px;font-size:.88rem;font-weight:500;' +
        'box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:300;transition:opacity .3s';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 2200);
}

function toggleFav(cnpj, btn) {
    const favs = getFavs();
    const i = favs.indexOf(cnpj);
    if (i >= 0) { favs.splice(i, 1); btn.classList.remove('on'); }
    else        { favs.push(cnpj);    btn.classList.add('on'); }
    setFavs(favs);
}
// Marcar favoritos ao renderizar
function markFavs() {
    const favs = new Set(getFavs());
    document.querySelectorAll('.result-card').forEach(c => {
        const cnpj = c.dataset.cnpj;
        const btn = c.querySelector('.fav-btn');
        if (btn && favs.has(cnpj)) btn.classList.add('on');
    });
}

// ── Bulk select ───────────────────────────────────────────────────────────────
const QUOTA_AVAILABLE = <?= (int) max(0, $q_limit - $q_used) ?>;

function getSelected() {
    return Array.from(document.querySelectorAll('.bulk-cb:checked')).map(c => c.value);
}

function selectVisible() {
    const cbs = document.querySelectorAll('.bulk-cb');
    const allChecked = Array.from(cbs).every(c => c.checked);
    cbs.forEach(c => c.checked = !allChecked);
    updateBulkBar();
}

function selectUpToQuota() {
    const cbs = Array.from(document.querySelectorAll('.bulk-cb'));
    const limit = QUOTA_AVAILABLE;
    if (limit <= 0) { alert('Você não tem créditos disponíveis este mês.'); return; }
    // Já marcadas mantêm
    let alreadyChecked = cbs.filter(c => c.checked).length;
    if (alreadyChecked >= limit) {
        // Reduz pra ficar = limit
        let extra = alreadyChecked - limit;
        for (let i = cbs.length - 1; i >= 0 && extra > 0; i--) {
            if (cbs[i].checked) { cbs[i].checked = false; extra--; }
        }
        alert(`Você tinha mais selecionadas que o limite. Reduzi para ${limit} (sua cota disponível).`);
    } else {
        // Marca em ordem até atingir limit
        let toAdd = limit - alreadyChecked;
        for (let i = 0; i < cbs.length && toAdd > 0; i++) {
            if (!cbs[i].checked) { cbs[i].checked = true; toAdd--; }
        }
        if (alreadyChecked + (limit - alreadyChecked - toAdd) < limit) {
            // Não tinha cards suficientes
        }
    }
    updateBulkBar();
    // Se ainda não tem 100 cards e quota > 100, sugere carregar mais
    const checked = getSelected().length;
    if (checked < limit && cbs.length < limit) {
        const need = limit - cbs.length;
        if (confirm(`Sua cota permite ${limit} mas só tem ${cbs.length} carregadas. Carregar mais ${need <= 1000 ? need : 1000}?`)) {
            document.getElementById('load-more-btn')?.click();
        }
    }
}
function updateBulkBar() {
    const sel = getSelected();
    const bar = document.getElementById('bulk-bar');
    document.getElementById('bulk-count').textContent = sel.length;
    bar.classList.toggle('show', sel.length > 0);
}
document.addEventListener('change', e => {
    if (e.target.matches('.bulk-cb')) updateBulkBar();
});
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('bulk-clear')?.addEventListener('click', () => {
        document.querySelectorAll('.bulk-cb:checked').forEach(c => c.checked = false);
        updateBulkBar();
    });
    document.getElementById('bulk-copy')?.addEventListener('click', () => {
        const cnpjs = getSelected().map(c => c.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5')).join('\n');
        navigator.clipboard.writeText(cnpjs).then(() => alert(getSelected().length + ' CNPJs copiados.'));
    });
    document.getElementById('bulk-save')?.addEventListener('click', () => {
        const sel = getSelected();
        if (!sel.length) return;
        const name = prompt('Nome da lista (' + sel.length + ' empresas):');
        if (!name) return;
        // Submete via form dinâmico
        const fd = new FormData();
        fd.append('action', 'save_filter');
        fd.append('name', name);
        fd.append('filter_json', JSON.stringify({_cnpjs: sel}));
        fd.append('item_count', sel.length);
        fetch('cnpj-lists.php', { method: 'POST', body: fd }).then(() => {
            alert('Lista salva!');
            location.href = 'cnpj-lists.php';
        });
    });
    markFavs();
    sortByScoreIfNeeded();
});

// ── Busca avançada (toggle de Empresa + Tempo) ───────────────────────────────
function toggleAdvanced(btn) {
    const adv = document.getElementById('filters-advanced');
    if (!adv) return;
    const opening = !adv.classList.contains('open');
    adv.classList.toggle('open');
    if (btn) btn.classList.toggle('open');
}

// ── Filtros panel toggle com prevenção de pulo de tela ───────────────────────
function toggleFiltersPanel() {
    const panel    = document.getElementById('filters-panel');
    const viewport = document.querySelector('.viewport') || document.documentElement;
    const scrollBefore = viewport.scrollTop;
    const opening  = !panel.classList.contains('open');
    panel.classList.toggle('open');
    if (opening) {
        // Ancora o painel no topo do scroll para não "pular"
        const panelTop = panel.getBoundingClientRect().top + viewport.scrollTop - 80;
        viewport.scrollTo({ top: panelTop, behavior: 'smooth' });
    } else {
        // Ao fechar, mantém o scroll atual
        viewport.scrollTop = scrollBefore;
    }
}

// ── Sort by Newton Score (client-side) ────────────────────────────────────────
function sortByScoreIfNeeded() {
    const params = new URLSearchParams(location.search);
    if (params.get('sort') !== 'score') return;
    const list = document.querySelector('.result-list');
    if (!list) return;
    const cards = Array.from(list.querySelectorAll('.result-card'));
    cards.sort((a, b) => (parseInt(b.dataset.score) || 0) - (parseInt(a.dataset.score) || 0));
    cards.forEach(c => list.appendChild(c));
}

// ── Sub-verticais cascading ───────────────────────────────────────────────────
async function loadSubverticais() {
    const vsel = document.getElementById('vertical-sel');
    const sel  = document.getElementById('subvert-sel');
    if (!sel || !vsel) {
        console.warn('[Newton] loadSubverticais: elementos não encontrados', {vsel, sel});
        return;
    }
    const v = vsel.value;
    console.log('[Newton] loadSubverticais chamado, vertical =', v);

    if (!v) {
        sel.innerHTML = '<option value="">Selecione uma vertical primeiro</option>';
        sel.disabled = true;
        return;
    }

    // Preserva valor atualmente selecionado (vital no refresh — PHP renderizou seleção)
    const previousValue = sel.value;

    sel.disabled = false;
    sel.removeAttribute('disabled');
    // Não limpa visualmente se já tinha opções carregadas — evita flash
    if (sel.options.length <= 1) {
        sel.innerHTML = '<option value="">Carregando atividades…</option>';
    }

    try {
        const resp = await fetch('cnpj-api.php?action=subverticais&vertical=' + encodeURIComponent(v));
        if (!resp.ok) {
            console.error('[Newton] Subverticais: HTTP', resp.status, resp.statusText);
            sel.innerHTML = '<option value="">Erro ' + resp.status + ' — recarregue</option>';
            return;
        }
        const text = await resp.text();
        let data;
        try { data = JSON.parse(text); }
        catch (e) {
            console.error('[Newton] Subverticais: JSON inválido:', text.slice(0, 300));
            sel.innerHTML = '<option value="">Erro: resposta inválida</option>';
            return;
        }
        if (!Array.isArray(data)) {
            console.error('[Newton] Subverticais: esperado array, recebido:', data);
            sel.innerHTML = '<option value="">Erro: formato inesperado</option>';
            return;
        }
        console.log('[Newton] Subverticais carregadas:', data.length);
        if (!data.length) {
            sel.innerHTML = '<option value="">Nenhuma atividade específica</option>';
            return;
        }
        sel.innerHTML = '<option value="">Todas as atividades (' + data.length + ')</option>';
        data.forEach(s => {
            const o = document.createElement('option');
            o.value = s.sub_vertical;
            o.textContent = s.sub_vertical;
            if (s.sub_vertical === previousValue) o.selected = true;
            sel.appendChild(o);
        });
        // Garante restauração mesmo se a opção não vier (edge case)
        if (previousValue && sel.value !== previousValue) sel.value = previousValue;
    } catch(e) {
        console.error('[Newton] Subverticais fetch error:', e);
        sel.innerHTML = '<option value="">Erro ao carregar — recarregue</option>';
    }
}

// Bind do evento de mudança de vertical — único ponto de entrada
document.addEventListener('DOMContentLoaded', () => {
    const vsel = document.getElementById('vertical-sel');
    if (vsel) {
        vsel.addEventListener('change', () => { loadSubverticais(); scheduleSearch(); });
        if (vsel.value) loadSubverticais();
    }

    // Auto-busca em TODOS os filtros do painel — UX consistente com os chips rápidos
    // (UF tem handler próprio que dispara loadMunicipios + scheduleSearch via 'change' abaixo)
    const panel = document.getElementById('filters-panel');
    if (panel) {
        panel.addEventListener('change', (e) => {
            const t = e.target;
            // Ignora elementos sem name (não enviados no form)
            if (!t.name) return;
            // Vertical já tem handler dedicado acima
            if (t.id === 'vertical-sel') return;
            // mun-input não tem name (a seleção é via mun-code hidden)
            scheduleSearch();
        });
    }
});

// ── Município autocomplete robusto ────────────────────────────────────────────
let _munList = [];
let _munLoading = false;
let _munLoaded = false;
let _munActiveIdx = -1;
let _munSelected = false; // true quando cidade foi escolhida — suprime dropdown

function normStr(s) {
    return (s || '').toString().toLowerCase()
        .normalize('NFD').replace(/[̀-ͯ]/g, ''); // remove acentos
}

// Trocar UF invalida cidade anterior (evita filtro inválido tipo UF=RJ + cidade=SP)
function onUfChange() {
    const inp  = document.getElementById('mun-input');
    const code = document.getElementById('mun-code');
    if (inp)  inp.value  = '';
    if (code) code.value = '';
    _munSelected = false;
    _munList = []; _munLoaded = false;
    loadMunicipios();
}

async function loadMunicipios() {
    const uf  = document.getElementById('uf-sel')?.value;
    const inp = document.getElementById('mun-input');
    const code = document.getElementById('mun-code');
    if (!inp) return;
    inp.disabled = !uf;
    inp.placeholder = uf ? 'Digite o nome da cidade…' : 'Selecione UF primeiro';
    if (!uf) {
        inp.value = ''; if (code) code.value = '';
        _munList = []; _munLoaded = false;
        return;
    }

    _munLoading = true;
    _munLoaded = false;
    if (!_munSelected) showMunSuggestions(inp.value); // mostra "carregando" só se não selecionado

    try {
        const data = await fetch('cnpj-api.php?action=municipios&uf=' + uf + '&v=4').then(r => r.json());
        _munList = data.map(m => ({
            codigo: (m.codigo || '').toString().trim(),
            nome: (m.nome || '').toString().trim()
        }));
        _munLoaded = true;
    } catch(e) {
        _munList = []; _munLoaded = false;
    } finally {
        _munLoading = false;
        // Re-renderiza sugestões se input tá focado/com texto
        if (!_munSelected && document.activeElement === inp) showMunSuggestions(inp.value);
    }
}

function showMunSuggestions(query) {
    const box = document.getElementById('mun-suggestions');
    if (!box) return;
    // Cidade já selecionada — não mostra nada (evita "SANTOS" aparecer abaixo)
    if (_munSelected) { box.style.display = 'none'; return; }
    const q = normStr(query).trim();

    if (_munLoading) {
        box.innerHTML = '<div class="mun-suggest-loading">⏳ Carregando municípios…</div>';
        box.style.display = 'block';
        return;
    }
    if (!_munLoaded) { box.style.display = 'none'; return; }
    if (q.length < 1) {
        // Sem query, mostra primeiros 8 alfabeticamente
        const sample = _munList.slice(0, 8);
        renderMunList(box, sample, q);
        return;
    }
    // Filtra: começa com / contém (com normalização sem acento)
    const startsWith = _munList.filter(m => normStr(m.nome).startsWith(q));
    const contains   = _munList.filter(m => !normStr(m.nome).startsWith(q) && normStr(m.nome).includes(q));
    const matches = startsWith.concat(contains).slice(0, 10);
    if (!matches.length) {
        box.innerHTML = '<div class="mun-suggest-empty">Nenhum município encontrado pra "' + escapeHtml(query) + '"</div>';
        box.style.display = 'block';
        return;
    }
    renderMunList(box, matches, q);
    _munActiveIdx = -1;
}

function renderMunList(box, items, q) {
    box.innerHTML = items.map((m, i) => {
        const safeName = escapeHtml(m.nome);
        return '<div class="mun-suggest-item" data-idx="' + i + '" data-code="' + m.codigo + '" data-nome="' + safeName + '">' + safeName + '</div>';
    }).join('');
    box.style.display = 'block';
    // Liga clicks
    box.querySelectorAll('.mun-suggest-item').forEach(el => {
        el.addEventListener('mousedown', (e) => {
            e.preventDefault(); // evita blur antes de selecionar
            selectMun(el.dataset.code, el.dataset.nome);
        });
    });
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function selectMun(code, nome) {
    const inp  = document.getElementById('mun-input');
    const codeEl = document.getElementById('mun-code');
    const box  = document.getElementById('mun-suggestions');
    const clr  = document.getElementById('mun-clear');
    if (inp)    inp.value  = nome;
    if (codeEl) codeEl.value = code;
    if (box)    box.style.display = 'none';
    if (clr)    clr.style.display = '';
    _munSelected = true;
    // Visual: input fica verde brevemente
    if (inp) {
        inp.style.borderColor = '#22c55e';
        inp.style.background  = '#f0fdf4';
        setTimeout(() => { inp.style.borderColor = ''; inp.style.background = ''; }, 600);
    }
    scheduleSearch(); // auto-busca igual ao comportamento dos chips
}

// Limpa cidade selecionada (botão X ao lado do input)
function clearMunSelection() {
    const inp  = document.getElementById('mun-input');
    const code = document.getElementById('mun-code');
    const clr  = document.getElementById('mun-clear');
    if (inp)  inp.value  = '';
    if (code) code.value = '';
    if (clr)  clr.style.display = 'none';
    _munSelected = false;
    if (inp) inp.focus();
    scheduleSearch();
}

document.addEventListener('DOMContentLoaded', () => {
    const inp = document.getElementById('mun-input');
    if (inp) {
        inp.addEventListener('input', e => {
            _munSelected = false; // digitando = invalida seleção anterior
            const code = document.getElementById('mun-code');
            const clr  = document.getElementById('mun-clear');
            if (code && code.value) code.value = '';
            if (clr) clr.style.display = 'none';
            showMunSuggestions(e.target.value);
        });
        inp.addEventListener('focus', e => {
            if (!_munSelected) showMunSuggestions(e.target.value);
        });
        inp.addEventListener('blur', () => {
            setTimeout(() => {
                const box = document.getElementById('mun-suggestions');
                if (box) box.style.display = 'none';
            }, 200);
        });
        // Keyboard navigation
        inp.addEventListener('keydown', e => {
            const box = document.getElementById('mun-suggestions');
            if (!box || box.style.display === 'none') return;
            const items = box.querySelectorAll('.mun-suggest-item');
            if (!items.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                _munActiveIdx = Math.min(_munActiveIdx + 1, items.length - 1);
                updateMunHighlight(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                _munActiveIdx = Math.max(_munActiveIdx - 1, -1);
                updateMunHighlight(items);
            } else if (e.key === 'Enter' && _munActiveIdx >= 0) {
                e.preventDefault();
                const el = items[_munActiveIdx];
                selectMun(el.dataset.code, el.dataset.nome);
            }
        });

        // (init de _munSelected e loadMunicipios já feito em syncInit — não repetir aqui)
    }
});

function updateMunHighlight(items) {
    items.forEach((el, i) => el.classList.toggle('active', i === _munActiveIdx));
    if (_munActiveIdx >= 0) items[_munActiveIdx].scrollIntoView({block: 'nearest'});
}


// Init síncrono no fim do body (DOM já carregado).
// IMPORTANTE: roda ANTES de DOMContentLoaded, então qualquer estado de "valor já preenchido"
// precisa ser detectado aqui também, não só no DOMContentLoaded.
(function syncInit() {
    try {
        // Sub-verticais: PHP já renderizou as opções, mas chamamos para garantir consistência
        const vsel = document.getElementById('vertical-sel');
        if (vsel && vsel.value) loadSubverticais();

        // Município: se cidade já estava selecionada (refresh com filtro), seta flag PRIMEIRO
        // para que loadMunicipios() não mostre "Carregando..." nem reabra o dropdown.
        const munCodeEl = document.getElementById('mun-code');
        if (munCodeEl?.value) {
            _munSelected = true;
        }
        const ufsel = document.getElementById('uf-sel');
        if (ufsel && ufsel.value) loadMunicipios();
    } catch(e) { console.error('[Newton init]', e); }
})();

// ── CNAE autocomplete (defensivo) ─────────────────────────────────────────────
(function(){
    const inp = document.getElementById('cnae-input');
    const box = document.getElementById('cnae-list');
    if (!inp || !box) return; // sai limpo se elementos não existem
    let timer;
    inp.addEventListener('input', function() {
        clearTimeout(timer);
        const q = inp.value.trim();
        if (q.length < 2) { hideSugg(); return; }
        timer = setTimeout(() => fetchCnaes(q), 280);
    });
    inp.addEventListener('keydown', e => { if (e.key === 'Escape') hideSugg(); });
    document.addEventListener('click', e => { if (!box.contains(e.target) && e.target !== inp) hideSugg(); });
    async function fetchCnaes(q) {
        try {
            const data = await fetch('cnpj-api.php?action=cnaes&q=' + encodeURIComponent(q)).then(r => r.json());
            if (!data.length) { hideSugg(); return; }
            let el = document.getElementById('cnae-suggestions');
            if (!el) { el = document.createElement('div'); el.id = 'cnae-suggestions'; box.appendChild(el); }
            el.innerHTML = '';
            data.forEach(c => {
                const d = document.createElement('div');
                d.innerHTML = '<strong>' + c.codigo + '</strong><small>' + c.descricao + '</small>';
                d.addEventListener('mousedown', e => { e.preventDefault(); inp.value = c.codigo; hideSugg(); });
                el.appendChild(d);
            });
        } catch(e) { hideSugg(); }
    }
    function hideSugg() { const el = document.getElementById('cnae-suggestions'); if (el) el.remove(); }
})();

// ── Collapsible filter sections ───────────────────────────────────────────────
document.querySelectorAll('.filter-section-title').forEach(t => {
    t.addEventListener('click', () => t.classList.toggle('collapsed'));
});

// ── Confirmação de consumo de lead antes de abrir o drawer ──────────────────
// Regra de negócio: cada visualização única no mês = 1 lead da cota.
// Re-visualizar o mesmo CNPJ no mesmo mês NÃO cobra de novo (dedup server + localStorage).

function _viewedKey() {
    const d = new Date();
    return 'cnpj_viewed_' + d.getFullYear() + '_' + String(d.getMonth() + 1).padStart(2, '0');
}
function _viewedSet() {
    try { return new Set(JSON.parse(localStorage.getItem(_viewedKey()) || '[]')); }
    catch(e) { return new Set(); }
}
function _viewedAdd(cnpj) {
    const s = _viewedSet();
    s.add(cnpj);
    localStorage.setItem(_viewedKey(), JSON.stringify([...s]));
}

async function confirmAndOpen(cnpj) {
    // Já visualizado este mês? Abre direto, sem cobrar nem perguntar.
    if (_viewedSet().has(cnpj)) {
        return openDetail(cnpj);
    }
    // Primeira visualização do mês: pede confirmação
    const ok = await showLeadConfirmModal();
    if (!ok) return;
    // Loga no servidor (dedup + consumo) ANTES de abrir
    try {
        const r = await fetch('cnpj-api.php?action=log_view&cnpj=' + encodeURIComponent(cnpj))
                        .then(r => r.json());
        if (!r.ok) {
            if (r.error === 'quota_exceeded') {
                showToast('⚠ Cota mensal esgotada. Upgrade ou compre créditos extras.', true);
            } else {
                showToast('⚠ Erro ao contabilizar: ' + (r.error || 'desconhecido'), true);
            }
            return;
        }
        _viewedAdd(cnpj);
        if (r.counted) {
            updateQuotaIndicator(r.used, r.limit);
            showToast('✓ 1 lead consumido · ' + r.used + '/' + r.limit);
        }
    } catch(e) {
        console.error('[Newton] log_view falhou', e);
        // Mesmo se der erro, abre o drawer (não bloqueia UX por causa de log)
    }
    openDetail(cnpj);
}

function showLeadConfirmModal() {
    return new Promise(resolve => {
        const bg = document.createElement('div');
        bg.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:400;display:flex;align-items:center;justify-content:center;animation:fadeIn .15s';
        bg.innerHTML = `
            <div style="background:#fff;border-radius:14px;padding:24px;max-width:420px;width:90%;box-shadow:0 20px 50px rgba(0,0,0,.2);animation:slideUp .2s">
                <div style="font-size:1.6rem;margin-bottom:8px">🎯</div>
                <h3 style="margin:0 0 10px;font-size:1.05rem;font-weight:700;color:#0f172a">Visualizar este lead?</h3>
                <p style="margin:0 0 16px;font-size:.86rem;line-height:1.5;color:#475569">
                    Ver os detalhes vai contabilizar <strong>1 lead</strong> da sua cota mensal.<br>
                    <small style="color:#64748b">Re-abrir o mesmo lead neste mês não cobra de novo.</small>
                </p>
                <div style="display:flex;gap:8px;justify-content:flex-end">
                    <button id="lcm-cancel" style="background:#fff;border:1px solid #e5e7eb;color:#475569;padding:9px 18px;border-radius:8px;font-size:.86rem;font-weight:500;cursor:pointer">Cancelar</button>
                    <button id="lcm-ok" style="background:var(--cr);border:none;color:#fff;padding:9px 18px;border-radius:8px;font-size:.86rem;font-weight:600;cursor:pointer">Sim, ver lead</button>
                </div>
            </div>
        `;
        document.body.appendChild(bg);
        const close = (ans) => { bg.remove(); resolve(ans); };
        bg.querySelector('#lcm-ok').onclick = () => close(true);
        bg.querySelector('#lcm-cancel').onclick = () => close(false);
        bg.onclick = (e) => { if (e.target === bg) close(false); };
        document.addEventListener('keydown', function onEsc(e) {
            if (e.key === 'Escape') { document.removeEventListener('keydown', onEsc); close(false); }
        });
    });
}

function updateQuotaIndicator(used, limit) {
    // Atualiza o indicador "X / Y leads · Z%" no topo da página
    const quota = document.querySelector('.quota');
    if (!quota) return;
    const pct = limit > 0 ? Math.round((used / limit) * 100) : 0;
    const nums = quota.querySelectorAll('span');
    if (nums[0]) nums[0].innerHTML = '<b>' + used.toLocaleString('pt-BR') + '</b> / ' + limit.toLocaleString('pt-BR') + ' leads';
    // Atualiza a barra
    const bar = quota.querySelector('.bar i');
    if (bar) {
        bar.style.width = pct + '%';
        bar.className = pct >= 90 ? 'danger' : (pct >= 50 ? 'warn' : 'ok');
    }
    // Atualiza o %
    const pctSpan = [...quota.querySelectorAll('span')].find(s => s.textContent.includes('%'));
    if (pctSpan) pctSpan.textContent = pct + '%';
}

// ── Detail drawer ─────────────────────────────────────────────────────────────
async function openDetail(cnpj) {
    document.getElementById('drawer-bg').classList.add('open');
    document.getElementById('drawer').classList.add('open');
    document.getElementById('drawer').dataset.cnpj = cnpj;
    document.getElementById('drawer-title').textContent = 'Carregando…';
    document.getElementById('drawer-body').innerHTML = '<div class="skeleton" style="height:18px;margin-bottom:10px"></div>'.repeat(8);
    try {
        const resp = await fetch('cnpj-api.php?action=detail&cnpj=' + cnpj);
        const text = await resp.text();
        let d;
        try { d = JSON.parse(text); }
        catch (e) {
            document.getElementById('drawer-body').innerHTML = '<div style="color:#b91c1c"><strong>Erro: resposta inválida do servidor.</strong><br><pre style="font-size:.7rem;background:#f3f4f6;padding:8px;border-radius:6px;overflow:auto;max-height:300px">' +
                text.replace(/[<>&]/g, m => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[m])).slice(0, 2000) + '</pre></div>';
            return;
        }
        if (d.error) { document.getElementById('drawer-body').textContent = d.error; return; }
        try { renderDetail(d); }
        catch (e) {
            document.getElementById('drawer-body').innerHTML = '<div style="color:#b91c1c"><strong>Erro ao renderizar:</strong> ' + (e.message || e) + '</div>';
            console.error(e, d);
        }
    } catch(e) {
        document.getElementById('drawer-body').innerHTML = '<div style="color:#b91c1c">Erro ao carregar: ' + (e.message || e) + '</div>';
    }
}
function closeDrawer() {
    document.getElementById('drawer-bg').classList.remove('open');
    document.getElementById('drawer').classList.remove('open');
}
function fmtCnpj(c) {
    return c.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
}
function fmtDateBr(iso) { if (!iso) return '—'; const p = iso.split('-'); return p[2]+'/'+p[1]+'/'+p[0]; }
function esc(s){ return String(s??'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

// Renderiza o breakdown completo do Newton Score (PREMIUM feel — explicabilidade)
function renderScoreBreakdown(bd) {
    if (!bd || !bd.parts) return '';
    const cats = bd.parts.map(p => {
        const pct  = p.max > 0 ? Math.round((p.pts / p.max) * 100) : 0;
        const sigs = p.signals.map(s => `
            <li class="sb-signal ${s.hit ? 'hit' : 'miss'}">
                <span>${s.hit ? '✓' : '○'} ${esc(s.label)}${s.hint ? ' <small style="opacity:.6">· ' + esc(s.hint) + '</small>' : ''}</span>
                <span class="sb-signal-pts">${s.pts > 0 ? '+' : ''}${s.pts}</span>
            </li>
        `).join('');
        const dimClass = p.pts === 0 ? 'dim' : '';
        return `
            <div class="sb-cat">
                <div class="sb-cat-header">
                    <div class="sb-cat-title">${p.icon || ''} ${esc(p.cat)}</div>
                    <div class="sb-cat-pts ${dimClass}"><strong>${p.pts}</strong> / ${p.max}</div>
                </div>
                <div class="sb-bar"><div class="sb-bar-fill" style="width:${pct}%"></div></div>
                <ul class="sb-signals">${sigs}</ul>
            </div>
        `;
    }).join('');

    const redFlags = (bd.red_flags && bd.red_flags.length) ? `
        <div class="sb-redflags">
            <div class="sb-redflags-title">⚠ Red flags (penalizam o score)</div>
            ${bd.red_flags.map(rf => `
                <div class="sb-redflag">
                    <span>${esc(rf.label)}</span>
                    <span class="sb-redflag-pts">−${rf.penalty}</span>
                </div>
            `).join('')}
        </div>
    ` : '';

    return `
        <div class="score-breakdown">
            <div class="sb-header">
                <h4>Por que esse score?</h4>
                <span class="sb-total">${bd.positive} pts ganhos${bd.penalty ? ' · −' + bd.penalty + ' red flags' : ''} → <strong>${bd.total}</strong></span>
            </div>
            <div class="sb-cats">${cats}</div>
            ${redFlags}
        </div>
    `;
}

function renderDetail(d) {
    if (!d || typeof d !== 'object') {
        document.getElementById('drawer-body').textContent = 'Resposta inválida do servidor.';
        return;
    }
    // Defaults defensivos pra evitar TypeError
    d.faturamento      = d.faturamento      || { faixa: '—', fonte: '' };
    d.funcionarios     = d.funcionarios     || { faixa: '—', fonte: '' };
    d.links            = d.links            || {};
    d.socios           = d.socios           || [];
    d.cnaes_secundarios= d.cnaes_secundarios|| [];
    d.newton_score     = d.newton_score     ?? 0;
    d.score_class      = d.score_class      || 'score-cold';
    d.score_label      = d.score_label      || '';
    d.idade            = d.idade            || '';
    document.getElementById('drawer-title').textContent = d.razao_social || ('CNPJ ' + (d.cnpj || ''));

    const endereco = [
        [d.tipo_logradouro, d.logradouro].filter(Boolean).join(' '),
        d.numero, d.complemento, d.bairro
    ].filter(Boolean).join(', ');
    const cep = d.cep ? d.cep.replace(/^(\d{5})(\d{3})$/, '$1-$2') : '';

    let socios = '<p style="color:#9ca3af">Nenhum sócio cadastrado.</p>';
    if (d.socios && d.socios.length) {
        socios = d.socios.map(s => `
            <div class="socio-row">
                <strong>${esc(s.nome_socio)}</strong>
                <small>${esc(s.qualificacao_nome || s.qualificacao || '')} ${s.data_entrada ? '· entrou em ' + fmtDateBr(s.data_entrada) : ''}</small>
            </div>`).join('');
    }

    let cnaesSec = '';
    if (d.cnaes_secundarios && d.cnaes_secundarios.length) {
        cnaesSec = '<h4>Atividades secundárias</h4><ul style="padding-left:18px;margin:0;font-size:.85rem;color:#374151">' +
            d.cnaes_secundarios.map(c => `<li>${esc(c.codigo)} — ${esc(c.descricao)}</li>`).join('') +
            '</ul>';
    }

    // Card de qualificação no topo
    const scoreBlock = `
        <div class="qualify-card">
            <div class="qualify-score qualify-${esc(d.score_class)}">
                <div class="qs-num">${d.newton_score}</div>
                <div class="qs-lbl">Radar Score<br><small>${esc(d.score_label)}</small></div>
            </div>
            <div class="qualify-info">
                <div><span class="qi-lbl">Faturamento estimado</span><strong>${esc(d.faturamento.faixa)}</strong><small>${esc(d.faturamento.fonte)}</small></div>
                <div><span class="qi-lbl">Funcionários estimados</span><strong>${esc(d.funcionarios.faixa)}</strong><small>${esc(d.funcionarios.fonte)}</small></div>
                <div><span class="qi-lbl">Idade da empresa</span><strong>${esc(d.idade || '—')}</strong></div>
            </div>
        </div>
        ${renderScoreBreakdown(d.score_breakdown)}
        <div class="links-bar">
            <a href="${esc(d.links.google)}" target="_blank" rel="noopener">🔎 Google</a>
            <a href="${esc(d.links.maps)}" target="_blank" rel="noopener">🗺 Maps</a>
            ${d.links.linkedin ? `<a href="${esc(d.links.linkedin)}" target="_blank" rel="noopener">💼 LinkedIn</a>` : ''}
            ${d.links.instagram ? `<a href="${esc(d.links.instagram)}" target="_blank" rel="noopener" title="Provável (não verificado)">📷 IG?</a>` : ''}
            ${d.links.site ? `<a href="${esc(d.links.site)}" target="_blank" rel="noopener">🌐 ${esc(d.links.site_dom)}</a>` : ''}
        </div>
    `;

    // Beta: drawer enxuto. Enriquecimento volta como feature premium depois.
    const enrichBlock = '';

    document.getElementById('drawer-body').innerHTML = scoreBlock + enrichBlock + `
        <h4>Identificação</h4>
        <dl class="dt-grid">
            <dt>CNPJ</dt><dd style="font-family:monospace">${fmtCnpj(d.cnpj)}</dd>
            <dt>Razão social</dt><dd>${esc(d.razao_social)}</dd>
            <dt>Nome fantasia</dt><dd>${esc(d.nome_fantasia || '—')}</dd>
            <dt>Natureza jurídica</dt><dd>${esc(d.natureza_juridica_nome || d.natureza_juridica || '—')}</dd>
            <dt>Capital social</dt><dd>${d.capital_social ? 'R$ ' + Number(d.capital_social).toLocaleString('pt-BR', {minimumFractionDigits:2}) : '—'}</dd>
            <dt>Porte</dt><dd>${esc(d.porte_empresa || '—')}</dd>
            <dt>Matriz/Filial</dt><dd>${d.identificador_mf === '1' ? 'Matriz' : 'Filial'}</dd>
        </dl>

        <h4>Situação cadastral</h4>
        <dl class="dt-grid">
            <dt>Situação</dt><dd>${esc(d.situacao_cadastral)}</dd>
            <dt>Data situação</dt><dd>${fmtDateBr(d.data_situacao_cadastral)}</dd>
            <dt>Início atividade</dt><dd>${fmtDateBr(d.data_inicio_atividade)}</dd>
            <dt>Simples Nacional</dt><dd>${d.is_simples ? 'Sim' : 'Não'}</dd>
            <dt>MEI</dt><dd>${d.is_mei ? 'Sim' : 'Não'}</dd>
        </dl>

        <h4>Atividade principal</h4>
        <p style="margin:0;font-size:.88rem"><strong>${esc(d.cnae_principal)}</strong> — ${esc(d.cnae_descricao || '')}</p>
        ${cnaesSec}

        <h4>Endereço</h4>
        <p style="margin:0;font-size:.88rem">${esc(endereco)}<br>${esc(d.municipio_nome || '')}/${esc(d.uf || '')} ${cep ? ' · CEP ' + cep : ''}</p>

        <h4>Contato</h4>
        <dl class="dt-grid">
            <dt>Telefone 1</dt><dd>${d.telefone1 ? '(' + (d.ddd1||'') + ') ' + d.telefone1 : '—'}</dd>
            <dt>Telefone 2</dt><dd>${d.telefone2 ? '(' + (d.ddd2||'') + ') ' + d.telefone2 : '—'}</dd>
            <dt>E-mail</dt><dd>${esc(d.email || '—').toLowerCase()}</dd>
        </dl>

        <h4>Sócios (${d.socios ? d.socios.length : 0})</h4>
        ${socios}
    `;
}

// ── Persist last search + scroll position ────────────────────────────────────
const _viewport = document.querySelector('.viewport') || document.documentElement;

window.addEventListener('beforeunload', () => {
    try {
        const params = new URLSearchParams(location.search);
        if (params.has('submitted') || params.toString().length > 5) {
            sessionStorage.setItem('cnpj_last_search', location.search);
        }
        sessionStorage.setItem('cnpj_scroll', String(_viewport.scrollTop || window.scrollY));
        sessionStorage.setItem('cnpj_scroll_url', location.pathname + location.search);
    } catch(e) {}
});

window.addEventListener('DOMContentLoaded', () => {
    try {
        // Scroll restore (mesma URL = refinamento)
        const y = sessionStorage.getItem('cnpj_scroll');
        const url = sessionStorage.getItem('cnpj_scroll_url');
        const currentFull = location.pathname + location.search;
        if (y && url === currentFull) {
            setTimeout(() => {
                _viewport.scrollTop = parseInt(y, 10);
                window.scrollTo(0, parseInt(y, 10));
            }, 50);
        }

        // Auto-redirect pra última busca — APENAS em navegação entre módulos.
        // F5/Ctrl+R (reload) deve manter estado limpo.
        const navEntries = performance.getEntriesByType('navigation');
        const isReload = navEntries.length > 0 && navEntries[0].type === 'reload';
        // Flag setada pelo redirect inline no <head> quando F5 com filtros na URL
        const justReloaded = sessionStorage.getItem('cnpj_just_reloaded');
        sessionStorage.removeItem('cnpj_just_reloaded');

        const last = sessionStorage.getItem('cnpj_last_search');
        if (last && !location.search && !isReload && !justReloaded && !window._noAutoRedirect) {
            const notice = document.createElement('div');
            notice.style.cssText = 'position:fixed;top:14px;left:50%;transform:translateX(-50%);background:#0f172a;color:#fff;padding:10px 18px;border-radius:8px;font-size:.88rem;font-weight:500;z-index:300;box-shadow:0 8px 20px rgba(0,0,0,.15);display:flex;gap:10px;align-items:center';
            notice.innerHTML = '↺ Restaurando última busca… <button onclick="window._noAutoRedirect=true;this.parentNode.remove();" style="background:#374151;color:#fff;border:none;padding:4px 10px;border-radius:5px;cursor:pointer;font-size:.78rem">Cancelar</button>';
            document.body.appendChild(notice);
            setTimeout(() => {
                if (!window._noAutoRedirect) location.href = 'cnpj.php' + last;
            }, 1200);
        }
    } catch(e) {}
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeDrawer(); closeModal(); }
    // Enter no input de busca = submit
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type === 'text') {
        const form = document.getElementById('cnpj-form');
        if (form && form.contains(e.target)) {
            e.preventDefault();
            form.submit();
        }
    }
    // Ctrl+K = foca na busca
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        document.querySelector('.search-bar input[name="q"]')?.focus();
    }
});

// Limpa URL params vazios + salva scroll + skeleton + desabilita Buscar
document.addEventListener('submit', e => {
    if (e.target.id !== 'cnpj-form') return;
    const form = e.target;
    try { sessionStorage.setItem('cnpj_scroll', String(_viewport.scrollTop || window.scrollY)); } catch(_) {}

    Array.from(form.elements).forEach(el => {
        if (el.name && el.value === '' && el.type !== 'submit') {
            el.disabled = true;
            setTimeout(() => { el.disabled = false; }, 100);
        }
    });
    const btn = document.getElementById('btn-buscar');
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Buscando…'; }

    // Mostra skeleton cards no lugar dos resultados antigos
    const list = document.querySelector('.result-list');
    if (list) {
        list.innerHTML = Array(5).fill(`
            <div class="skeleton-card">
                <div class="sk-avatar"></div>
                <div class="sk-body">
                    <div class="sk-line sk-line-name"></div>
                    <div class="sk-line sk-line-meta"></div>
                    <div class="sk-line sk-line-info"></div>
                </div>
            </div>
        `).join('');
    }

    // ── Aviso de busca lenta (3s) ──────────────────────────────────────────
    // Se ainda está carregando depois de 3s, sugere refinar os filtros.
    const _slowTimer = setTimeout(() => {
        // Só exibe se a página ainda não recarregou (btn ainda desabilitado)
        const b = document.getElementById('btn-buscar');
        if (!b || !b.disabled) return;

        let warn = document.getElementById('slow-search-warn');
        if (!warn) {
            warn = document.createElement('div');
            warn.id = 'slow-search-warn';
            warn.style.cssText = [
                'position:fixed','top:16px','left:50%','transform:translateX(-50%)',
                'background:#fff','border:1px solid #fbbf24','border-radius:12px',
                'padding:14px 20px','box-shadow:0 8px 24px rgba(0,0,0,.12)',
                'z-index:500','display:flex','align-items:center','gap:14px',
                'max-width:520px','width:90%','font-size:.88rem'
            ].join(';');
            warn.innerHTML = `
                <span style="font-size:1.4rem">⏳</span>
                <div style="flex:1">
                    <strong style="display:block;margin-bottom:3px">Busca demorada — muitos resultados</strong>
                    <span style="color:#6b7280">Adicione mais filtros (UF, vertical, porte, abertura) para resultados mais rápidos e relevantes.</span>
                </div>
                <button onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;font-size:1.1rem;color:#94a3b8;padding:4px">×</button>
            `;
            document.body.appendChild(warn);
        }
    }, 3000);

    // Guarda referência do timer no form pra cancelar se submeter novamente
    form._slowTimer = _slowTimer;
});

// ── Enrichment (sob demanda) ──────────────────────────────────────────────────
let enrichingCnpj = null;
async function runEnrich() {
    const btn = document.getElementById('btn-enrich');
    if (!btn) return;
    const cnpj = document.getElementById('drawer').dataset.cnpj;
    if (!cnpj) return;
    btn.disabled = true;
    btn.innerHTML = '⏳ Buscando em ReceitaWS + Google Maps…<small>Pode levar alguns segundos</small>';
    try {
        const r = await fetch('cnpj-api.php?action=enrich&cnpj=' + cnpj).then(r => r.json());
        renderEnrichment(r);
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = '⚠ Erro ao enriquecer. Tentar novamente.';
    }
}

function renderEnrichment(r) {
    const zone = document.getElementById('enrich-zone');
    const rws  = r.sources.receitaws;
    const pub  = r.sources.publica;
    const gp   = r.sources.gplaces;
    const fresh = rws || pub;

    let html = '<h4>📡 Dados ao vivo</h4>';

    // ReceitaWS / publica
    if (fresh) {
        const src = rws ? 'ReceitaWS' : 'publica.cnpj.ws';
        html += `<div class="enrich-block">
            <div class="enrich-src">${src} <small>${fresh._cached_at ? '· cache ' + fresh._cached_at : '· buscado agora'}</small></div>
            <dl class="dt-grid">
                ${fresh.situacao    ? `<dt>Situação</dt><dd>${esc(fresh.situacao)}${fresh.situacao_data ? ' · ' + fmtDateBr(fresh.situacao_data) : ''}</dd>` : ''}
                ${fresh.telefone    ? `<dt>Telefone</dt><dd>${esc(fresh.telefone)}</dd>` : ''}
                ${fresh.email       ? `<dt>E-mail</dt><dd>${esc(String(fresh.email).toLowerCase())}</dd>` : ''}
                ${fresh.endereco    ? `<dt>Endereço</dt><dd>${esc(fresh.endereco)}</dd>` : ''}
                ${fresh.capital_social ? `<dt>Capital social</dt><dd>R$ ${Number(fresh.capital_social).toLocaleString('pt-BR',{minimumFractionDigits:2})}</dd>` : ''}
                ${fresh.simples !== null && fresh.simples !== undefined ? `<dt>Simples</dt><dd>${fresh.simples ? 'Sim' : 'Não'}</dd>` : ''}
            </dl>
            ${fresh.atividades_secundarias && fresh.atividades_secundarias.length ? `
                <p style="font-size:.78rem;color:#6b7280;margin:8px 0 4px">Atividades secundárias (${fresh.atividades_secundarias.length}):</p>
                <ul style="padding-left:18px;margin:0;font-size:.82rem;color:#374151;max-height:140px;overflow-y:auto">
                    ${fresh.atividades_secundarias.slice(0,15).map(a => `<li>${esc(a.code || a.codigo || '')} — ${esc(a.text || a.descricao || '')}</li>`).join('')}
                </ul>` : ''}
        </div>`;
    } else {
        html += `<div class="enrich-empty">⚠ ReceitaWS indisponível agora (rate limit ou erro). Tente novamente em alguns segundos.</div>`;
    }

    // Google Places
    if (gp && gp._found) {
        const stars = gp.rating ? '⭐'.repeat(Math.round(gp.rating)) : '';
        html += `<div class="enrich-block">
            <div class="enrich-src">📍 Google Maps</div>
            ${gp.photo_url ? `<img src="${esc(gp.photo_url)}" alt="" style="width:100%;max-height:200px;object-fit:cover;border-radius:8px;margin-bottom:10px">` : ''}
            <dl class="dt-grid">
                ${gp.rating ? `<dt>Avaliação</dt><dd><strong style="color:#f59e0b">${gp.rating}</strong> ${stars} <small>(${gp.reviews_total || 0} reviews)</small></dd>` : ''}
                ${gp.business_status ? `<dt>Status</dt><dd>${gp.business_status === 'OPERATIONAL' ? '✅ Operacional' : esc(gp.business_status)}</dd>` : ''}
                ${gp.is_open_now !== null && gp.is_open_now !== undefined ? `<dt>Aberto agora</dt><dd>${gp.is_open_now ? '🟢 Sim' : '🔴 Não'}</dd>` : ''}
                ${gp.phone ? `<dt>Telefone Google</dt><dd>${esc(gp.phone)}</dd>` : ''}
                ${gp.website ? `<dt>Site</dt><dd><a href="${esc(gp.website)}" target="_blank" rel="noopener" style="color:var(--cr)">${esc(gp.website)}</a></dd>` : ''}
                ${gp.maps_url ? `<dt>Ver no Maps</dt><dd><a href="${esc(gp.maps_url)}" target="_blank" rel="noopener" style="color:var(--cr)">Abrir Google Maps →</a></dd>` : ''}
            </dl>
            ${gp.hours && gp.hours.length ? `
                <p style="font-size:.78rem;color:#6b7280;margin:8px 0 4px">Horário de funcionamento:</p>
                <ul style="padding-left:0;list-style:none;margin:0;font-size:.82rem;color:#374151">
                    ${gp.hours.map(h => `<li style="padding:2px 0">${esc(h)}</li>`).join('')}
                </ul>` : ''}
            ${gp.reviews_sample && gp.reviews_sample.length ? `
                <p style="font-size:.78rem;color:#6b7280;margin:10px 0 4px">Últimas avaliações:</p>
                ${gp.reviews_sample.map(rv => `
                    <div style="background:#f9fafb;border-radius:8px;padding:8px 10px;margin-bottom:6px;font-size:.82rem">
                        <strong>${esc(rv.author)}</strong> · ${'⭐'.repeat(rv.rating || 0)} <small style="color:#9ca3af">${esc(rv.time || '')}</small><br>
                        <span style="color:#374151">${esc(rv.text)}</span>
                    </div>`).join('')}` : ''}
        </div>`;
    } else if (gp && gp._error === 'sem_chave') {
        html += `<div class="enrich-empty">🔑 Google Places não configurado. Adicione <code>GOOGLE_PLACES_API_KEY</code> no config.php.</div>`;
    } else if (gp && gp._found === false) {
        html += `<div class="enrich-empty">📍 Empresa não encontrada no Google Maps.</div>`;
    }

    document.getElementById('enrich-zone').innerHTML = html;
}

// Delegação: botões de enrich/signals são criados dinamicamente
document.addEventListener('click', e => {
    if (e.target.closest && e.target.closest('#btn-enrich'))  runEnrich();
    if (e.target.closest && e.target.closest('#btn-signals')) runSignals();
});

// ── Análise de sinais ─────────────────────────────────────────────────────────
async function runSignals() {
    const btn = document.getElementById('btn-signals');
    if (!btn) return;
    const cnpj = document.getElementById('drawer').dataset.cnpj;
    if (!cnpj) return;
    btn.disabled = true;
    btn.innerHTML = '⏳ Analisando site, DNS, stack…<small>Pode levar 10-15 segundos</small>';
    try {
        const r = await fetch('cnpj-api.php?action=signals&cnpj=' + cnpj).then(r => r.json());
        renderSignals(r.signals || {});
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = '⚠ Erro. Tentar novamente';
    }
}

function renderSignals(s) {
    const zone = document.getElementById('signals-zone');
    const prods = s.products || {};
    const site = s.site || {};
    const dns  = s.dns  || {};

    const prodEmoji = { PASCAL: '🧠', TESLA: '🤖', ATLAS: '🏗', PRISMA: '💎' };
    const prodName  = {
        PASCAL: 'CRM e organização comercial',
        TESLA:  'Atendimento (chat/SDR)',
        ATLAS:  'Infraestrutura (e-mail/DNS/SSL)',
        PRISMA: 'Marca, UX/UI'
    };

    let prodHtml = '<div class="prod-grid">';
    ['PASCAL','TESLA','ATLAS','PRISMA'].forEach(p => {
        const pp = prods[p] || { fit:false, reasons:[] };
        const cls = pp.fit ? 'fit' : 'nofit';
        prodHtml += `
            <div class="prod-card ${cls}">
                <div class="prod-head">
                    <span class="prod-emoji">${prodEmoji[p]}</span>
                    <strong>${p}</strong>
                    <span class="prod-status">${pp.fit ? '✅ OPORTUNIDADE' : '❌ Não se aplica'}</span>
                </div>
                <small>${esc(prodName[p])}</small>
                ${pp.reasons && pp.reasons.length ? '<ul>' + pp.reasons.map(r => '<li>'+esc(r)+'</li>').join('') + '</ul>' : ''}
            </div>`;
    });
    prodHtml += '</div>';

    // Detalhes técnicos
    let tech = '<h4>🔍 Detalhes técnicos</h4><dl class="dt-grid">';
    if (s.site_domain) {
        tech += `<dt>Domínio analisado</dt><dd><a href="https://${esc(s.site_domain)}" target="_blank" rel="noopener" style="color:var(--cr)">${esc(s.site_domain)}</a></dd>`;
    }
    if (site.ok) {
        tech += `<dt>Site acessível</dt><dd>✓ ${site.has_https ? 'com HTTPS' : '<span style="color:#b91c1c">sem HTTPS</span>'}</dd>`;
        tech += `<dt>Chat widgets</dt><dd>${site.chats.length ? site.chats.join(', ') : '<span style="color:#9ca3af">nenhum</span>'}</dd>`;
        tech += `<dt>Stack detectado</dt><dd>${site.stack.length ? site.stack.join(', ') : '<span style="color:#9ca3af">nenhum</span>'}</dd>`;
        if (site.platforms.length) tech += `<dt>Plataforma</dt><dd style="color:#b45309">⚠ ${site.platforms.join(', ')}</dd>`;
        tech += `<dt>Páginas maduras</dt><dd>${site.maturity_score}/4 (${Object.keys(site.maturity).filter(k => site.maturity[k]).join(', ') || 'nenhuma'})</dd>`;
    } else {
        tech += `<dt>Site</dt><dd style="color:#b91c1c">❌ ${esc(site.reason || 'não respondeu')}</dd>`;
    }
    if (dns.ok) {
        tech += `<dt>Provedor de e-mail</dt><dd>${esc(dns.provider || '—')} ${dns.is_pro_email ? '<span style="color:#15803d">(profissional)</span>' : ''}</dd>`;
        if (dns.records && dns.records.length) tech += `<dt>MX records</dt><dd style="font-family:monospace;font-size:.78rem">${dns.records.slice(0,3).map(esc).join('<br>')}</dd>`;
    }
    if (s.email_x_dominio) {
        const ed = s.email_x_dominio;
        const cor = ed.match === true ? '#15803d' : (ed.match === false ? '#b91c1c' : '#6b7280');
        tech += `<dt>E-mail × site</dt><dd style="color:${cor}">${esc(ed.observação)}</dd>`;
    }
    tech += '</dl>';

    zone.innerHTML = '<h4>🎯 Análise de oportunidades</h4>' + prodHtml + tech +
        `<small style="display:block;margin-top:10px;color:#9ca3af">Analisado em ${esc(s._analyzed_at || '')} · cache 14 dias</small>`;
}

// ── Load more (carrega 1000 em 1000) ──────────────────────────────────────────
(function(){
    const btn = document.getElementById('load-more-btn');
    if (!btn) return;
    const initialPer = <?= (int)$per ?>; // 100
    const batchPer   = 100;              // batches subsequentes: 100 também (leve)
    let loadedSoFar  = <?= (int) count($results['rows']) ?>;
    let page = 1; // próximo offset será calculado por loadedSoFar
    const params = new URLSearchParams(<?= json_encode(array_merge($f, ['sort'=>$sort]), JSON_HEX_TAG) ?>);
    params.set('per', batchPer);

    btn.addEventListener('click', async function() {
        btn.disabled = true;
        const orig = btn.innerHTML;
        btn.innerHTML = '⏳ Carregando…';
        const status = document.getElementById('load-more-status');
        status.textContent = '';
        // Calcula página baseada no que já foi carregado
        params.set('per',  batchPer);
        params.set('page', Math.floor(loadedSoFar / batchPer) + 1);
        // Caso especial: ainda estamos na "página 1" do batchPer mas já tem initialPer cards
        // Backend retorna primeiras batchPer linhas; descartamos as primeiras initialPer no front
        try {
            const html = await fetch('cnpj-rows.php?' + params.toString()).then(r => r.text());
            if (!html.trim() || html.indexOf('<!--empty-->') === 0) {
                document.getElementById('load-more-wrap').innerHTML =
                    '<div style="color:#6b7280;font-size:.85rem">✓ Todos os resultados carregados.</div>';
                return;
            }
            const list = document.querySelector('.result-list');
            // Cria um container temp pra parsear
            const tmp = document.createElement('div');
            tmp.innerHTML = html;
            const newCards = tmp.querySelectorAll('.result-card');
            // Pular cards que já estão na lista (dedup por CNPJ)
            const existing = new Set(Array.from(list.querySelectorAll('.result-card'))
                .map(c => c.dataset.cnpj));
            let added = 0;
            newCards.forEach(c => {
                if (!existing.has(c.dataset.cnpj)) {
                    list.appendChild(c);
                    added++;
                }
            });
            loadedSoFar = list.querySelectorAll('.result-card').length;
            document.getElementById('loaded-n').textContent = loadedSoFar.toLocaleString('pt-BR');
            btn.disabled = false;
            btn.innerHTML = orig;
            if (added === 0 || newCards.length < batchPer) {
                document.getElementById('load-more-wrap').innerHTML =
                    '<div style="color:#6b7280;font-size:.85rem">✓ Todos os resultados carregados (' + loadedSoFar.toLocaleString('pt-BR') + ').</div>';
            }
        } catch(e) {
            btn.disabled = false;
            btn.innerHTML = orig;
            status.textContent = 'Erro ao carregar. Tente novamente.';
        }
    });
})();

// ── Sticky search box ────────────────────────────────────────────────────────
(function() {
    const box = document.querySelector('.search-box');
    if (!box) return;
    const sentinel = document.createElement('div');
    sentinel.style.height = '1px';
    box.parentNode.insertBefore(sentinel, box);
    const obs = new IntersectionObserver(entries => {
        box.classList.toggle('sticky', !entries[0].isIntersecting);
    }, { threshold: 1 });
    obs.observe(sentinel);
})();

// ── Filtros favoritos ────────────────────────────────────────────────────────
(function() {
    const SF_KEY = 'hermes_saved_filters';

    function loadSaved() {
        try { return JSON.parse(localStorage.getItem(SF_KEY) || '[]'); } catch(e) { return []; }
    }
    function saveSaved(arr) {
        try { localStorage.setItem(SF_KEY, JSON.stringify(arr)); } catch(e) {}
    }

    function renderSavedFilters() {
        const bar = document.getElementById('saved-filters-bar');
        if (!bar) return;
        const saved = loadSaved();
        if (!saved.length) { bar.style.display = 'none'; return; }
        bar.style.display = 'flex';
        // Remove chips antigos (deixa só o label)
        Array.from(bar.querySelectorAll('.saved-filter-chip')).forEach(c => c.remove());
        saved.forEach((item, idx) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'saved-filter-chip';
            chip.title = 'Aplicar: ' + item.label;
            chip.innerHTML = `<span>${item.label}</span><span class="sf-del" title="Remover">×</span>`;
            chip.querySelector('span:first-child').addEventListener('click', () => applyFilter(item.qs));
            chip.querySelector('.sf-del').addEventListener('click', e => { e.stopPropagation(); removeFilter(idx); });
            bar.appendChild(chip);
        });
    }

    function applyFilter(qs) {
        window.location.href = 'cnpj.php?' + qs + '&submitted=1';
    }

    function removeFilter(idx) {
        const arr = loadSaved();
        arr.splice(idx, 1);
        saveSaved(arr);
        renderSavedFilters();
    }

    window.saveCurrentFilter = function() {
        const form = document.getElementById('cnpj-form');
        if (!form) return;
        const data = new FormData(form);
        const params = new URLSearchParams();
        for (const [k, v] of data.entries()) { if (v && k !== 'submitted') params.set(k, v); }
        const qs = params.toString();
        if (!qs) { alert('Defina ao menos um filtro antes de salvar.'); return; }

        const label = prompt('Nome para este filtro favorito:', buildLabel(params));
        if (!label) return;

        const arr = loadSaved();
        if (arr.length >= 8) { alert('Máximo de 8 filtros favoritos atingido. Remova um antes de salvar.'); return; }
        arr.push({ label: label.trim().slice(0, 30), qs });
        saveSaved(arr);
        renderSavedFilters();

        // Feedback visual
        const btn = document.querySelector('[onclick="saveCurrentFilter()"]');
        if (btn) { const orig = btn.textContent; btn.textContent = '✓ Salvo!'; setTimeout(() => btn.textContent = orig, 1500); }
    };

    function buildLabel(params) {
        const parts = [];
        if (params.get('vertical')) parts.push(params.get('vertical').split('-').map(w => w[0]?.toUpperCase() + w.slice(1)).join(' '));
        if (params.get('uf'))       parts.push(params.get('uf').toUpperCase());
        if (params.get('porte'))    parts.push({ ME:'MEI/ME', EP:'EP', PP:'PP', GE:'Grande' }[params.get('porte')] || params.get('porte'));
        if (params.get('q'))        parts.push('"' + params.get('q').slice(0, 20) + '"');
        return parts.join(' · ') || 'Filtro personalizado';
    }

    renderSavedFilters();
})();
</script>
</body>
</html>
<?php
});
