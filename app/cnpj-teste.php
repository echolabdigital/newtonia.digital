<?php
/**
 * Newton CNPJ — TESTE SIMPLES
 * Query direta, sem ORM, sem CTE, sem 2-fases. Só pra provar que funciona.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_layout.php';
@set_time_limit(30);

$tenant = require_tenant();

$uf       = strtoupper(trim($_GET['uf'] ?? 'SC'));
$cnae     = trim($_GET['cnae'] ?? '');
$tem_email = !empty($_GET['tem_email']);
$tem_tel   = !empty($_GET['tem_tel']);

$conds  = ["e.situacao_cadastral = '02'"];
$params = [];

if ($uf)       { $conds[] = "e.uf = ?";              $params[] = $uf; }
if ($cnae)     { $conds[] = "e.cnae_principal = ?";  $params[] = $cnae; }
if ($tem_email){ $conds[] = "e.email IS NOT NULL AND e.email <> ''"; }
if ($tem_tel)  { $conds[] = "e.telefone1 IS NOT NULL AND e.telefone1 <> ''"; }

$where = 'WHERE ' . implode(' AND ', $conds);

$rows = [];
$err  = '';
$elapsed = 0;

try {
    cnpj_db()->exec("SET statement_timeout = '15s'");
    $t0 = microtime(true);
    $rows = cnpj_all(
        "SELECT
            e.cnpj_basico || e.cnpj_ordem || e.cnpj_dv AS cnpj,
            e.nome_fantasia,
            e.uf,
            e.municipio,
            e.cnae_principal,
            e.email,
            e.ddd1, e.telefone1
         FROM rf_estabelecimentos e
         $where
         LIMIT 50",
        $params
    );
    $elapsed = round((microtime(true) - $t0) * 1000);
} catch (\Throwable $e) {
    $err = $e->getMessage();
}

app_layout('Newton CNPJ — Teste', 'cnpj', function() use ($rows, $err, $elapsed, $uf, $cnae, $tem_email, $tem_tel) {
?>
<style>
  .test-page { max-width:1200px; }
  .test-form { background:#fff; padding:16px; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.06); display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin-bottom:16px; }
  .test-form label { font-size:.7rem; color:#64748b; text-transform:uppercase; font-weight:600; display:block; margin-bottom:3px; }
  .test-form input, .test-form select { padding:8px 12px; border:1px solid #e2e8f0; border-radius:8px; font-size:.9rem; }
  .test-form button { background:#6366f1; color:#fff; border:none; padding:9px 20px; border-radius:8px; font-weight:600; cursor:pointer; }
  .test-meta { background:#dcfce7; color:#15803d; padding:10px 14px; border-radius:8px; margin-bottom:12px; font-size:.88rem; }
  .test-err { background:#fee2e2; color:#b91c1c; padding:14px; border-radius:8px; margin-bottom:12px; font-family:monospace; font-size:.82rem; white-space:pre-wrap; word-break:break-all; }
  table.test-tbl { width:100%; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.06); border-collapse:collapse; font-size:.85rem; }
  table.test-tbl th { background:#f8fafc; text-align:left; padding:10px 14px; font-weight:600; color:#475569; font-size:.75rem; text-transform:uppercase; }
  table.test-tbl td { padding:10px 14px; border-top:1px solid #f1f5f9; }
  table.test-tbl tr:hover td { background:#f8fafc; }
</style>

<div class="test-page">
  <h2 style="margin:0 0 14px;font-size:1.1rem">🧪 Newton CNPJ — Teste de busca simples</h2>
  <p style="color:#64748b;margin:0 0 14px;font-size:.88rem">Sem CTE, sem multi-fase, sem chips. Só pra provar que a query funciona.</p>

  <form class="test-form" method="get">
    <div>
      <label>UF</label>
      <input type="text" name="uf" value="<?= e($uf) ?>" maxlength="2" style="width:60px;text-transform:uppercase">
    </div>
    <div>
      <label>CNAE (código 7 dígitos)</label>
      <input type="text" name="cnae" value="<?= e($cnae) ?>" placeholder="ex: 6201500" style="width:120px">
    </div>
    <div>
      <label><input type="checkbox" name="tem_email" value="1" <?= $tem_email?'checked':'' ?>> Com e-mail</label>
    </div>
    <div>
      <label><input type="checkbox" name="tem_tel" value="1" <?= $tem_tel?'checked':'' ?>> Com telefone</label>
    </div>
    <button type="submit">Buscar</button>
  </form>

  <?php if ($err): ?>
    <div class="test-err"><strong>ERRO:</strong>
<?= e($err) ?></div>
  <?php else: ?>
    <div class="test-meta">
      ✓ <strong><?= count($rows) ?></strong> resultados em <strong><?= $elapsed ?>ms</strong>
      <?php if ($elapsed > 3000): ?>
        <span style="color:#b91c1c"> · ⚠ lento</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($rows)): ?>
  <table class="test-tbl">
    <thead>
      <tr>
        <th>CNPJ</th>
        <th>Nome fantasia</th>
        <th>UF</th>
        <th>CNAE</th>
        <th>Telefone</th>
        <th>E-mail</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td style="font-family:monospace;font-size:.78rem"><?= e(cnpj_fmt($r['cnpj'])) ?></td>
        <td><strong><?= e($r['nome_fantasia'] ?: '—') ?></strong></td>
        <td><?= e($r['uf']) ?></td>
        <td><?= e($r['cnae_principal']) ?></td>
        <td><?= e(($r['ddd1'] ? '('.$r['ddd1'].') ' : '') . ($r['telefone1'] ?: '—')) ?></td>
        <td><?= e($r['email'] ?: '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php elseif (!$err): ?>
    <div style="text-align:center;padding:40px;color:#94a3b8">Nenhum resultado.</div>
  <?php endif; ?>
</div>
<?php
});
