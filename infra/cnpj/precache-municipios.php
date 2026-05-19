<?php
/**
 * Pré-cacheia TODOS os municípios de TODAS as UFs no MySQL.
 * Roda 1× — depois o autocomplete vira instantâneo.
 *
 * Uso: http://newton.local/infra/cnpj/precache-municipios.php
 */
require_once __DIR__ . '/../../config.php';
@set_time_limit(600);

// Garante tabela cache
try {
    db_q("CREATE TABLE IF NOT EXISTS cnpj_static_cache (
        key_name VARCHAR(80) PRIMARY KEY,
        data MEDIUMTEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

$ufs = ['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB',
        'PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'];

echo "Pré-cacheando municípios por UF (uma vez só)...\n\n";

foreach ($ufs as $uf) {
    $key = "muns_uf_${uf}_v6";
    $started = microtime(true);

    try { cnpj_db()->exec("SET statement_timeout = '180s'"); } catch (\Throwable $e) {}
    try {
        $rows = cnpj_all(
            "SELECT TRIM(m.codigo::text) AS codigo,
                    TRIM(m.descricao) AS nome
             FROM rf_municipios m
             WHERE m.codigo IN (
                 SELECT DISTINCT municipio
                 FROM rf_estabelecimentos
                 WHERE uf = ?
             )
             ORDER BY m.descricao",
            [$uf]
        );
        // Garante trim em todos
        foreach ($rows as &$r) { if (isset($r['codigo'])) $r['codigo'] = trim((string)$r['codigo']); }
        unset($r);

        $json = json_encode($rows);
        db_q("REPLACE INTO cnpj_static_cache (key_name, data) VALUES (?, ?)", [$key, $json]);

        $elapsed = round((microtime(true) - $started) * 1000);
        printf("  %s — %4d municípios em %5d ms\n", $uf, count($rows), $elapsed);
    } catch (\Throwable $e) {
        printf("  %s — ERRO: %s\n", $uf, substr($e->getMessage(), 0, 80));
    }
    try { cnpj_db()->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}
}

echo "\n✓ Pronto. Cache válido por 30 dias.\n";
echo "Autocomplete agora vai responder instantaneamente em qualquer UF.\n";
