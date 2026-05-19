<?php
/**
 * Importa CSV v3 (3 colunas: Subclasse | Descrição | Vertical)
 * Sem sub-vertical — fica só Vertical → CNAEs.
 */
require_once __DIR__ . '/../../config.php';
@set_time_limit(180);

$csvPath = __DIR__ . '/../../_verticais/CNAE_Mapeamento_Verticais_v3.utf8.csv';
if (!file_exists($csvPath)) {
    // Converte do Windows-1252 se ainda não foi
    $src = __DIR__ . '/../../_verticais/CNAE_Mapeamento_Verticais_v3.csv';
    if (file_exists($src)) {
        file_put_contents($csvPath, mb_convert_encoding(file_get_contents($src), 'UTF-8', 'Windows-1252'));
    } else {
        die("CSV não encontrado: $csvPath\n");
    }
}

$rows = [];
if (($fh = fopen($csvPath, 'r')) !== false) {
    while (($r = fgetcsv($fh, 0, ';')) !== false) $rows[] = $r;
    fclose($fh);
}
echo "Total linhas: " . count($rows) . "\n";

$pdo = cnpj_db();
$pdo->exec("TRUNCATE cnpj_verticais_map");

$ins = $pdo->prepare("INSERT INTO cnpj_verticais_map
    (cnae, cnae_formatado, vertical_id, vertical_nome, sub_vertical, sinonimos)
    VALUES (?, ?, ?, ?, ?, ?)
    ON CONFLICT (cnae) DO NOTHING");

$ok = 0; $skip = 0;
$verticais_set = [];
foreach ($rows as $r) {
    $codigo   = trim($r[0] ?? '');
    $descricao= trim($r[1] ?? '');
    $vertical = trim($r[2] ?? '');
    if ($codigo === '' || $vertical === '') { $skip++; continue; }
    $cnae = preg_replace('/\D/', '', $codigo);
    if (strlen($cnae) !== 7) { $skip++; continue; }

    // vertical_id = nome normalizado curto
    $vid = strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $vertical));
    $vid = preg_replace('/[^A-Z0-9]+/', '_', $vid);
    $vid = substr(trim($vid, '_'), 0, 20);

    try {
        $ins->execute([$cnae, $codigo, $vid, $vertical, $descricao /* descricao da CNAE como "sub" */, '']);
        $ok++;
        $verticais_set[$vid] = $vertical;
    } catch (\Throwable $e) { $skip++; }
}
$pdo->exec("ANALYZE cnpj_verticais_map");

echo "Inseridos: $ok · Pulados: $skip\n";
echo "Verticais únicas: " . count($verticais_set) . "\n\n";

echo "=== Distribuição ===\n";
$stmt = $pdo->query("SELECT vertical_id, vertical_nome, COUNT(*) AS qtd
                     FROM cnpj_verticais_map GROUP BY vertical_id, vertical_nome ORDER BY qtd DESC");
while ($r = $stmt->fetch()) {
    printf("  %-50s %5d CNAEs\n", $r['vertical_nome'], $r['qtd']);
}
echo "\n✓ Pronto.\n";
