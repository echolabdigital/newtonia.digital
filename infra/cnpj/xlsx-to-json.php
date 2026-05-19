<?php
/**
 * Converte o XLSX revisado pra JSON e re-importa no PostgreSQL.
 * Uso (CLI ou browser):
 *   /infra/cnpj/xlsx-to-json.php
 */
require_once __DIR__ . '/../../config.php';
@set_time_limit(180);

$xlsxPath = __DIR__ . '/../../_verticais/CNAE_Mapeamento_Verticais_v2.xlsx';
$jsonPath = __DIR__ . '/../../_verticais/CNAE_Mapeamento_Verticais.json';

if (!file_exists($xlsxPath)) die("XLSX não encontrado: $xlsxPath\n");

// ─── Parser XLSX ─────────────────────────────────────────────────────────────
$zip = new ZipArchive();
if ($zip->open($xlsxPath) !== true) die("Erro abrindo XLSX\n");

$ss_xml = $zip->getFromName('xl/sharedStrings.xml');
$ss_doc = simplexml_load_string($ss_xml);
$ss = [];
foreach ($ss_doc->si as $si) {
    // Pega TODOS os <t> incluindo rich text
    $txt = '';
    foreach ($si->t as $t) $txt .= (string) $t;
    if ($txt === '' && isset($si->r)) {
        foreach ($si->r as $r) $txt .= (string) $r->t;
    }
    $ss[] = $txt;
}

$sheet_xml = $zip->getFromName('xl/worksheets/sheet2.xml');
$zip->close();

$sheet = simplexml_load_string($sheet_xml);
$rows = [];
foreach ($sheet->sheetData->row as $row) {
    $r = [];
    foreach ($row->c as $c) {
        $type = (string) $c['t'];
        $val  = (string) $c->v;
        if ($type === 's') $val = $ss[(int)$val] ?? '';
        $r[] = $val;
    }
    $rows[] = $r;
}

echo "Total linhas lidas: " . count($rows) . "\n";

// ─── Monta subclasses ────────────────────────────────────────────────────────
// Row 0 = título, Row 1 = header, Row 2+ = dados
$subclasses = [];
$verticais  = [];

for ($i = 2; $i < count($rows); $i++) {
    $r = $rows[$i];
    if (count($r) < 4) continue;
    $codigo       = trim($r[0] ?? '');
    $descricao    = trim($r[1] ?? '');
    $vertical     = trim($r[2] ?? '');
    $sub_vertical = trim($r[3] ?? '');
    $tag          = trim($r[4] ?? '');

    if ($codigo === '' || $vertical === '') continue;

    // vertical_id derivado do NOME DA VERTICAL (não da tag — tag se repete entre verticais diferentes)
    $vid = strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $vertical));
    $vid = preg_replace('/[^A-Z0-9]+/', '_', $vid);
    $vid = substr(trim($vid, '_'), 0, 20);

    $subclasses[] = [
        'codigo'           => $codigo,
        'descricao_oficial'=> $descricao,
        'vertical'         => $vertical,
        'vertical_id'      => $vid,
        'sub_vertical'     => $sub_vertical,
        'sinonimos'        => [],
        'tags'             => [$tag ?: ''],
    ];

    if (!isset($verticais[$vid])) {
        $verticais[$vid] = ['nome' => $vertical, 'sub_verticais' => []];
    }
    if ($sub_vertical && !in_array($sub_vertical, $verticais[$vid]['sub_verticais'], true)) {
        $verticais[$vid]['sub_verticais'][] = $sub_vertical;
    }
}

$out = [
    'versao' => 'CNAE 2.3 (IBGE) — revisão Echo Lab Digital v2',
    'fonte_oficial' => 'https://concla.ibge.gov.br/',
    'total_subclasses' => count($subclasses),
    'total_verticais'  => count($verticais),
    'verticais'  => $verticais,
    'subclasses' => $subclasses,
];

file_put_contents($jsonPath, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "JSON gerado: " . count($subclasses) . " subclasses, " . count($verticais) . " verticais\n";
echo "Arquivo: $jsonPath\n\n";

// ─── Re-importa no Postgres ───────────────────────────────────────────────────
echo "Re-importando no PostgreSQL...\n";
$pdo = cnpj_db();
$pdo->exec("TRUNCATE cnpj_verticais_map");

$ins = $pdo->prepare("INSERT INTO cnpj_verticais_map
    (cnae, cnae_formatado, vertical_id, vertical_nome, sub_vertical, sinonimos)
    VALUES (?, ?, ?, ?, ?, ?)
    ON CONFLICT (cnae) DO UPDATE SET
        vertical_id = EXCLUDED.vertical_id,
        vertical_nome = EXCLUDED.vertical_nome,
        sub_vertical = EXCLUDED.sub_vertical");

$ok = 0; $skip_format = 0; $skip_dup = 0; $skip_other = 0;
$samples_format = [];
$samples_dup = [];
foreach ($subclasses as $s) {
    $cnae = preg_replace('/\D/', '', $s['codigo']);
    if (strlen($cnae) !== 7) {
        $skip_format++;
        if (count($samples_format) < 5) $samples_format[] = $s['codigo'] . ' (len=' . strlen($cnae) . ')';
        continue;
    }
    try {
        $ins->execute([
            $cnae, $s['codigo'],
            $s['vertical_id'], $s['vertical'],
            $s['sub_vertical'] ?: null, ''
        ]);
        $ok++;
    } catch (\Throwable $e) {
        // ON CONFLICT já cobre duplicatas, então erro real
        if (str_contains($e->getMessage(), 'duplicate')) {
            $skip_dup++;
            if (count($samples_dup) < 3) $samples_dup[] = $s['codigo'];
        } else {
            $skip_other++;
        }
    }
}
$pdo->exec("ANALYZE cnpj_verticais_map");

echo "Inseridos: $ok\n";
echo "Pulados por formato CNAE: $skip_format\n";
if ($samples_format) echo "  Exemplos: " . implode(', ', $samples_format) . "\n";
echo "Pulados por duplicata: $skip_dup\n";
if ($samples_dup) echo "  Exemplos: " . implode(', ', $samples_dup) . "\n";
echo "Pulados outros: $skip_other\n\n";

// Resumo das verticais
echo "=== Verticais resultantes ===\n";
$stmt = $pdo->query("SELECT vertical_id, vertical_nome, COUNT(*) AS qtd
                     FROM cnpj_verticais_map GROUP BY vertical_id, vertical_nome
                     ORDER BY qtd DESC");
while ($r = $stmt->fetch()) {
    printf("  %-12s %-50s %5d CNAEs\n", $r['vertical_id'], $r['vertical_nome'], $r['qtd']);
}

echo "\n✓ Pronto. Recarrega o Newton CNPJ no navegador.\n";
