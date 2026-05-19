<?php
/**
 * Newton CNPJ — helpers de Verticais (mapeamento CNAE → vertical de negócio).
 */

// Garante que a tabela de mapeamento exista (vazia, se ninguém rodou o import ainda).
// Evita "relation does not exist" no LEFT JOIN da busca.
function cnpj_verticais_ensure_table(): void
{
    static $done = false;
    if ($done) return;
    try {
        cnpj_db()->exec("CREATE TABLE IF NOT EXISTS cnpj_verticais_map (
            cnae           VARCHAR(7)  PRIMARY KEY,
            cnae_formatado VARCHAR(12),
            vertical_id    VARCHAR(20) NOT NULL DEFAULT '',
            vertical_nome  TEXT        NOT NULL DEFAULT '',
            sub_vertical   TEXT,
            sinonimos      TEXT
        )");
        $done = true;
    } catch (\Throwable $e) { /* ignora */ }
}

// Auto-import do JSON se a tabela existir mas estiver vazia
function cnpj_verticais_autoimport(): int
{
    $jsonPath = __DIR__ . '/../_verticais/CNAE_Mapeamento_Verticais.json';
    if (!file_exists($jsonPath)) return 0;
    $j = json_decode(file_get_contents($jsonPath), true);
    if (!$j || empty($j['subclasses'])) return 0;

    $pdo = cnpj_db();
    $ins = $pdo->prepare("INSERT INTO cnpj_verticais_map
        (cnae, cnae_formatado, vertical_id, vertical_nome, sub_vertical, sinonimos)
        VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT (cnae) DO NOTHING");

    $ok = 0;
    foreach ($j['subclasses'] as $row) {
        $codigo_fmt = $row['codigo'] ?? '';
        $cnae = preg_replace('/\D/', '', $codigo_fmt);
        if (strlen($cnae) !== 7) continue;
        $sin = is_array($row['sinonimos'] ?? null) ? implode(', ', $row['sinonimos']) : '';
        try {
            $ins->execute([
                $cnae, $codigo_fmt,
                $row['vertical_id']  ?? '',
                $row['vertical']     ?? '',
                $row['sub_vertical'] ?? null,
                $sin,
            ]);
            $ok++;
        } catch (\Throwable $e) {}
    }
    try { $pdo->exec("ANALYZE cnpj_verticais_map"); } catch (\Throwable $e) {}
    return $ok;
}

// Lista de verticais com contagem de CNAEs (cacheado em memória durante a request)
function cnpj_verticais_list(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    cnpj_verticais_ensure_table();

    try {
        $cache = cnpj_all(
            "SELECT vertical_id, vertical_nome, COUNT(*) AS qtd
             FROM cnpj_verticais_map
             GROUP BY vertical_id, vertical_nome
             ORDER BY vertical_nome"
        );
        // Se vazio, tenta auto-importar (uma vez)
        if (empty($cache)) {
            cnpj_verticais_autoimport();
            $cache = cnpj_all(
                "SELECT vertical_id, vertical_nome, COUNT(*) AS qtd
                 FROM cnpj_verticais_map
                 GROUP BY vertical_id, vertical_nome
                 ORDER BY vertical_nome"
            );
        }
    } catch (\Throwable $e) {
        $cache = [];
    }
    return $cache;
}

// Sub-verticais (= classes CNAE específicas, com código pra filtragem)
function cnpj_subverticais_list(string $vertical_id): array
{
    try {
        return cnpj_all(
            "SELECT DISTINCT sub_vertical, cnae
             FROM cnpj_verticais_map
             WHERE vertical_id = ? AND sub_vertical IS NOT NULL AND sub_vertical <> ''
             ORDER BY sub_vertical",
            [$vertical_id]
        );
    } catch (\Throwable $e) { return []; }
}

// Lookup: vertical de um CNAE
function cnpj_vertical_de(string $cnae): ?array
{
    static $cache = [];
    if (isset($cache[$cnae])) return $cache[$cnae];
    try {
        $r = cnpj_one(
            "SELECT vertical_id, vertical_nome, sub_vertical
             FROM cnpj_verticais_map WHERE cnae = ?",
            [$cnae]
        );
    } catch (\Throwable $e) { $r = null; }
    return $cache[$cnae] = $r;
}

// Cor + ícone por vertical_id (curado pra fazer sentido visual)
function cnpj_vertical_visual(?string $vid): array
{
    static $map = [
        'AGRO'   => ['#16a34a', '🌾'],
        'MINER'  => ['#78716c', '⛏'],
        'ALIM'   => ['#f97316', '🍽'],
        'MODA'   => ['#ec4899', '👗'],
        'SAU'    => ['#db2777', '⚕'],
        'AUTO'   => ['#0891b2', '🚗'],
        'CONST'  => ['#ea580c', '🏗'],
        'CASA'   => ['#d97706', '🛋'],
        'ENER'   => ['#f59e0b', '⚡'],
        'IND'    => ['#dc2626', '🏭'],
        'VAR'    => ['#3b82f6', '🛒'],
        'ATA'    => ['#1d4ed8', '📦'],
        'LOG'    => ['#0c4a6e', '🚚'],
        'TUR'    => ['#a855f7', '✈'],
        'TEC'    => ['#8b5cf6', '💻'],
        'MIDIA'  => ['#c026d3', '🎬'],
        'EDU'    => ['#0d9488', '🎓'],
        'FIN'    => ['#059669', '🏦'],
        'IMOB'   => ['#b45309', '🏘'],
        'PROF'   => ['#6366f1', '💼'],
        'ENG'    => ['#4338ca', '📐'],
        'MKT'    => ['#e11d48', '📣'],
        'PET'    => ['#7c2d12', '🐾'],
        'ADMI'   => ['#7c3aed', '📋'],
        'BELE'   => ['#be185d', '💅'],
        'CULT'   => ['#9333ea', '🎨'],
        'PUBLI'  => ['#1e40af', '🏛'],
    ];
    if (!$vid) return ['cor' => '#9ca3af', 'icon' => '📦'];
    [$cor, $ic] = $map[$vid] ?? ['#9ca3af', '📦'];
    return ['cor' => $cor, 'icon' => $ic];
}
