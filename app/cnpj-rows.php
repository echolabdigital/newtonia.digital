<?php
/**
 * Newton CNPJ — render de cards limpo (HTTP fragment ou include).
 */

if (!isset($results)) {
    require_once __DIR__ . '/../config.php';
    @set_time_limit(90);
    ini_set('memory_limit', '512M');
    $tenant = require_tenant();

    $f = array_map('trim', array_filter($_GET, 'is_string'));
    unset($f['page'], $f['sort'], $f['per']);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $per  = max(10, min(2000, (int) ($_GET['per'] ?? 100)));
    $sort = $_GET['sort'] ?? 'qualified';

    $results = cnpj_search($f, $page, $per, $sort);

    if (empty($results['rows'])) {
        echo '<!--empty-->';
        exit;
    }
}

foreach ($results['rows'] as $r):
    $sit       = $r['situacao_cadastral'];
    $sit_cls   = $sit === '02' ? 'badge-green' : ($sit === '08' ? 'badge-red' : 'badge-gray');
    $cnpj_fmt  = cnpj_fmt($r['cnpj']);
    $cnpj_raw  = preg_replace('/\D/', '', $r['cnpj']);
    $idadeCat  = cnpj_idade_categoria($r['data_inicio_atividade'] ?? null);
    $tel1      = cnpj_tel_fmt($r['ddd1'] ?? null, $r['telefone1'] ?? null);
    $avatar_c  = cnpj_avatar_color($r['razao_social'] ?? '');
    $score     = isset($r['sql_score']) ? (int) $r['sql_score'] : cnpj_newton_score($r);
    $score_cls = cnpj_score_class($score);

    // Vertical curada (JOIN) ou setor por CNAE
    if (!empty($r['vertical_id'])) {
        $vv = cnpj_vertical_visual($r['vertical_id']);
        $setor = ['nome' => $r['vertical_nome'], 'cor' => $vv['cor'], 'icon' => $vv['icon']];
    } else {
        $setor = cnpj_setor($r['cnae_principal'] ?? null);
    }

    // Limpa razão social — tira prefixo CNPJ (MEIs vêm "00.123.456 NOME PESSOA")
    $clean_razao = preg_replace('/^\d{2}\.?\d{3}\.?\d{3}[\s\-\/]+/', '', (string)($r['razao_social'] ?? ''));
    $clean_razao = trim($clean_razao);
    $clean_fant  = trim((string)($r['nome_fantasia'] ?? ''));
    $brand_hint  = (strpos($clean_razao, 'CNPJ ') === 0) ? cnpj_brand_from_email($r['email'] ?? null) : null;

    // Nome do card: prioriza FANTASIA limpa
    $fant_ok = $clean_fant !== '' && !preg_match('/^\*+$/', $clean_fant);
    if ($fant_ok) {
        $primary_name   = $clean_fant;
        $secondary_name = ($clean_razao && $clean_razao !== $clean_fant) ? $clean_razao : null;
    } else {
        $primary_name   = $brand_hint ?: ($clean_razao ?: '—');
        $secondary_name = $brand_hint ? $clean_razao : null;
    }
    $avatar_l = cnpj_initial($primary_name);

    $isCorpEmail = cnpj_email_is_corporate($r['email'] ?? null);
    $cidade_uf   = trim(($r['municipio_nome'] ?? '') . '/' . ($r['uf'] ?? ''), '/');

    $tel_wpp = '';
    if (!empty($r['ddd1']) && !empty($r['telefone1'])) {
        $tel_wpp = preg_replace('/\D/', '', '55' . $r['ddd1'] . $r['telefone1']);
    }
?>
<article class="result-card" data-cnpj="<?= e($cnpj_raw) ?>" data-score="<?= $score ?>">
  <label class="rc-select" title="Selecionar"><input type="checkbox" class="bulk-cb" value="<?= e($cnpj_raw) ?>"></label>

  <div class="rc-main">
    <div class="rc-avatar" style="background:<?= $avatar_c ?>"><?= e($avatar_l) ?></div>

    <div class="rc-content">
      <!-- Nome principal -->
      <h3 class="rc-name"><?= e($primary_name) ?></h3>
      <?php if ($secondary_name): ?>
        <div class="rc-sub">Razão: <?= e($secondary_name) ?></div>
      <?php endif; ?>

      <!-- Tags: CNPJ + score + situação + vertical + idade + MEI/porte -->
      <div class="rc-tags">
        <span class="rc-cnpj copyable" onclick="copyText('<?= e($cnpj_fmt) ?>', this)" title="Copiar CNPJ"><?= e($cnpj_fmt) ?></span>
        <span class="newton-score <?= $score_cls ?>" title="Radar Score: <?= $score ?>"><?= $score ?> <?= e(cnpj_score_label($score)) ?></span>
        <span class="badge <?= $sit_cls ?>"><?= e(cnpj_situacao_label($sit)) ?></span>
        <span class="badge-setor" style="background:<?= $setor['cor'] ?>15;color:<?= $setor['cor'] ?>"><?= $setor['icon'] ?> <?= e($setor['nome']) ?></span>
        <span class="badge <?= $idadeCat['class'] ?>"><?= $idadeCat['label'] ?></span>
        <?php if (!empty($r['is_mei'])): ?><span class="badge badge-amber">MEI</span><?php endif; ?>
        <?php if (($p = $r['porte_empresa'] ?? null) && $p !== '00' && empty($r['is_mei'])): ?>
          <span class="badge badge-purple"><?= e(cnpj_porte_label($p)) ?></span>
        <?php endif; ?>
      </div>

      <!-- Info compacta: CNAE · cidade · tel · email · capital -->
      <div class="rc-info">
        <?php if (!empty($r['cnae_descricao'])): ?>
          <span class="info-item" title="<?= e($r['cnae_principal']) ?>">🏷 <?= e(mb_strimwidth($r['cnae_descricao'], 0, 50, '…')) ?></span>
        <?php endif; ?>
        <?php if ($cidade_uf): ?><span class="info-item">📍 <?= e($cidade_uf) ?></span><?php endif; ?>
        <?php if ($tel1): ?>
          <span class="info-item copyable" onclick="copyText('<?= e($tel1) ?>', this)">📞 <?= e($tel1) ?></span>
        <?php endif; ?>
        <?php if (!empty($r['email'])): ?>
          <span class="info-item copyable" onclick="copyText('<?= e(strtolower($r['email'])) ?>', this)"><?= $isCorpEmail ? '✉' : '📧' ?> <?= e(strtolower($r['email'])) ?></span>
        <?php endif; ?>
        <?php if (!empty($r['capital_social']) && (float)$r['capital_social'] > 0): ?>
          <span class="info-item">💰 <?= e(cnpj_capital_fmt($r['capital_social'])) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="rc-actions">
      <button type="button" class="rc-btn rc-btn-primary" onclick="confirmAndOpen('<?= e($cnpj_raw) ?>')">Ver</button>
      <button type="button" class="rc-btn rc-btn-crm" onclick="addToCRM(this, <?= e(json_encode([
            'cnpj' => $cnpj_raw,
            'razao_social' => $primary_name,
            'nome_fantasia' => $clean_fant,
            'telefone' => $tel1,
            'email' => $r['email'] ?? '',
            'cidade_uf' => $cidade_uf,
            'cnae' => $r['cnae_principal'] ?? '',
            'capital' => (float) ($r['capital_social'] ?? 0),
            'score' => $score,
        ], JSON_HEX_APOS|JSON_HEX_QUOT)) ?>)" title="Adicionar ao CRM">+ CRM</button>
      <?php if (!empty($r['email'])): ?>
        <button type="button" class="rc-btn rc-btn-mail" onclick='openMailCompose({to:<?= json_encode($r['email']) ?>, name:<?= json_encode($primary_name) ?>})' title="Enviar e-mail (Mail Lab)">✉ E-mail</button>
      <?php endif; ?>
      <?php if ($tel_wpp): ?>
        <a class="rc-btn rc-btn-wa" href="https://wa.me/<?= e($tel_wpp) ?>" target="_blank" rel="noopener" title="WhatsApp">WA</a>
      <?php endif; ?>
    </div>
  </div>
</article>
<?php endforeach;
