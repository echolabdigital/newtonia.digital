<?php
/**
 * Newton CNPJ — importa o mapeamento CNAE→Verticais para PostgreSQL.
 *
 * Uso (CLI ou navegador):
 *   php infra/cnpj/import-verticais.php
 *   http://newton.local/infra/cnpj/import-verticais.php
 *
 * Idempotente — pode rodar várias vezes.
 */

require_once __DIR__ . '/../../config.php';
@set_time_limit(180);

$jsonPath = __DIR__ . '/../../_verticais/CNAE_Mapeamento_Verticais.json';
if (!file_exists($jsonPath)) {
    die("Arquivo não encontrado: $jsonPath\nColoque o JSON em /_verticais/CNAE_Mapeamento_Verticais.json\n");
}

$j = json_decode(file_get_contents($jsonPath), true);
if (!$j || empty($j['subclasses'])) die("JSON inválido.\n");

echo "Versão: " . ($j['versao'] ?? '—') . "\n";
echo "Subclasses: " . count($j['subclasses']) . "\n";
echo "Verticais : " . ($j['total_verticais'] ?? '—') . "\n\n";

$pdo = cnpj_db();

// Schema
$pdo->exec("DROP TABLE IF EXISTS cnpj_verticais_map");
$pdo->exec("
    CREATE TABLE cnpj_verticais_map (
        cnae           VARCHAR(7)  PRIMARY KEY,
        cnae_formatado VARCHAR(12),
        vertical_id    VARCHAR(20) NOT NULL,
        vertical_nome  TEXT        NOT NULL,
        sub_vertical   TEXT,
        sinonimos      TEXT
    )
");
$pdo->exec("CREATE INDEX idx_vmap_vertical    ON cnpj_verticais_map (vertical_id)");
$pdo->exec("CREATE INDEX idx_vmap_subvertical ON cnpj_verticais_map (vertical_id, sub_vertical)");

$ins = $pdo->prepare("INSERT INTO cnpj_verticais_map
    (cnae, cnae_formatado, vertical_id, vertical_nome, sub_vertical, sinonimos)
    VALUES (?, ?, ?, ?, ?, ?)");

$ok = 0; $skip = 0;
foreach ($j['subclasses'] as $row) {
    $codigo_fmt = $row['codigo'] ?? '';                  // "0111-3/01"
    $cnae       = preg_replace('/\D/', '', $codigo_fmt); // "0111301"
    if (strlen($cnae) !== 7) { $skip++; continue; }

    $sin = is_array($row['sinonimos'] ?? null) ? implode(', ', $row['sinonimos']) : '';
    try {
        $ins->execute([
            $cnae,
            $codigo_fmt,
            $row['vertical_id']  ?? '',
            $row['vertical']     ?? '',
            $row['sub_vertical'] ?? null,
            $sin,
        ]);
        $ok++;
    } catch (\Throwable $e) {
        $skip++;
    }
}

$pdo->exec("ANALYZE cnpj_verticais_map");

echo "Inseridos: $ok\n";
echo "Pulados : $skip\n\n";

$count = $pdo->query("SELECT COUNT(*) FROM cnpj_verticais_map")->fetchColumn();
echo "Total na tabela: $count\n\n";

// Sample
echo "Verticais resultantes:\n";
$stmt = $pdo->query("SELECT vertical_id, vertical_nome, COUNT(*) AS qtd
                     FROM cnpj_verticais_map GROUP BY vertical_id, vertical_nome
                     ORDER BY qtd DESC");
while ($r = $stmt->fetch()) {
    printf("  %-8s %-50s %5d CNAEs\n", $r['vertical_id'], $r['vertical_nome'], $r['qtd']);
}

echo "\n✓ Concluído.\n";
