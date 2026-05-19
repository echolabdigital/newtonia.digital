<?php
require_once __DIR__ . '/../config.php';
$tenant = require_tenant();
require_once __DIR__ . '/../core/cnpj_db.php';

header('Content-Type: application/json; charset=utf-8');
// Cache curto pra evitar pegar dados antigos com bugs
header('Cache-Control: public, max-age=300');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'municipios':
            $uf = strtoupper(trim($_GET['uf'] ?? ''));
            if (!$uf || !in_array($uf, CNPJ_UFS, true)) {
                echo json_encode([]);
                exit;
            }

            // Cache MySQL — não serve resultado vazio (indica falha anterior)
            try {
                db_q("CREATE TABLE IF NOT EXISTS cnpj_static_cache (
                    key_name VARCHAR(80) PRIMARY KEY,
                    data MEDIUMTEXT NOT NULL,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (\Throwable $e) {}

            $key = "muns_uf_${uf}_v6";
            $cached = db_one("SELECT data, updated_at FROM cnpj_static_cache WHERE key_name = ?", [$key]);
            if ($cached && strlen($cached['data']) > 10 && (time() - strtotime($cached['updated_at'])) < 86400 * 30) {
                header('X-Cache: HIT');
                echo $cached['data'];
                exit;
            }

            // Dois passos: (1) lista de códigos da UF via subquery; (2) lookup de nomes em rf_municipios.
            // Mais eficiente — o planner usa índice em (uf, municipio) se existir.
            try { cnpj_db()->exec("SET statement_timeout = '120s'"); } catch (\Throwable $e) {}
            $rows = [];
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
            } catch (\Throwable $e) { $rows = []; }
            try { cnpj_db()->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

            foreach ($rows as &$rr) {
                if (isset($rr['codigo'])) $rr['codigo'] = trim((string)$rr['codigo']);
            }
            unset($rr);

            $json = json_encode($rows);
            if (!empty($rows)) {
                try {
                    db_q("REPLACE INTO cnpj_static_cache (key_name, data) VALUES (?, ?)", [$key, $json]);
                } catch (\Throwable $e) {}
            }
            header('X-Cache: MISS');
            echo $json;
            break;

        case 'subverticais':
            $vid = trim($_GET['vertical'] ?? '');
            if (!$vid) { echo json_encode([]); exit; }
            echo json_encode(cnpj_subverticais_list($vid));
            break;

        case 'cnaes':
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) { echo json_encode([]); exit; }
            $rows = cnpj_all(
                "SELECT codigo, descricao FROM rf_cnaes
                 WHERE descricao ILIKE ? OR codigo LIKE ?
                 ORDER BY codigo LIMIT 20",
                ['%' . $q . '%', $q . '%']
            );
            echo json_encode($rows);
            break;

        case 'bairros':
            // Bairros válidos de um município — carregado uma vez, filtrado client-side.
            // Normaliza para ASCII uppercase (remove acentos via iconv TRANSLIT) para que:
            //   • "BOQUEIRÃO" e "BOQUEIRAO" virem o mesmo token "BOQUEIRAO"
            //   • O filtro ILIKE na busca encontre os registros sem depender de unaccent()
            // Cache MySQL 7 dias (v4).
            $mun = trim($_GET['municipio'] ?? '');
            if (!$mun || !ctype_digit($mun)) { echo json_encode([]); exit; }

            $padded = str_pad($mun, 7);
            $key    = "bairros_mun_{$mun}_v4";

            $cached = db_one("SELECT data, updated_at FROM cnpj_static_cache WHERE key_name = ?", [$key]);
            if ($cached && strlen($cached['data']) > 2
                && (time() - strtotime($cached['updated_at'])) < 86400 * 7) {
                header('X-Cache: HIT');
                echo $cached['data'];
                exit;
            }

            try { cnpj_db()->exec("SET statement_timeout = '30s'"); } catch (\Throwable $e) {}
            $rows = [];
            try {
                $rows = cnpj_all(
                    "SELECT UPPER(TRIM(bairro)) AS bairro
                     FROM rf_estabelecimentos
                     WHERE municipio IN (?, ?)
                       AND bairro IS NOT NULL
                       AND LENGTH(TRIM(bairro)) >= 3
                       AND bairro NOT SIMILAR TO '%[0-9]{3,}%'
                     GROUP BY UPPER(TRIM(bairro))
                     HAVING COUNT(*) >= 3
                     ORDER BY 1
                     LIMIT 500",
                    [$mun, $padded]
                );
            } catch (\Throwable $e) { $rows = []; }
            try { cnpj_db()->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

            // Normaliza para ASCII (remove acentos) e deduplica
            // BOQUEIRÃO → BOQUEIRAO, Boqueirão → BOQUEIRAO → mesmo token, 1 entrada
            $seen = [];
            $list = [];
            foreach (array_column($rows, 'bairro') as $b) {
                $b    = strtoupper(trim((string)$b));
                $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $b);
                if ($ascii === false || $ascii === '') $ascii = $b;
                $ascii = strtoupper(preg_replace('/[^A-Z0-9 \-\.]/', '', $ascii));
                if (strlen($ascii) < 3) continue;
                if (!isset($seen[$ascii])) {
                    $seen[$ascii] = true;
                    $list[] = $ascii; // guarda versão ASCII limpa
                }
            }
            $list = array_values($list);
            $json = json_encode($list);
            if (!empty($list)) {
                try { db_q("REPLACE INTO cnpj_static_cache (key_name, data) VALUES (?, ?)", [$key, $json]); }
                catch (\Throwable $e) {}
            }
            header('X-Cache: MISS');
            echo $json;
            break;

        case 'detail':
            $cnpj = preg_replace('/\D/', '', $_GET['cnpj'] ?? '');
            if (strlen($cnpj) !== 14) {
                http_response_code(400);
                echo json_encode(['error' => 'CNPJ inválido']);
                exit;
            }
            $d = cnpj_detail($cnpj);
            if (!$d) {
                http_response_code(404);
                echo json_encode(['error' => 'Não encontrado']);
                exit;
            }
            // Enriquecimento — Newton Score (com breakdown), faturamento, funcionários, links
            $breakdown          = cnpj_newton_score_breakdown($d);
            $d['newton_score']  = $breakdown['total'];
            $d['score_label']   = $breakdown['tier_label'];
            $d['score_class']   = cnpj_score_class($breakdown['total']);
            $d['score_breakdown'] = $breakdown; // para o drawer renderizar
            $d['faturamento']   = cnpj_faturamento_estimado($d);
            $d['funcionarios']  = cnpj_funcionarios_estimado($d);
            $d['links']         = cnpj_links_externos($d);
            $d['idade']         = cnpj_idade($d['data_inicio_atividade'] ?? null);
            echo json_encode($d);
            break;

        case 'log_view':
            // Contabiliza 1 lead da cota mensal por visualização de detalhes.
            // DEDUP: se o mesmo CNPJ já foi visto no mesmo mês pelo mesmo tenant, não cobra de novo.
            $tenant_id = (int) $tenant['id'];
            $cnpj = preg_replace('/\D/', '', $_GET['cnpj'] ?? '');
            if (strlen($cnpj) !== 14) {
                http_response_code(400);
                echo json_encode(['error' => 'CNPJ inválido']);
                exit;
            }

            $already = (bool) db_val(
                "SELECT 1 FROM cnpj_download_log
                 WHERE tenant_id = ?
                   AND filters_json LIKE ?
                   AND YEAR(downloaded_at)  = YEAR(NOW())
                   AND MONTH(downloaded_at) = MONTH(NOW())
                 LIMIT 1",
                [$tenant_id, '%"_view":"' . $cnpj . '"%']
            );

            $limit_total = cnpj_monthly_limit($tenant_id);
            $used_before = cnpj_monthly_used($tenant_id);
            $addon       = (int) db_val('SELECT cnpj_addon_credits FROM tenants WHERE id = ?', [$tenant_id]);
            $available   = max(0, $limit_total - $used_before) + $addon;

            $counted = false;
            if (!$already) {
                if ($available <= 0) {
                    echo json_encode([
                        'ok'      => false,
                        'error'   => 'quota_exceeded',
                        'used'    => $used_before,
                        'limit'   => $limit_total,
                        'addon'   => $addon,
                    ]);
                    exit;
                }
                cnpj_quota_log($tenant_id, 1, ['_view' => $cnpj]);
                $counted = true;
            }

            echo json_encode([
                'ok'      => true,
                'counted' => $counted,
                'already' => $already,
                'used'    => cnpj_monthly_used($tenant_id),
                'limit'   => $limit_total,
                'addon'   => (int) db_val('SELECT cnpj_addon_credits FROM tenants WHERE id = ?', [$tenant_id]),
            ]);
            break;

        case 'signals':
            @set_time_limit(25);
            $cnpj = preg_replace('/\D/', '', $_GET['cnpj'] ?? '');
            if (strlen($cnpj) !== 14) {
                http_response_code(400);
                echo json_encode(['error' => 'CNPJ inválido']);
                exit;
            }
            $base = cnpj_detail($cnpj);
            if (!$base) {
                http_response_code(404);
                echo json_encode(['error' => 'Não encontrado']);
                exit;
            }
            $sig = cnpj_signals_run($cnpj, $base);
            echo json_encode(['cnpj' => $cnpj, 'signals' => $sig]);
            break;

        case 'enrich':
            @set_time_limit(20);
            $cnpj = preg_replace('/\D/', '', $_GET['cnpj'] ?? '');
            if (strlen($cnpj) !== 14) {
                http_response_code(400);
                echo json_encode(['error' => 'CNPJ inválido']);
                exit;
            }
            $base = cnpj_detail($cnpj);
            if (!$base) {
                http_response_code(404);
                echo json_encode(['error' => 'Não encontrado']);
                exit;
            }
            $enr = cnpj_enrich_all($cnpj, $base);
            if (!empty($enr['gplaces']['photo_ref'])) {
                $enr['gplaces']['photo_url'] = cnpj_gplaces_photo_url($enr['gplaces']['photo_ref'], 600);
            }
            echo json_encode([
                'cnpj'       => $cnpj,
                'sources'    => $enr,
                'has_places_key' => (defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY !== ''),
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'action inválida']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    $payload = ['error' => 'Erro: ' . $e->getMessage()];
    if (defined('APP_ENV') && APP_ENV === 'local') {
        $payload['file'] = $e->getFile() . ':' . $e->getLine();
        $payload['trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 6);
    }
    echo json_encode($payload);
}
