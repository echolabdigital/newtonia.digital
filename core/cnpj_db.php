<?php
/**
 * Newton AI — CNPJ / Receita Federal helpers
 * PostgreSQL connection + query helpers + quota v2
 */

// ─── PostgreSQL connection ────────────────────────────────────────────────────

function cnpj_db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        CNPJ_DB_HOST, CNPJ_DB_PORT, CNPJ_DB_NAME
    );
    $pdo = new PDO($dsn, CNPJ_DB_USER, CNPJ_DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function cnpj_q(string $sql, array $p = []): PDOStatement
{
    $st = cnpj_db()->prepare($sql);
    $st->execute($p);
    return $st;
}

function cnpj_one(string $sql, array $p = []): ?array
{
    $r = cnpj_q($sql, $p)->fetch();
    return $r ?: null;
}

function cnpj_all(string $sql, array $p = []): array
{
    return cnpj_q($sql, $p)->fetchAll();
}

function cnpj_val(string $sql, array $p = [])
{
    return cnpj_q($sql, $p)->fetchColumn();
}

// ─── WHERE builder ───────────────────────────────────────────────────────────

function cnpj_build_where(array $f): array
{
    $conds  = [];
    $params = [];

    if (!empty($f['q'])) {
        $q = trim($f['q']);
        $digits = preg_replace('/\D/', '', $q);
        if (strlen($digits) >= 8) {
            $conds[]  = 'e.cnpj_basico = ?';
            $params[] = substr($digits, 0, 8);
        } else {
            $conds[]  = "(emp.razao_social ILIKE ? OR e.nome_fantasia ILIKE ?)";
            $like     = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
    }

    if (!empty($f['situacao'])) {
        $conds[]  = 'e.situacao_cadastral = ?';
        $params[] = $f['situacao'];
    }

    if (!empty($f['uf'])) {
        $conds[]  = 'e.uf = ?';
        $params[] = strtoupper($f['uf']);
    }

    if (!empty($f['municipio'])) {
        $mun = trim((string)$f['municipio']);
        if ($mun !== '' && ctype_digit($mun)) {
            // Tenta o valor com e sem padding pra cobrir CHAR/VARCHAR storage.
            // PG IN com 2 valores indexáveis = rápido.
            $padded = str_pad($mun, 7);
            $conds[]  = 'e.municipio IN (?, ?)';
            $params[] = $mun;
            $params[] = $padded;
        }
    }

    // Filtro por bairro — multi-select via comma-separated (requer município para performance)
    // Compara em ASCII uppercase de ambos os lados:
    //   PG:  translate(UPPER(TRIM(e.bairro)), 'ÁÀÂÃÉÈÊÍÎÓÔÕÚÛÇ', 'AAAAEEEIIOOOUUC')
    //   PHP: iconv TRANSLIT → strip accents → uppercase
    // Assim "BOQUEIRAO" e "BOQUEIRÃO" (se o banco guardar com acento) sempre batem.
    if (!empty($f['bairros']) && !empty($f['municipio'])) {
        $bairros_arr = array_filter(array_map('trim', explode(',', (string)$f['bairros'])));
        if ($bairros_arr) {
            $ph = implode(',', array_fill(0, count($bairros_arr), '?'));
            // translate() remove acentos comuns do português sem precisar da extensão unaccent
            $conds[] = "translate(UPPER(TRIM(e.bairro)),
                            'ÁÀÂÃÄÉÈÊËÍÌÎÏÓÒÔÕÖÚÙÛÜÝÇ',
                            'AAAAAEEEEIIIIOOOOOOUUUUYC')
                        ILIKE ANY(ARRAY[$ph])";
            foreach ($bairros_arr as $b) {
                // Normaliza o valor de busca da mesma forma: ASCII uppercase
                $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', strtoupper(trim($b)));
                $params[] = ($ascii !== false && $ascii !== '') ? strtoupper($ascii) : strtoupper($b);
            }
        }
    }

    if (!empty($f['cnae'])) {
        $conds[]  = 'e.cnae_principal = ?';
        $params[] = $f['cnae'];
    }

    // Verticais: pre-resolve as CNAEs em PHP (planner do PG escolhe plano melhor com lista literal)
    // Funciona com vertical, sub_vertical OU ambos
    if (!empty($f['vertical']) || !empty($f['sub_vertical'])) {
        $where_inner = '';
        $params_inner = [];
        if (!empty($f['vertical']) && !empty($f['sub_vertical'])) {
            $where_inner = 'WHERE vertical_id = ? AND sub_vertical = ?';
            $params_inner = [$f['vertical'], $f['sub_vertical']];
        } elseif (!empty($f['vertical'])) {
            $where_inner = 'WHERE vertical_id = ?';
            $params_inner = [$f['vertical']];
        } else {
            $where_inner = 'WHERE sub_vertical = ?';
            $params_inner = [$f['sub_vertical']];
        }

        $vcnaes = [];
        try {
            $vcnaes = cnpj_all("SELECT cnae FROM cnpj_verticais_map $where_inner", $params_inner);
        } catch (\Throwable $e) {}

        $vcodes = array_column($vcnaes, 'cnae');
        if (!empty($vcodes)) {
            $placeholders = implode(',', array_fill(0, count($vcodes), '?'));
            $conds[] = "e.cnae_principal IN ($placeholders)";
            foreach ($vcodes as $c) $params[] = $c;
        } else {
            $conds[] = '1=0';
        }
    }

    if (!empty($f['porte'])) {
        $conds[]  = 'emp.porte_empresa = ?';
        $params[] = $f['porte'];
    }

    if (!empty($f['mf'])) {
        $conds[]  = 'e.identificador_mf = ?';
        $params[] = $f['mf'];
    }

    if (!empty($f['abertura_de'])) {
        $conds[]  = 'e.data_inicio_atividade >= ?';
        $params[] = $f['abertura_de'];
    }

    if (!empty($f['abertura_ate'])) {
        $conds[]  = 'e.data_inicio_atividade <= ?';
        $params[] = $f['abertura_ate'];
    }

    if (!empty($f['tem_email'])) {
        $conds[] = "e.email IS NOT NULL AND e.email <> ''";
    }

    if (!empty($f['tem_tel'])) {
        $conds[] = "e.telefone1 IS NOT NULL AND e.telefone1 <> ''";
    }

    if (!empty($f['simples'])) {
        $conds[] = 'EXISTS (SELECT 1 FROM rf_simples s WHERE s.cnpj_basico = e.cnpj_basico AND s.opcao_simples = \'S\')';
    }

    if (!empty($f['mei'])) {
        $conds[] = 'EXISTS (SELECT 1 FROM rf_simples s WHERE s.cnpj_basico = e.cnpj_basico AND s.opcao_mei = \'S\')';
    }

    if (!empty($f['sem_mei'])) {
        // Empresa sem registro MEI no rf_simples (ou registro com opcao_mei != 'S')
        $conds[] = 'NOT EXISTS (SELECT 1 FROM rf_simples s WHERE s.cnpj_basico = e.cnpj_basico AND s.opcao_mei = \'S\')';
    }

    if (!empty($f['capital_min'])) {
        $conds[]  = 'emp.capital_social >= ?';
        $params[] = (float) $f['capital_min'];
    }

    if (!empty($f['idade_min'])) {
        // Em anos
        $anos = (int) $f['idade_min'];
        $conds[]  = "e.data_inicio_atividade <= (CURRENT_DATE - (? || ' years')::interval)";
        $params[] = $anos;
    }

    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
    return [$where, $params];
}

// ─── Search ──────────────────────────────────────────────────────────────────

/**
 * Newton Score v2 — espelho SQL do cnpj_newton_score() em PHP.
 *
 *   Reach        (30): email corp +20 | free +10, telefone +10
 *   Buying power (25): porte (05=+15, 03=+10, 01=+5), capital (≥1M=+10, ≥100k=+5)
 *   Fit B2B      (25): vertical curada +15, setor B2B-friendly +5, coerência porte+capital +5
 *   Stability    (15): ativa +5, idade 3-15a=+10 | >15a=+7 | 1-3a=+3
 *   Brand         (5): matriz +3, fantasia válida +2
 *   Red flags    (-): fantasia censurada -10, nova-zumbi -15
 *
 * (DDD x UF não está no SQL — checado em PHP via cnpj_ddd_uf_match)
 */
const CNPJ_SCORE_SQL = "GREATEST(0, LEAST(100,
    -- Reach (30) com tier de email
    (CASE
        WHEN e.email IS NULL OR e.email = '' THEN 0
        WHEN e.email ILIKE '%@gmail.%'   OR e.email ILIKE '%@hotmail.%' OR e.email ILIKE '%@outlook.%'
          OR e.email ILIKE '%@yahoo.%'   OR e.email ILIKE '%@live.%'    OR e.email ILIKE '%@bol.com.br'
          OR e.email ILIKE '%@uol.com.br' OR e.email ILIKE '%@ig.com.br' OR e.email ILIKE '%@terra.com.br'
          OR e.email ILIKE '%@icloud.%'  OR e.email ILIKE '%@msn.%'     OR e.email ILIKE '%@r7.com'
          OR e.email ILIKE '%@globomail.%' THEN 10
        ELSE 20
     END) +
    (CASE WHEN e.telefone1 IS NOT NULL AND e.telefone1 <> '' THEN 10 ELSE 0 END) +

    -- Buying power (25)
    (CASE WHEN emp.porte_empresa = '05' THEN 15
          WHEN emp.porte_empresa = '03' THEN 10
          WHEN emp.porte_empresa = '01' THEN 5
          ELSE 0 END) +
    (CASE WHEN emp.capital_social >= 1000000 THEN 10
          WHEN emp.capital_social >= 100000  THEN 5
          ELSE 0 END) +

    -- Fit B2B (25)
    (CASE WHEN vm.vertical_id IS NOT NULL THEN 15 ELSE 0 END) +
    (CASE WHEN LEFT(e.cnae_principal::text, 1) IN ('2','3','6','7') THEN 5 ELSE 0 END) +
    (CASE WHEN emp.porte_empresa = '05' AND emp.capital_social >= 1000000 THEN 5 ELSE 0 END) +

    -- Stability (15)
    (CASE WHEN e.situacao_cadastral = '02' THEN 5 ELSE 0 END) +
    (CASE WHEN e.data_inicio_atividade BETWEEN CURRENT_DATE - INTERVAL '15 years' AND CURRENT_DATE - INTERVAL '3 years' THEN 10
          WHEN e.data_inicio_atividade <= CURRENT_DATE - INTERVAL '15 years' THEN 7
          WHEN e.data_inicio_atividade <= CURRENT_DATE - INTERVAL '1 year'   THEN 3
          ELSE 0 END) +

    -- Brand (5)
    (CASE WHEN e.identificador_mf = '1' THEN 3 ELSE 0 END) +
    (CASE WHEN e.nome_fantasia IS NOT NULL AND e.nome_fantasia <> '' AND e.nome_fantasia !~ '^\\*+$' THEN 2 ELSE 0 END)

    -- Red flags
    - (CASE WHEN e.nome_fantasia ~ '^\\*+$' THEN 10 ELSE 0 END)
    - (CASE WHEN e.data_inicio_atividade > CURRENT_DATE - INTERVAL '6 months'
             AND (e.email IS NULL OR e.email = '')
             AND (e.telefone1 IS NULL OR e.telefone1 = '')
           THEN 15 ELSE 0 END)
))";

const CNPJ_SORT_OPTIONS = [
    'qualified'    => 'sql_score DESC NULLS LAST, emp.capital_social DESC NULLS LAST',
    'razao'        => 'razao_social ASC',
    'recentes'     => 'e.data_inicio_atividade DESC NULLS LAST',
    'antigas'      => 'e.data_inicio_atividade ASC NULLS LAST',
    'capital_desc' => 'emp.capital_social DESC NULLS LAST',
];

function cnpj_search(array $f, int $page = 1, int $per = 20, string $sort = 'qualified'): array
{
    if (function_exists('cnpj_verticais_ensure_table')) cnpj_verticais_ensure_table();

    // Smart default: ativas
    if (empty($f['situacao']) && empty($f['_allow_inactive'])) {
        $f['situacao'] = '02';
    }

    // Sem MEI: aplica em PHP (NOT EXISTS é caro na WHERE principal)
    $apply_sem_mei_php = !empty($f['sem_mei']);
    if ($apply_sem_mei_php) unset($f['sem_mei']);

    [$where, $params] = cnpj_build_where($f);

    $orderBy = match ($sort) {
        'recentes' => 'e.data_inicio_atividade DESC NULLS LAST',
        'antigas'  => 'e.data_inicio_atividade ASC NULLS LAST',
        default    => 'e.data_inicio_atividade DESC NULLS LAST',
    };

    $total      = 0;
    $total_more = false;

    $offset = ($page - 1) * $per;
    $fetch_per = $apply_sem_mei_php ? (int)($per * 1.5 + 1) : $per + 1;

    // ── UMA query só, simples e direta. Igual à página de teste. ──
    try { cnpj_db()->exec("SET statement_timeout = '15s'"); } catch (\Throwable $e) {}

    $rows = cnpj_all(
        "SELECT
            e.cnpj_basico || e.cnpj_ordem || e.cnpj_dv AS cnpj,
            COALESCE(
                NULLIF(REGEXP_REPLACE(TRIM(emp.razao_social), '^\*+$', ''), ''),
                NULLIF(REGEXP_REPLACE(TRIM(e.nome_fantasia), '^\*+$', ''), ''),
                'CNPJ ' || e.cnpj_basico || e.cnpj_ordem || e.cnpj_dv
            ) AS razao_social,
            NULLIF(REGEXP_REPLACE(TRIM(e.nome_fantasia), '^\*+$', ''), '') AS nome_fantasia,
            e.situacao_cadastral, e.data_inicio_atividade, e.data_situacao_cadastral,
            e.cnae_principal,
            COALESCE(cn.descricao, e.cnae_principal) AS cnae_descricao,
            e.uf, e.municipio,
            COALESCE(mun.descricao, e.municipio::text) AS municipio_nome,
            e.tipo_logradouro, e.logradouro, e.numero, e.complemento, e.bairro, e.cep,
            e.ddd1, e.telefone1, e.ddd2, e.telefone2, e.email,
            COALESCE(emp.porte_empresa, '00') AS porte_empresa,
            emp.capital_social, emp.natureza_juridica,
            e.identificador_mf,
            EXISTS (SELECT 1 FROM rf_simples s WHERE s.cnpj_basico = e.cnpj_basico AND s.opcao_simples = 'S') AS is_simples,
            EXISTS (SELECT 1 FROM rf_simples s WHERE s.cnpj_basico = e.cnpj_basico AND s.opcao_mei = 'S')     AS is_mei,
            vm.vertical_id, vm.vertical_nome, vm.sub_vertical,
            NULL::int AS sql_score
         FROM rf_estabelecimentos e
         LEFT JOIN rf_empresas        emp ON emp.cnpj_basico = e.cnpj_basico
         LEFT JOIN rf_municipios      mun ON mun.codigo::text = e.municipio::text
         LEFT JOIN rf_cnaes           cn  ON cn.codigo        = e.cnae_principal
         LEFT JOIN cnpj_verticais_map vm  ON vm.cnae          = e.cnae_principal
         $where
         ORDER BY $orderBy
         LIMIT $fetch_per OFFSET $offset",
        $params
    );

    try { cnpj_db()->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

    $total_more = count($rows) > $per;
    if ($total_more) array_pop($rows);

    // Sem MEI em PHP
    if ($apply_sem_mei_php) {
        $rows = array_values(array_filter($rows, fn($r) => empty($r['is_mei'])));
        $rows = array_slice($rows, 0, $per);
    }

    // Ordenação 'qualified' = sort por Newton Score em PHP (estável, com tiebreaker)
    if ($sort === 'qualified' && function_exists('cnpj_newton_score')) {
        foreach ($rows as &$r) { $r['_score'] = cnpj_newton_score($r); }
        unset($r);
        usort($rows, function($a, $b) {
            // Score desc, depois capital desc, depois cnpj asc (deterministic)
            $diff = ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0);
            if ($diff !== 0) return $diff;
            $cap = ((float)($b['capital_social'] ?? 0)) <=> ((float)($a['capital_social'] ?? 0));
            if ($cap !== 0) return $cap;
            return ($a['cnpj'] ?? '') <=> ($b['cnpj'] ?? ''); // tiebreaker estável
        });
    }

    // COUNT exato (sem cap). Timeout 8s — se passar disso, mostra "100k+"
    $total = $offset + count($rows);
    try { cnpj_db()->exec("SET statement_timeout = '8s'"); } catch (\Throwable $e) {}
    try {
        $cnt = (int) cnpj_val(
            "SELECT COUNT(*) FROM rf_estabelecimentos e $where",
            $params
        );
        $total = $cnt;
        $total_more = false; // count é exato agora
    } catch (\Throwable $e) {
        // Timeout — fallback pra capped
        try {
            $CAP = 100000;
            $cnt = (int) cnpj_val(
                "SELECT COUNT(*) FROM (SELECT 1 FROM rf_estabelecimentos e $where LIMIT " . ($CAP + 1) . ") sub",
                $params
            );
            if ($cnt > $CAP) { $total = $CAP; $total_more = true; }
            else { $total = $cnt; $total_more = false; }
        } catch (\Throwable $e2) {
            // Mantém estimate
        }
    }
    try { cnpj_db()->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

    return ['rows' => $rows, 'total' => $total, 'total_more' => $total_more];
}

// ─── Detail (drawer) ─────────────────────────────────────────────────────────

function cnpj_detail(string $cnpj14): ?array
{
    $d = preg_replace('/\D/', '', $cnpj14);
    if (strlen($d) !== 14) return null;

    $basico = substr($d, 0, 8);
    $ordem  = substr($d, 8, 4);
    $dv     = substr($d, 12, 2);

    // Tenta query completa; se rf_naturezas / coluna 'descricao' não existir, retira o JOIN
    $detail_sql = "SELECT
            e.cnpj_basico || e.cnpj_ordem || e.cnpj_dv AS cnpj,
            e.cnpj_basico,
            COALESCE(emp.razao_social, e.nome_fantasia, 'N/D') AS razao_social,
            e.nome_fantasia,
            e.situacao_cadastral,
            e.data_situacao_cadastral,
            e.motivo_situacao_cadastral,
            e.data_inicio_atividade,
            e.cnae_principal,
            COALESCE(cn.descricao, e.cnae_principal) AS cnae_descricao,
            e.cnae_secundaria,
            e.uf,
            e.municipio,
            COALESCE(mun.descricao, e.municipio::text) AS municipio_nome,
            e.tipo_logradouro, e.logradouro, e.numero, e.complemento, e.bairro, e.cep,
            e.ddd1, e.telefone1, e.ddd2, e.telefone2, e.email,
            COALESCE(emp.porte_empresa, '00') AS porte_empresa,
            emp.capital_social,
            emp.natureza_juridica,
            nj.descricao AS natureza_juridica_nome,
            e.identificador_mf,
            EXISTS (SELECT 1 FROM rf_simples s WHERE s.cnpj_basico = e.cnpj_basico AND s.opcao_simples = 'S') AS is_simples,
            EXISTS (SELECT 1 FROM rf_simples s WHERE s.cnpj_basico = e.cnpj_basico AND s.opcao_mei = 'S')     AS is_mei
         FROM rf_estabelecimentos e
         LEFT JOIN rf_empresas       emp ON emp.cnpj_basico = e.cnpj_basico
         LEFT JOIN rf_municipios     mun ON mun.codigo::text = e.municipio::text
         LEFT JOIN rf_cnaes          cn  ON cn.codigo        = e.cnae_principal
         LEFT JOIN rf_naturezas      nj  ON nj.codigo::text  = emp.natureza_juridica::text
         WHERE e.cnpj_basico = ? AND e.cnpj_ordem = ? AND e.cnpj_dv = ?
         LIMIT 1";

    try {
        $row = cnpj_one($detail_sql, [$basico, $ordem, $dv]);
    } catch (\Throwable $e) {
        // Fallback: remove o JOIN com rf_naturezas (caso a tabela/coluna varie)
        $fallback = preg_replace(
            '/nj\.descricao AS natureza_juridica_nome/',
            'NULL AS natureza_juridica_nome',
            $detail_sql
        );
        $fallback = preg_replace(
            '/LEFT JOIN rf_naturezas\s+nj\s+ON[^\n]+/',
            '',
            $fallback
        );
        $row = cnpj_one($fallback, [$basico, $ordem, $dv]);
    }

    if (!$row) return null;

    // Sócios — usa s.* pra ser tolerante a schema diferente
    try {
        $row['socios'] = cnpj_all(
            "SELECT s.*, q.descricao AS qualificacao_nome
             FROM rf_socios s
             LEFT JOIN rf_qualificacoes q ON q.codigo::text = s.qualificacao::text
             WHERE s.cnpj_basico = ?
             LIMIT 30",
            [$basico]
        );
    } catch (\Throwable $e) {
        try {
            $row['socios'] = cnpj_all(
                "SELECT s.* FROM rf_socios s WHERE s.cnpj_basico = ? LIMIT 30",
                [$basico]
            );
        } catch (\Throwable $e2) {
            $row['socios'] = [];
        }
    }

    // Atividades secundárias (códigos separados por vírgula)
    $row['cnaes_secundarios'] = [];
    if (!empty($row['cnae_secundaria'])) {
        $codes = array_filter(array_map('trim', explode(',', $row['cnae_secundaria'])));
        if ($codes) {
            $place = implode(',', array_fill(0, count($codes), '?'));
            $row['cnaes_secundarios'] = cnpj_all(
                "SELECT codigo, descricao FROM rf_cnaes WHERE codigo IN ($place) ORDER BY codigo",
                $codes
            );
        }
    }

    return $row;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function cnpj_endereco_fmt(array $r): string
{
    $partes = array_filter([
        trim(($r['tipo_logradouro'] ?? '') . ' ' . ($r['logradouro'] ?? '')),
        $r['numero'] ?? null,
        $r['bairro'] ?? null,
    ]);
    $endereco = implode(', ', $partes);
    $loc = trim(($r['municipio_nome'] ?? '') . '/' . ($r['uf'] ?? ''), '/');
    return trim($endereco . ' · ' . $loc, ' ·');
}

function cnpj_idade(?string $dataIso): ?string
{
    if (!$dataIso) return null;
    try {
        $d = new DateTime($dataIso);
    } catch (\Throwable $e) { return null; }
    $diff = (new DateTime())->diff($d);
    if ($diff->y > 0) return 'há ' . $diff->y . ($diff->y === 1 ? ' ano' : ' anos');
    if ($diff->m > 0) return 'há ' . $diff->m . ($diff->m === 1 ? ' mês' : ' meses');
    if ($diff->d > 0) return 'há ' . $diff->d . ($diff->d === 1 ? ' dia' : ' dias');
    return 'hoje';
}

function cnpj_data_br(?string $iso): string
{
    if (!$iso) return '';
    try { return (new DateTime($iso))->format('d/m/Y'); }
    catch (\Throwable $e) { return $iso; }
}

function cnpj_capital_fmt($valor): string
{
    if ($valor === null || $valor === '') return '—';
    $v = (float) $valor;
    if ($v >= 1_000_000) return 'R$ ' . number_format($v / 1_000_000, 1, ',', '.') . ' mi';
    if ($v >= 1_000)     return 'R$ ' . number_format($v / 1_000,     1, ',', '.') . ' mil';
    return 'R$ ' . number_format($v, 2, ',', '.');
}

function cnpj_avatar_color(string $seed): string
{
    $colors = ['#6366f1','#8b5cf6','#ec4899','#f59e0b','#10b981','#06b6d4','#3b82f6','#ef4444'];
    return $colors[abs(crc32($seed)) % count($colors)];
}

function cnpj_initial(string $name): string
{
    $name = trim($name);
    if ($name === '') return '?';
    return mb_strtoupper(mb_substr($name, 0, 1));
}

function cnpj_nfmt_br(int $n): string
{
    return number_format($n, 0, ',', '.');
}

function cnpj_tel_fmt(?string $ddd, ?string $tel): string
{
    if (!$tel) return '';
    $tel = preg_replace('/\D/', '', $tel);
    if (!$tel) return '';
    if (strlen($tel) === 9) $tel = substr($tel, 0, 5) . '-' . substr($tel, 5);
    elseif (strlen($tel) === 8) $tel = substr($tel, 0, 4) . '-' . substr($tel, 4);
    return ($ddd ? '(' . $ddd . ') ' : '') . $tel;
}

// ─── Quota v2 ─────────────────────────────────────────────────────────────────

function cnpj_monthly_limit(int $tenantId): int
{
    // HERMES v2: lê do tenant.plan_id → plans.limit_cnpj_monthly (não mais cnpj_plans legado).
    // Soma também addon credits manuais + lead pack credits ativos.
    $row = db_one(
        "SELECT t.cnpj_limit_override, t.cnpj_addon_credits,
                p.limit_cnpj_monthly AS plan_limit,
                cp.monthly_limit     AS legacy_limit
         FROM tenants t
         LEFT JOIN plans p ON p.id = t.plan_id
         LEFT JOIN cnpj_plans cp ON cp.id = t.cnpj_plan_id
         WHERE t.id = ?",
        [$tenantId]
    );
    if (!$row) return 0;
    // Prioridade: override manual → plano HERMES → plano legacy → 0
    $base = $row['cnpj_limit_override'] !== null ? (int) $row['cnpj_limit_override']
          : ((int) ($row['plan_limit']   ?? 0) > 0 ? (int) $row['plan_limit']
          : (int) ($row['legacy_limit'] ?? 0));

    // Lead pack saldo ativo (validade <12 meses)
    $pack_balance = 0;
    try {
        $pack_balance = (int) db_val(
            "SELECT COALESCE(SUM(leads), 0) FROM lead_pack_credits
             WHERE tenant_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())",
            [$tenantId]
        );
    } catch (\Throwable $e) { /* tabela pode ainda não existir */ }

    return $base + (int) $row['cnpj_addon_credits'] + $pack_balance;
}

function cnpj_base_limit(int $tenantId): int
{
    return (int) db_val(
        "SELECT COALESCE(t.cnpj_limit_override, p.limit_cnpj_monthly, cp.monthly_limit, 0)
         FROM tenants t
         LEFT JOIN plans p ON p.id = t.plan_id
         LEFT JOIN cnpj_plans cp ON cp.id = t.cnpj_plan_id
         WHERE t.id = ?",
        [$tenantId]
    );
}

function cnpj_monthly_used(int $tenantId): int
{
    return (int) db_val(
        "SELECT COALESCE(SUM(records_count), 0)
         FROM cnpj_download_log
         WHERE tenant_id = ?
           AND YEAR(downloaded_at)  = YEAR(NOW())
           AND MONTH(downloaded_at) = MONTH(NOW())",
        [$tenantId]
    );
}

function cnpj_quota_log(int $tenantId, int $rows, array $filters = []): void
{
    if ($rows <= 0) return;

    db_insert('cnpj_download_log', [
        'tenant_id'    => $tenantId,
        'records_count'=> $rows,
        'filters_json' => json_encode($filters, JSON_UNESCAPED_UNICODE),
    ]);

    // Deduct addon credits for any overflow past the base plan
    $base      = cnpj_base_limit($tenantId);
    $usedAfter = cnpj_monthly_used($tenantId);
    $overflow  = min($rows, max(0, $usedAfter - $base));
    if ($overflow > 0) {
        db_q(
            "UPDATE tenants SET cnpj_addon_credits = GREATEST(0, cnpj_addon_credits - ?) WHERE id = ?",
            [$overflow, $tenantId]
        );
    }
}

function cnpj_usage_pct(int $used, int $limit): float
{
    if ($limit <= 0) return 100.0;
    return min(100.0, round($used / $limit * 100, 1));
}

const CNPJ_ALERT_THRESHOLDS = [90, 80, 70, 60, 50];

function cnpj_alert_message(float $pct, int $used, int $limit): ?string
{
    foreach (CNPJ_ALERT_THRESHOLDS as $t) {
        if ($pct >= $t) {
            $remaining = $limit - $used;
            return "Você usou {$pct}% do limite mensal ({$used}/{$limit} leads). Restam {$remaining} leads.";
        }
    }
    return null;
}

// ─── Constants ───────────────────────────────────────────────────────────────

const CNPJ_SITUACOES = [
    '01' => 'Nula',
    '02' => 'Ativa',
    '03' => 'Suspensa',
    '04' => 'Inapta',
    '08' => 'Baixada',
];

const CNPJ_PORTES = [
    '00' => 'Não informado',
    '01' => 'Micro empresa',
    '03' => 'Empresa de Pequeno Porte',
    '05' => 'Demais',
];

const CNPJ_UFS = [
    'AC','AL','AM','AP','BA','CE','DF','ES','GO','MA',
    'MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN',
    'RO','RR','RS','SC','SE','SP','TO',
];

// ─── Formatters ──────────────────────────────────────────────────────────────

function cnpj_fmt(string $cnpj): string
{
    $d = preg_replace('/\D/', '', $cnpj);
    if (strlen($d) !== 14) return $cnpj;
    return substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'
         . substr($d,8,4).'-'.substr($d,12,2);
}

function cnpj_situacao_label(string $code): string
{
    return CNPJ_SITUACOES[$code] ?? $code;
}

function cnpj_porte_label(string $code): string
{
    return CNPJ_PORTES[$code] ?? $code;
}
