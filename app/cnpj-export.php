<?php
/**
 * Newton AI — CNPJ Export (CSV)
 * Respeita limite mensal + addon credits. Streaming para evitar timeout.
 */

require_once __DIR__ . '/../config.php';
@set_time_limit(120);

$tenant    = require_tenant();
$tenant_id = (int) $tenant['id'];

// 🔓 MODO DEV: quota desabilitada (defina CNPJ_EXPORT_FREE=false pra reabilitar)
$EXPORT_FREE = !defined('CNPJ_EXPORT_FREE') || CNPJ_EXPORT_FREE;

$limit = cnpj_monthly_limit($tenant_id);
$used  = cnpj_monthly_used($tenant_id);
$pct   = cnpj_usage_pct($used, $limit);

// Limite mensal atingido (só bloqueia se NÃO estiver em modo livre)
if (!$EXPORT_FREE && $pct >= 100) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode([
        'error'   => 'limit_reached',
        'message' => "Você atingiu 100% do seu limite mensal ({$limit} leads).",
        'used'    => $used,
        'limit'   => $limit,
        'percent' => $pct,
    ]);
    exit;
}

// Filtros (ignora controles)
$filters = $_GET;
unset($filters['page'], $filters['sort'], $filters['per'], $filters['noauto']);

[$where, $params] = cnpj_build_where($filters);

// Capped count para definir export_count sem full-scan
$CAP = 50000;
try { cnpj_db()->exec("SET statement_timeout = '10s'"); } catch (\Throwable $e) {}
try {
    $total = (int) cnpj_val(
        "SELECT COUNT(*) FROM (
            SELECT 1 FROM rf_estabelecimentos e
            LEFT JOIN rf_empresas emp ON emp.cnpj_basico = e.cnpj_basico
            $where
            LIMIT " . ($CAP + 1) . "
         ) sub",
        $params
    );
} catch (\Throwable $e) {
    $total = $CAP + 1;
}
try { cnpj_db()->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

$available = $EXPORT_FREE ? PHP_INT_MAX : max(0, $limit - $used);
// Hard cap apenas de sanidade (proteção contra exports gigantes que travam o PHP)
$hard_cap     = 100000;
$export_count = min($total, $hard_cap, $available);

if ($export_count <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['error' => 'no_results', 'message' => 'Nenhum resultado para exportar.']);
    exit;
}

// Reserva a quota ANTES do streaming (em modo livre não conta na quota)
if (!$EXPORT_FREE) {
    cnpj_quota_log($tenant_id, $export_count, $filters);
}

$alert = cnpj_alert_message($pct, $used, $limit);

// Streaming
$filename = 'newton-cnpj_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
if ($alert) header('X-Newton-Alert: ' . $alert);

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para abrir bem no Excel

// Cabeçalho
fputcsv($out, [
    'CNPJ', 'Razão Social', 'Nome Fantasia',
    'Situação', 'Data Situação', 'Início Atividade',
    'CNAE Código', 'CNAE Descrição',
    'Matriz/Filial', 'Porte', 'Capital Social',
    'Simples', 'MEI', 'Natureza Jurídica',
    'Endereço', 'Número', 'Complemento', 'Bairro',
    'Município', 'UF', 'CEP',
    'DDD1', 'Telefone1', 'DDD2', 'Telefone2', 'E-mail',
], ';');

// Query em chunks para não estourar memória em exports grandes
$chunk = 500;
for ($offset = 0; $offset < $export_count; $offset += $chunk) {
    $take = min($chunk, $export_count - $offset);
    $rows = cnpj_all(
        "SELECT
            e.cnpj_basico || e.cnpj_ordem || e.cnpj_dv AS cnpj,
            COALESCE(emp.razao_social, e.nome_fantasia, 'N/D') AS razao_social,
            e.nome_fantasia,
            e.situacao_cadastral,
            e.data_situacao_cadastral,
            e.data_inicio_atividade,
            e.cnae_principal,
            COALESCE(cn.descricao, '') AS cnae_descricao,
            CASE WHEN e.identificador_mf = '1' THEN 'Matriz' ELSE 'Filial' END AS mf,
            COALESCE(emp.porte_empresa, '') AS porte,
            COALESCE(emp.capital_social::text, '') AS capital,
            CASE WHEN EXISTS (SELECT 1 FROM rf_simples s WHERE s.cnpj_basico = e.cnpj_basico AND s.opcao_simples = 'S') THEN 'Sim' ELSE 'Não' END AS simples,
            CASE WHEN EXISTS (SELECT 1 FROM rf_simples s WHERE s.cnpj_basico = e.cnpj_basico AND s.opcao_mei     = 'S') THEN 'Sim' ELSE 'Não' END AS mei,
            COALESCE(nj.descricao, emp.natureza_juridica::text, '') AS natureza,
            TRIM(BOTH ' ' FROM COALESCE(e.tipo_logradouro,'') || ' ' || COALESCE(e.logradouro,'')) AS endereco,
            e.numero, e.complemento, e.bairro,
            COALESCE(mun.descricao, e.municipio::text) AS municipio_nome,
            e.uf, e.cep,
            e.ddd1, e.telefone1, e.ddd2, e.telefone2,
            LOWER(COALESCE(e.email, '')) AS email
         FROM rf_estabelecimentos e
         LEFT JOIN rf_empresas       emp ON emp.cnpj_basico = e.cnpj_basico
         LEFT JOIN rf_municipios     mun ON mun.codigo::text = e.municipio::text
         LEFT JOIN rf_cnaes          cn  ON cn.codigo        = e.cnae_principal
         LEFT JOIN rf_naturezas      nj  ON nj.codigo::text  = emp.natureza_juridica::text
         $where
         ORDER BY razao_social
         LIMIT $take OFFSET $offset",
        $params
    );

    foreach ($rows as $r) {
        fputcsv($out, [
            cnpj_fmt($r['cnpj']),
            $r['razao_social'],
            $r['nome_fantasia'],
            cnpj_situacao_label($r['situacao_cadastral']),
            cnpj_data_br($r['data_situacao_cadastral']),
            cnpj_data_br($r['data_inicio_atividade']),
            $r['cnae_principal'],
            $r['cnae_descricao'],
            $r['mf'],
            cnpj_porte_label($r['porte']),
            $r['capital'] !== '' ? number_format((float)$r['capital'], 2, ',', '.') : '',
            $r['simples'],
            $r['mei'],
            $r['natureza'],
            $r['endereco'],
            $r['numero'],
            $r['complemento'],
            $r['bairro'],
            $r['municipio_nome'],
            $r['uf'],
            $r['cep'],
            $r['ddd1'],
            $r['telefone1'],
            $r['ddd2'],
            $r['telefone2'],
            $r['email'],
        ], ';');
    }
    if (ob_get_level()) ob_flush();
    flush();
}

fclose($out);
exit;
