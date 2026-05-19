<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_layout.php';
@set_time_limit(30);

$tenant = require_tenant();
$tid    = (int) $tenant['id'];

// ── Ações POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_filter') {
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $fjson = $_POST['filter_json'] ?? '{}';
        if ($name !== '') {
            db_insert('cnpj_lists', [
                'tenant_id'   => $tid,
                'name'        => mb_substr($name, 0, 200),
                'description' => $desc ?: null,
                'filter_json' => $fjson,
            ]);
            flash_set('success', 'Lista salva com sucesso!');
        }
        header('Location: cnpj-lists.php');
        exit;
    }

    if ($action === 'delete') {
        $lid = (int) ($_POST['list_id'] ?? 0);
        if ($lid) {
            db_delete('cnpj_lists', 'id = :id AND tenant_id = :tid', ['id' => $lid, 'tid' => $tid]);
            flash_set('info', 'Lista removida.');
        }
        header('Location: cnpj-lists.php');
        exit;
    }
}

// ── Exportar itens de uma lista (re-executa o filtro salvo) ─────────────────
if (isset($_GET['export'])) {
    $lid  = (int) $_GET['export'];
    $list = db_one('SELECT * FROM cnpj_lists WHERE id = ? AND tenant_id = ?', [$lid, $tid]);
    if (!$list) { http_response_code(404); exit('Lista não encontrada.'); }
    // Redireciona pro export que respeita quota
    $filter = $list['filter_json'] ? json_decode($list['filter_json'], true) : [];
    $qs = http_build_query(array_filter((array)$filter, fn($v) => $v !== '' && $v !== null));
    header('Location: cnpj-export.php?' . $qs);
    exit;
}

// ── Visualizar itens (re-roda o filtro) ─────────────────────────────────────
$view_list   = null;
$view_result = ['rows' => [], 'total' => 0];
if (isset($_GET['view'])) {
    $lid       = (int) $_GET['view'];
    $view_list = db_one('SELECT * FROM cnpj_lists WHERE id = ? AND tenant_id = ?', [$lid, $tid]);
    if ($view_list) {
        $filter = $view_list['filter_json'] ? json_decode($view_list['filter_json'], true) : [];
        $view_result = cnpj_search($filter, 1, 100, 'razao');
    }
}

$lists = db_all(
    'SELECT * FROM cnpj_lists WHERE tenant_id = ? ORDER BY created_at DESC',
    [$tid]
);

app_layout('Listas CNPJ', 'cnpj', function() use ($lists, $view_list, $view_result, $tid) {
?>
<style>
.list-grid     { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:16px; }
.list-card     { background:#fff; border-radius:12px; padding:18px; box-shadow:0 1px 4px rgba(0,0,0,.06); display:flex; flex-direction:column; gap:10px; }
.list-card h3  { font-size:.95rem; font-weight:700; margin:0; color:#111827; }
.list-meta     { font-size:.75rem; color:#6b7280; }
.list-actions  { display:flex; gap:6px; flex-wrap:wrap; margin-top:auto; }
.tag-pill      { display:inline-block; background:#eef2ff; color:#4338ca; border-radius:999px; padding:2px 9px; font-size:.7rem; font-weight:600; margin-right:4px; margin-bottom:3px; }
.btn-list      { background:#f3f4f6; color:#374151; border:none; border-radius:6px; padding:6px 12px; font-size:.78rem; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
.btn-list:hover{ background:#e0e7ff; color:#4338ca; }
.btn-list.danger{ color:#b91c1c; }
.btn-list.danger:hover{ background:#fee2e2; color:#7f1d1d; }
.tbl           { width:100%; border-collapse:collapse; font-size:.82rem; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.06); }
.tbl th        { text-align:left; padding:10px 12px; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; background:#f9fafb; border-bottom:1px solid #e5e7eb; }
.tbl td        { padding:10px 12px; border-bottom:1px solid #f3f4f6; }
.tbl tr:last-child td { border-bottom:none; }
.cnpj-mono     { font-family:monospace; font-size:.78rem; }
</style>

<?= flash_render() ?>

<?php if ($view_list): ?>
  <div style="display:flex;align-items:center;gap:14px;margin-bottom:18px">
    <a href="cnpj-lists.php" class="btn-list">← Voltar</a>
    <div style="flex:1">
      <h2 style="font-size:1.15rem;font-weight:800;margin:0"><?= e($view_list['name']) ?></h2>
      <?php if ($view_list['description']): ?>
        <p style="font-size:.82rem;color:#6b7280;margin:2px 0 0"><?= e($view_list['description']) ?></p>
      <?php endif; ?>
      <div style="font-size:.78rem;color:#6b7280;margin-top:4px">
        <b><?= number_format($view_result['total'], 0, ',', '.') ?><?= ($view_result['total_more']??false)?'+':'' ?></b> empresa(s) — mostrando primeiras <?= count($view_result['rows']) ?>
      </div>
    </div>
    <a href="cnpj-export.php?<?= e(http_build_query(array_filter((array)json_decode($view_list['filter_json'],true)))) ?>" class="btn-list" style="background:#22c55e;color:#fff">⬇ Exportar CSV</a>
    <a href="cnpj.php?<?= e(http_build_query(array_filter((array)json_decode($view_list['filter_json'],true)))) ?>" class="btn-list">Reabrir busca →</a>
  </div>

  <?php if (!empty($view_result['rows'])): ?>
    <table class="tbl">
      <thead>
        <tr>
          <th>CNPJ</th><th>Razão Social</th><th>UF</th><th>Município</th>
          <th>CNAE</th><th>Telefone</th><th>E-mail</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($view_result['rows'] as $r):
          $tel = cnpj_tel_fmt($r['ddd1']??null, $r['telefone1']??null);
        ?>
          <tr>
            <td class="cnpj-mono"><?= e(cnpj_fmt($r['cnpj'])) ?></td>
            <td><strong><?= e($r['razao_social']) ?></strong><?php if($r['nome_fantasia']): ?><br><small style="color:#6b7280"><?= e($r['nome_fantasia']) ?></small><?php endif; ?></td>
            <td><?= e($r['uf']) ?></td>
            <td><?= e($r['municipio_nome'] ?? $r['municipio']) ?></td>
            <td title="<?= e($r['cnae_principal']) ?>"><?= e(mb_strimwidth($r['cnae_descricao'] ?? $r['cnae_principal'], 0, 40, '…')) ?></td>
            <td><?= e($tel ?: '—') ?></td>
            <td><?= $r['email'] ? '<a href="mailto:'.e($r['email']).'" style="color:var(--cr)">' . e($r['email']) . '</a>' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div style="background:#fff;border-radius:12px;padding:40px;text-align:center;color:#6b7280;box-shadow:0 1px 4px rgba(0,0,0,.06)">
      Nenhuma empresa encontrada para esse filtro hoje.
    </div>
  <?php endif; ?>

<?php else: ?>

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
    <div>
      <h2 style="font-size:1.15rem;font-weight:800;margin:0">Listas salvas</h2>
      <p style="font-size:.82rem;color:#6b7280;margin-top:4px">Filtros guardados para usar em campanhas e disparos.</p>
    </div>
    <a href="cnpj.php" class="btn-list" style="background:var(--cr);color:#fff;padding:8px 16px">+ Nova busca</a>
  </div>

  <?php if ($lists): ?>
    <div class="list-grid">
      <?php foreach ($lists as $l):
        $filter = $l['filter_json'] ? json_decode($l['filter_json'], true) : [];
        $tags   = [];
        if (!empty($filter['uf']))        $tags[] = 'UF ' . $filter['uf'];
        if (!empty($filter['situacao']))  $tags[] = cnpj_situacao_label($filter['situacao']);
        if (!empty($filter['cnae']))      $tags[] = 'CNAE ' . $filter['cnae'];
        if (!empty($filter['porte']) && isset(CNPJ_PORTES[$filter['porte']])) $tags[] = CNPJ_PORTES[$filter['porte']];
        if (!empty($filter['tem_email'])) $tags[] = 'c/ e-mail';
        if (!empty($filter['tem_tel']))   $tags[] = 'c/ telefone';
        if (!empty($filter['mei']))       $tags[] = 'MEI';
        if (!empty($filter['simples']))   $tags[] = 'Simples';
        $fq = http_build_query(array_filter((array)$filter, fn($v) => $v !== '' && $v !== null));
      ?>
        <div class="list-card">
          <h3><?= e($l['name']) ?></h3>
          <?php if ($l['description']): ?>
            <p style="font-size:.82rem;color:#6b7280;margin:0"><?= e(mb_substr($l['description'], 0, 120)) ?></p>
          <?php endif; ?>
          <div>
            <?php foreach ($tags as $t): ?>
              <span class="tag-pill"><?= e($t) ?></span>
            <?php endforeach; ?>
          </div>
          <div class="list-meta">Criada em <?= date('d/m/Y H:i', strtotime($l['created_at'])) ?></div>
          <div class="list-actions">
            <a href="cnpj-lists.php?view=<?= $l['id'] ?>" class="btn-list">👁 Ver</a>
            <a href="cnpj.php?<?= $fq ?>" class="btn-list">🔍 Reabrir</a>
            <a href="cnpj-export.php?<?= $fq ?>" class="btn-list" style="background:#dcfce7;color:#15803d">⬇ CSV</a>
            <form method="post" style="margin:0;margin-left:auto" onsubmit="return confirm('Excluir esta lista?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="list_id" value="<?= $l['id'] ?>">
              <button type="submit" class="btn-list danger">🗑</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div style="background:#fff;border-radius:12px;padding:60px 30px;text-align:center;color:#6b7280;box-shadow:0 1px 4px rgba(0,0,0,.06)">
      <div style="font-size:3rem;margin-bottom:12px">📋</div>
      <h3 style="margin:0 0 6px;color:#111827;font-weight:700">Nenhuma lista salva ainda</h3>
      <p style="font-size:.88rem;margin:0 0 18px">Faça uma busca em <strong>Newton CNPJ</strong> e clique em <strong>Salvar lista</strong> para guardar seus filtros.</p>
      <a href="cnpj.php" class="btn-list" style="background:var(--cr);color:#fff;padding:10px 20px;font-size:.9rem">Ir para Newton CNPJ →</a>
    </div>
  <?php endif; ?>

<?php endif; ?>
<?php
});
