<?php
/**
 * HERMES.b2b — Leads Vistos
 * Lista de CNPJs que o usuário abriu (consumiu crédito) este mês
 * mas ainda não adicionou ao Pipeline.
 */
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../core/cnpj_db.php';

$tenant_id = (int) $tenant['id'];

// ── 1. Busca registros de visualização do mês atual ───────────────────────────
// filters_json = {"_view":"14digitCNPJ"} para drawer views
$mes_inicio = date('Y-m-01');

$log_rows = db_all(
    "SELECT
         JSON_UNQUOTE(JSON_EXTRACT(filters_json, '$.\"_view\"')) AS cnpj14,
         MAX(downloaded_at) AS last_viewed,
         COUNT(*) AS views
     FROM cnpj_download_log
     WHERE tenant_id = ?
       AND downloaded_at >= ?
       AND filters_json LIKE '%\"_view\"%'
     GROUP BY cnpj14
     ORDER BY last_viewed DESC
     LIMIT 200",
    [$tenant_id, $mes_inicio]
);

// Filtra CNPJs válidos (14 dígitos)
$viewed_cnpjs = [];
foreach ($log_rows as $r) {
    $c = preg_replace('/\D/', '', (string)($r['cnpj14'] ?? ''));
    if (strlen($c) === 14) {
        $viewed_cnpjs[$c] = $r;
    }
}

$total_viewed = count($viewed_cnpjs);

// ── 2. Busca nomes no PostgreSQL (batch) ──────────────────────────────────────
$pg_info = []; // cnpj14 => [razao_social, nome_fantasia, situacao_cadastral, uf, cidade]

if (!empty($viewed_cnpjs)) {
    $cnpj_list = array_keys($viewed_cnpjs);
    // Monta OR com (basico, ordem, dv) separados — usa índices individuais
    $parts  = [];
    $params = [];
    foreach ($cnpj_list as $c) {
        $parts[]  = "(e.cnpj_basico = ? AND e.cnpj_ordem = ? AND e.cnpj_dv = ?)";
        $params[] = substr($c, 0, 8);
        $params[] = substr($c, 8, 4);
        $params[] = substr($c, 12, 2);
    }
    $where_pg = implode(' OR ', $parts);

    try {
        cnpj_db()->exec("SET statement_timeout = '15s'");
        $pg_rows = cnpj_all(
            "SELECT
                 e.cnpj_basico || e.cnpj_ordem || e.cnpj_dv AS cnpj,
                 COALESCE(NULLIF(TRIM(emp.razao_social), ''), NULLIF(TRIM(e.nome_fantasia), ''), 'N/D') AS razao_social,
                 TRIM(e.nome_fantasia) AS nome_fantasia,
                 e.situacao_cadastral,
                 e.uf,
                 COALESCE(TRIM(mun.descricao), e.municipio::text) AS cidade,
                 e.cnae_principal,
                 COALESCE(TRIM(cn.descricao), e.cnae_principal::text) AS cnae_descricao,
                 e.email,
                 e.ddd1, e.telefone1
             FROM rf_estabelecimentos e
             LEFT JOIN rf_empresas   emp ON emp.cnpj_basico = e.cnpj_basico
             LEFT JOIN rf_municipios mun ON mun.codigo::text = e.municipio::text
             LEFT JOIN rf_cnaes      cn  ON cn.codigo = e.cnae_principal
             WHERE ($where_pg)",
            $params
        );
        cnpj_db()->exec("SET statement_timeout = 0");
        foreach ($pg_rows as $row) {
            $pg_info[$row['cnpj']] = $row;
        }
    } catch (\Throwable $e) {
        // PG offline — continua sem nomes
    }
}

// ── 3. Cross-referência com crm_cards ─────────────────────────────────────────
// Verifica quais CNPJs já estão no Pipeline deste tenant
$in_crm = []; // Set de cnpj14

if (!empty($viewed_cnpjs)) {
    $crm_rows = db_all(
        "SELECT DISTINCT REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', '') AS cnpj_clean
         FROM crm_cards
         WHERE tenant_id = ? AND cnpj IS NOT NULL AND cnpj <> ''",
        [$tenant_id]
    );
    foreach ($crm_rows as $r) {
        $clean = preg_replace('/\D/', '', $r['cnpj_clean'] ?? '');
        if ($clean) $in_crm[$clean] = true;
    }
}

// ── 4. Colunas Pipeline para o select no modal ────────────────────────────────
$columns = db_all(
    "SELECT id, name FROM crm_columns WHERE tenant_id = ? ORDER BY position ASC",
    [$tenant_id]
);

// ── 5. Monta a lista final ────────────────────────────────────────────────────
$leads = [];
foreach ($viewed_cnpjs as $cnpj14 => $log) {
    $info = $pg_info[$cnpj14] ?? null;
    $leads[] = [
        'cnpj'         => $cnpj14,
        'razao_social' => $info['razao_social'] ?? 'CNPJ ' . $cnpj14,
        'nome_fantasia'=> $info['nome_fantasia'] ?? '',
        'situacao'     => $info['situacao_cadastral'] ?? '',
        'uf'           => $info['uf'] ?? '',
        'cidade'       => $info['cidade'] ?? '',
        'cnae'         => $info['cnae_descricao'] ?? '',
        'email'        => $info['email'] ?? '',
        'tel'          => trim(($info['ddd1'] ?? '') . ' ' . ($info['telefone1'] ?? '')),
        'last_viewed'  => $log['last_viewed'],
        'views'        => (int)$log['views'],
        'in_crm'       => isset($in_crm[$cnpj14]),
    ];
}

// Conta pendentes (vistos, não no CRM)
$pendentes = count(array_filter($leads, fn($l) => !$l['in_crm']));

// ── Formata CNPJ para exibição ────────────────────────────────────────────────
function fmt_cnpj(string $c): string {
    return strlen($c) === 14
        ? substr($c,0,2).'.'.substr($c,2,3).'.'.substr($c,5,3).'/'.substr($c,8,4).'-'.substr($c,12,2)
        : $c;
}

app_layout('Leads Vistos', 'leads_vistos', function() use ($leads, $columns, $total_viewed, $pendentes, $mes_inicio) {
?>
<style>
.lv-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.25rem; flex-wrap:wrap; gap:.75rem; }
.lv-header h1 { font-size:1.2rem; font-weight:800; letter-spacing:-.02em; }
.lv-meta { font-size:.8rem; color:var(--mute); display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
.lv-badge { display:inline-flex; align-items:center; gap:4px; font-family:'Geist Mono',monospace; font-size:.68rem; font-weight:600; padding:3px 9px; border-radius:99px; }
.lv-badge.total  { background:#f0fdf4; color:#166534; border:1px solid #86efac; }
.lv-badge.pend   { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
.lv-badge.in-crm { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }

.lv-filters { display:flex; align-items:center; gap:.6rem; margin-bottom:1rem; flex-wrap:wrap; }
.lv-filters input[type=text] { padding:8px 12px; border:1px solid var(--border); border-radius:8px; font-size:.85rem; font-family:inherit; background:#fff; color:var(--ink); min-width:220px; }
.lv-filters input[type=text]:focus { outline:none; border-color:var(--hermes); box-shadow:0 0 0 3px rgba(16,185,129,.1); }
.lv-filter-btn { padding:7px 13px; border:1px solid var(--border); border-radius:8px; font-size:.8rem; font-family:inherit; background:#fff; color:var(--ink-2); cursor:pointer; font-weight:500; transition:all .15s; }
.lv-filter-btn.active { background:var(--hermes); color:#fff; border-color:var(--hermes); }
.lv-filter-btn:hover:not(.active) { background:var(--bone); }

.lv-table-wrap { background:#fff; border:1px solid var(--border); border-radius:14px; overflow:hidden; }
.lv-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.lv-table th { text-align:left; padding:11px 16px; font-family:'Geist Mono',monospace; font-size:.6rem; font-weight:600; color:var(--mute); text-transform:uppercase; letter-spacing:.08em; border-bottom:1px solid var(--border); background:var(--bone); }
.lv-table td { padding:12px 16px; border-bottom:1px solid var(--border); vertical-align:middle; }
.lv-table tr:last-child td { border-bottom:none; }
.lv-table tr:hover td { background:var(--bone); }
.lv-table .name { font-weight:600; color:var(--ink); line-height:1.3; }
.lv-table .name .fantasia { font-weight:400; font-size:.78rem; color:var(--mute); display:block; margin-top:1px; }
.lv-table .cnpj-fmt { font-family:'Geist Mono',monospace; font-size:.78rem; color:var(--mute); }
.lv-table .local { font-size:.78rem; color:var(--mute); }
.lv-table .cnae-cell { font-size:.75rem; color:var(--mute); max-width:180px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.lv-table .date-cell { font-family:'Geist Mono',monospace; font-size:.75rem; color:var(--mute); white-space:nowrap; }
.badge-status { display:inline-block; font-family:'Geist Mono',monospace; font-size:.6rem; font-weight:600; padding:2px 7px; border-radius:4px; text-transform:uppercase; letter-spacing:.04em; }
.badge-status.ativa   { background:#f0fdf4; color:#166534; }
.badge-status.inativa { background:#fef2f2; color:#991b1b; }
.badge-status.em-crm  { background:#eff6ff; color:#1e40af; }

.btn-add-crm { padding:6px 14px; background:var(--hermes); color:#fff; border:none; border-radius:7px; font-size:.78rem; font-weight:600; cursor:pointer; font-family:inherit; transition:background .15s; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; }
.btn-add-crm:hover { background:#0ea371; }
.btn-add-crm:disabled { opacity:.5; cursor:not-allowed; }
.btn-in-crm { padding:6px 14px; background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; border-radius:7px; font-size:.78rem; font-weight:600; font-family:inherit; cursor:default; white-space:nowrap; display:inline-flex; align-items:center; gap:5px; }

.lv-empty { text-align:center; padding:60px 20px; color:var(--mute); }
.lv-empty svg { width:48px; height:48px; stroke:var(--mute); opacity:.35; margin:0 auto 16px; display:block; }
.lv-empty h3 { font-size:1rem; font-weight:600; color:var(--ink-2); margin-bottom:6px; }
.lv-empty p  { font-size:.85rem; line-height:1.55; max-width:340px; margin:0 auto; }

.lv-month-badge { font-family:'Geist Mono',monospace; font-size:.68rem; color:var(--mute); background:var(--bone); border:1px solid var(--border); border-radius:6px; padding:3px 8px; }

/* Modal adicionar ao pipeline */
.lv-modal-bg { display:none; position:fixed; inset:0; background:rgba(15,23,42,.5); z-index:500; align-items:center; justify-content:center; }
.lv-modal-bg.open { display:flex; }
.lv-modal { background:#fff; border-radius:14px; padding:28px; width:100%; max-width:460px; box-shadow:0 20px 60px rgba(0,0,0,.18); }
.lv-modal h3 { font-size:1rem; font-weight:700; margin-bottom:4px; }
.lv-modal .lv-modal-sub { font-size:.83rem; color:var(--mute); margin-bottom:20px; }
.lv-modal label { display:block; font-family:'Geist Mono',monospace; font-size:.62rem; color:var(--mute); text-transform:uppercase; letter-spacing:.06em; font-weight:600; margin-bottom:6px; }
.lv-modal select, .lv-modal input[type=text] { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:8px; font-size:.88rem; font-family:inherit; color:var(--ink); background:#fff; margin-bottom:14px; }
.lv-modal select:focus, .lv-modal input[type=text]:focus { outline:none; border-color:var(--hermes); box-shadow:0 0 0 3px rgba(16,185,129,.1); }
.lv-modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:6px; }
.lv-modal-actions button { padding:9px 18px; border-radius:8px; font-size:.88rem; font-weight:600; cursor:pointer; font-family:inherit; border:none; }
.btn-cancel { background:var(--bone); color:var(--ink-2); border:1px solid var(--border) !important; }
.btn-cancel:hover { background:var(--line); }
.btn-confirm { background:var(--hermes); color:#fff; }
.btn-confirm:hover { background:#0ea371; }
.btn-confirm:disabled { opacity:.5; cursor:not-allowed; }
</style>

<div class="lv-header">
  <div>
    <h1>Leads Vistos</h1>
    <div class="lv-meta">
      <span class="lv-month-badge"><?= date('M/Y', strtotime($mes_inicio)) ?></span>
      <span class="lv-badge total">👁 <?= $total_viewed ?> visualizados</span>
      <?php if ($pendentes > 0): ?>
        <span class="lv-badge pend">⏳ <?= $pendentes ?> aguardando</span>
      <?php endif; ?>
      <?php $no_crm_count = $total_viewed - $pendentes; if ($no_crm_count > 0): ?>
        <span class="lv-badge in-crm">✓ <?= $no_crm_count ?> no Pipeline</span>
      <?php endif; ?>
    </div>
  </div>
  <a href="cnpj.php" class="btn-action secondary" style="text-decoration:none">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
    </svg>
    Buscar mais leads
  </a>
</div>

<?php if (empty($leads)): ?>
<div class="lv-table-wrap">
  <div class="lv-empty">
    <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
    </svg>
    <h3>Nenhum lead visualizado este mês</h3>
    <p>Quando você abrir o detalhamento de um CNPJ no Radar Leads, ele aparecerá aqui.</p>
  </div>
</div>
<?php else: ?>

<div class="lv-filters">
  <input type="text" id="lv-search" placeholder="Buscar por nome, CNPJ, cidade…" oninput="lvFilter()">
  <button class="lv-filter-btn active" id="btn-todos"    onclick="lvSetFilter('todos')">Todos (<?= $total_viewed ?>)</button>
  <button class="lv-filter-btn"       id="btn-pendentes" onclick="lvSetFilter('pendentes')">Adicionar ao CRM (<?= $pendentes ?>)</button>
  <button class="lv-filter-btn"       id="btn-in-crm"    onclick="lvSetFilter('in-crm')">No Pipeline (<?= $total_viewed - $pendentes ?>)</button>
</div>

<div class="lv-table-wrap">
  <table class="lv-table" id="lv-table">
    <thead>
      <tr>
        <th>Empresa</th>
        <th>Localização</th>
        <th>Segmento</th>
        <th>Visto em</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($leads as $l): ?>
      <?php
        $statusClass = $l['situacao'] === '02' ? 'ativa' : 'inativa';
        $statusLabel = match($l['situacao']) {
            '02' => 'Ativa', '03' => 'Suspensa', '04' => 'Inapta',
            '08' => 'Baixada', default => 'N/D'
        };
        $cnpjFmt = fmt_cnpj($l['cnpj']);
        $locStr  = array_filter([$l['cidade'], $l['uf']]);
        $locStr  = implode(' – ', $locStr);
        $dataStr = $l['last_viewed'] ? date('d/m H\hi', strtotime($l['last_viewed'])) : '';
      ?>
      <tr class="lv-row"
          data-cnpj="<?= e($l['cnpj']) ?>"
          data-name="<?= e(strtolower($l['razao_social'])) ?>"
          data-city="<?= e(strtolower($locStr)) ?>"
          data-status="<?= $l['in_crm'] ? 'in-crm' : 'pendentes' ?>">
        <td>
          <div class="name">
            <?= e($l['razao_social']) ?>
            <?php if ($l['nome_fantasia'] && $l['nome_fantasia'] !== $l['razao_social']): ?>
              <span class="fantasia"><?= e($l['nome_fantasia']) ?></span>
            <?php endif; ?>
          </div>
          <div class="cnpj-fmt"><?= e($cnpjFmt) ?></div>
        </td>
        <td class="local"><?= e($locStr ?: '—') ?></td>
        <td class="cnae-cell" title="<?= e($l['cnae']) ?>"><?= e($l['cnae'] ?: '—') ?></td>
        <td class="date-cell"><?= e($dataStr) ?></td>
        <td>
          <?php if ($l['in_crm']): ?>
            <span class="badge-status em-crm">✓ Pipeline</span>
          <?php else: ?>
            <span class="badge-status <?= $statusClass ?>"><?= $statusLabel ?></span>
          <?php endif; ?>
        </td>
        <td style="text-align:right">
          <?php if ($l['in_crm']): ?>
            <span class="btn-in-crm">
              <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
              No Pipeline
            </span>
          <?php else: ?>
            <button class="btn-add-crm" onclick="lvOpenModal('<?= e($l['cnpj']) ?>', <?= json_encode($l['razao_social']) ?>)">
              <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
              </svg>
              Pipeline
            </button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Modal: escolher coluna do pipeline -->
<div class="lv-modal-bg" id="lv-modal-bg" onclick="if(event.target.id==='lv-modal-bg')lvCloseModal()">
  <div class="lv-modal">
    <h3>Adicionar ao Pipeline</h3>
    <p class="lv-modal-sub" id="lv-modal-company">—</p>

    <label>Coluna</label>
    <select id="lv-col-select">
      <?php foreach ($columns as $col): ?>
        <option value="<?= (int)$col['id'] ?>"><?= e($col['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Anotação inicial <small style="font-weight:400;text-transform:none;letter-spacing:0">(opcional)</small></label>
    <input type="text" id="lv-modal-note" placeholder="Ex: cliente indicado, urgência alta…">

    <div class="lv-modal-actions">
      <button class="btn-cancel" onclick="lvCloseModal()">Cancelar</button>
      <button class="btn-confirm" id="lv-modal-confirm" onclick="lvConfirmAdd()">
        Adicionar ao Pipeline →
      </button>
    </div>
  </div>
</div>

<script>
// ── Filtro client-side ────────────────────────────────────────────────────────
let _lvFilter = 'todos';

function lvSetFilter(f) {
    _lvFilter = f;
    document.querySelectorAll('.lv-filter-btn').forEach(b => b.classList.remove('active'));
    const id = f === 'todos' ? 'btn-todos' : f === 'pendentes' ? 'btn-pendentes' : 'btn-in-crm';
    document.getElementById(id)?.classList.add('active');
    lvFilter();
}

function lvFilter() {
    const q = (document.getElementById('lv-search')?.value || '').toLowerCase().trim();
    document.querySelectorAll('.lv-row').forEach(row => {
        const matchSearch = !q ||
            row.dataset.name.includes(q) ||
            row.dataset.cnpj.includes(q.replace(/\D/g,'')) ||
            row.dataset.city.includes(q);
        const matchStatus = _lvFilter === 'todos' ||
            row.dataset.status === _lvFilter;
        row.style.display = (matchSearch && matchStatus) ? '' : 'none';
    });
}

// ── Modal ─────────────────────────────────────────────────────────────────────
let _lvPendingCnpj = '';
const _lvCsrf = <?= json_encode(csrf_token()) ?>;

function lvOpenModal(cnpj, name) {
    _lvPendingCnpj = cnpj;
    document.getElementById('lv-modal-company').textContent = name;
    document.getElementById('lv-modal-note').value = '';
    const btn = document.getElementById('lv-modal-confirm');
    btn.disabled = false;
    btn.textContent = 'Adicionar ao Pipeline →';
    document.getElementById('lv-modal-bg').classList.add('open');
    setTimeout(() => document.getElementById('lv-modal-note').focus(), 80);
}

function lvCloseModal() {
    document.getElementById('lv-modal-bg').classList.remove('open');
    _lvPendingCnpj = '';
}

async function lvConfirmAdd() {
    if (!_lvPendingCnpj) return;
    const btn   = document.getElementById('lv-modal-confirm');
    const colId = document.getElementById('lv-col-select').value;
    const note  = document.getElementById('lv-modal-note').value.trim();
    const cnpj  = _lvPendingCnpj;

    btn.disabled = true;
    btn.textContent = 'Buscando dados…';

    // 1. Carrega detalhes do CNPJ
    let detail;
    try {
        const res  = await fetch('cnpj-api.php?action=detail&cnpj=' + encodeURIComponent(cnpj));
        detail = await res.json();
        if (!detail || detail.error) throw new Error(detail?.error || 'Não encontrado');
    } catch (err) {
        alert('Erro ao buscar dados do CNPJ: ' + err.message);
        btn.disabled = false;
        btn.textContent = 'Adicionar ao Pipeline →';
        return;
    }

    btn.textContent = 'Adicionando…';

    // 2. POST para crm.php — add_from_cnpj
    // O action já tem dedup: se o CNPJ foi visto no drawer este mês, não cobra de novo.
    const cidade_uf = [detail.municipio_nome, detail.uf].filter(Boolean).join(' – ');
    const tel = detail.ddd1 ? (detail.ddd1 + detail.telefone1) : (detail.telefone1 || '');
    const cardData = {
        cnpj:         cnpj,
        razao_social: detail.razao_social    || '',
        nome_fantasia:detail.nome_fantasia   || '',
        telefone:     tel,
        email:        detail.email           || '',
        cidade_uf:    cidade_uf,
        cnae:         detail.cnae_descricao  || '',
        notes:        note,
        score:        detail.newton_score    || 0,
    };
    const fd = new FormData();
    fd.append('_csrf',     _lvCsrf);
    fd.append('action',    'add_from_cnpj');
    fd.append('column_id', colId);
    fd.append('data',      JSON.stringify(cardData));

    try {
        const r   = await fetch('crm.php', { method: 'POST', body: fd });
        const res = await r.json();
        if (!res.ok) throw new Error(res.error || 'Erro ao adicionar');
    } catch (err) {
        alert('Erro: ' + err.message);
        btn.disabled = false;
        btn.textContent = 'Adicionar ao Pipeline →';
        return;
    }

    // 3. Atualiza a UI sem recarregar
    lvCloseModal();
    const row = document.querySelector(`.lv-row[data-cnpj="${cnpj}"]`);
    if (row) {
        row.dataset.status = 'in-crm';
        // Atualiza célula de status
        const statusCell = row.querySelector('.badge-status');
        if (statusCell) { statusCell.className = 'badge-status em-crm'; statusCell.textContent = '✓ Pipeline'; }
        // Substitui botão
        const actionCell = row.querySelector('.btn-add-crm');
        if (actionCell) {
            actionCell.outerHTML = `<span class="btn-in-crm">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                No Pipeline
            </span>`;
        }
        // Atualiza contadores no header (aproximado)
        const pendBadge = document.querySelector('.lv-badge.pend');
        if (pendBadge) {
            const num = parseInt(pendBadge.textContent) - 1;
            if (num > 0) pendBadge.innerHTML = `⏳ ${num} aguardando`;
            else pendBadge.style.display = 'none';
        }
        // Re-aplica filtro (esconde a linha se filtro=pendentes)
        lvFilter();
    }
}

// Fecha modal com Esc
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.getElementById('lv-modal-bg').classList.contains('open')) lvCloseModal();
});
</script>
<?php });
