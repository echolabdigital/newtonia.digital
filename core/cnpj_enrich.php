<?php
/**
 * Newton AI — Enrichment (fontes externas)
 * - ReceitaWS (free, 3 req/min)
 * - publica.cnpj.ws (free, fallback)
 * - Google Places (chave em GOOGLE_PLACES_API_KEY no config.php)
 *
 * Cache agressivo no MySQL pra economizar quota (e $ no Places).
 */

// ─── Migration lazy (executa 1× na 1ª chamada) ───────────────────────────────
function cnpj_enrich_init_schema(): void
{
    static $done = false;
    if ($done) return;
    db_q("CREATE TABLE IF NOT EXISTS cnpj_enrichment_cache (
        cnpj        VARCHAR(14) NOT NULL,
        source      VARCHAR(40) NOT NULL,
        data        JSON        NOT NULL,
        fetched_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at  TIMESTAMP   NOT NULL,
        PRIMARY KEY (cnpj, source),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

// ─── Cache get/put ───────────────────────────────────────────────────────────
function cnpj_enrich_get(string $cnpj, string $source): ?array
{
    cnpj_enrich_init_schema();
    $row = db_one(
        "SELECT data, fetched_at FROM cnpj_enrichment_cache
         WHERE cnpj = ? AND source = ? AND expires_at > NOW()",
        [$cnpj, $source]
    );
    if (!$row) return null;
    $data = json_decode($row['data'], true);
    if (is_array($data)) $data['_cached_at'] = $row['fetched_at'];
    return $data;
}

function cnpj_enrich_save(string $cnpj, string $source, array $data, int $ttl_days = 30): void
{
    cnpj_enrich_init_schema();
    db_q(
        "REPLACE INTO cnpj_enrichment_cache (cnpj, source, data, fetched_at, expires_at)
         VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))",
        [$cnpj, $source, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE), $ttl_days]
    );
}

// ─── HTTP helper ─────────────────────────────────────────────────────────────
function cnpj_http_get_json(string $url, int $timeout = 8): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'NewtonAI/1.0 (+https://newtonia.com)',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) return null;
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

// ─── ReceitaWS ───────────────────────────────────────────────────────────────
function cnpj_enrich_receitaws(string $cnpj14): ?array
{
    $cached = cnpj_enrich_get($cnpj14, 'receitaws');
    if ($cached !== null) return $cached;

    $data = cnpj_http_get_json("https://www.receitaws.com.br/v1/cnpj/{$cnpj14}");
    if (!$data || ($data['status'] ?? '') === 'ERROR') {
        // cacheia negativo por 1 dia pra não martelar
        cnpj_enrich_save($cnpj14, 'receitaws', ['_error' => true], 1);
        return null;
    }

    $norm = [
        'razao_social'   => $data['nome']             ?? null,
        'nome_fantasia'  => $data['fantasia']         ?? null,
        'situacao'       => $data['situacao']         ?? null,
        'situacao_data'  => $data['data_situacao']    ?? null,
        'abertura'       => $data['abertura']         ?? null,
        'natureza'       => $data['natureza_juridica']?? null,
        'porte'          => $data['porte']            ?? null,
        'capital_social' => $data['capital_social']   ?? null,
        'atividade_principal' => $data['atividade_principal'][0] ?? null,
        'atividades_secundarias' => $data['atividades_secundarias'] ?? [],
        'tipo'           => $data['tipo']             ?? null,
        'endereco'       => trim(
            ($data['logradouro'] ?? '') . ', ' .
            ($data['numero']     ?? '') . ' ' .
            ($data['complemento']?? '') . ' - ' .
            ($data['bairro']     ?? '') . ', ' .
            ($data['municipio']  ?? '') . '/' . ($data['uf'] ?? '')
        ),
        'cep'            => $data['cep']              ?? null,
        'telefone'       => $data['telefone']         ?? null,
        'email'          => $data['email']            ?? null,
        'qsa'            => $data['qsa']              ?? [],
        'simples'        => $data['simples']          ?? null,
        'simei'          => $data['simei']            ?? null,
        'ultima_atualizacao' => $data['ultima_atualizacao'] ?? null,
    ];

    cnpj_enrich_save($cnpj14, 'receitaws', $norm, 30);
    return $norm;
}

// ─── publica.cnpj.ws (fallback gratuito) ─────────────────────────────────────
function cnpj_enrich_publica(string $cnpj14): ?array
{
    $cached = cnpj_enrich_get($cnpj14, 'publica');
    if ($cached !== null) return $cached;

    $data = cnpj_http_get_json("https://publica.cnpj.ws/cnpj/{$cnpj14}");
    if (!$data || empty($data['estabelecimento'])) {
        cnpj_enrich_save($cnpj14, 'publica', ['_error' => true], 1);
        return null;
    }

    $est  = $data['estabelecimento'];
    $norm = [
        'razao_social'   => $data['razao_social']   ?? null,
        'nome_fantasia'  => $est['nome_fantasia']   ?? null,
        'situacao'       => $est['situacao_cadastral'] ?? null,
        'situacao_data'  => $est['data_situacao_cadastral'] ?? null,
        'abertura'       => $est['data_inicio_atividade'] ?? null,
        'natureza'       => $data['natureza_juridica']['descricao'] ?? null,
        'porte'          => $data['porte']['descricao'] ?? null,
        'capital_social' => $data['capital_social'] ?? null,
        'atividade_principal' => $est['atividade_principal'] ?? null,
        'atividades_secundarias' => $est['atividades_secundarias'] ?? [],
        'tipo'           => $est['tipo'] ?? null,
        'endereco'       => trim(
            ($est['tipo_logradouro'] ?? '') . ' ' .
            ($est['logradouro']      ?? '') . ', ' .
            ($est['numero']          ?? '') . ' ' .
            ($est['complemento']     ?? '') . ' - ' .
            ($est['bairro']          ?? '') . ', ' .
            ($est['cidade']['nome']  ?? '') . '/' . ($est['estado']['sigla'] ?? '')
        ),
        'cep'            => $est['cep']      ?? null,
        'telefone'       => trim((($est['ddd1'] ?? '') . ' ' . ($est['telefone1'] ?? '')) . ' / ' . (($est['ddd2'] ?? '') . ' ' . ($est['telefone2'] ?? ''))),
        'email'          => $est['email']    ?? null,
        'qsa'            => $data['socios']  ?? [],
        'simples'        => $data['simples'] ?? null,
        'ultima_atualizacao' => $est['atualizado_em'] ?? null,
    ];

    cnpj_enrich_save($cnpj14, 'publica', $norm, 30);
    return $norm;
}

// ─── Google Places (paid; cache agressivo) ───────────────────────────────────
function cnpj_enrich_google_places(string $cnpj14, string $razao, string $fantasia, string $cidade, string $uf): ?array
{
    $cached = cnpj_enrich_get($cnpj14, 'gplaces');
    if ($cached !== null) return $cached;

    if (!defined('GOOGLE_PLACES_API_KEY') || !GOOGLE_PLACES_API_KEY) {
        return ['_error' => 'sem_chave'];
    }

    // 1) Find Place (cheap SKU)
    $nameForSearch = $fantasia ?: $razao;
    $query = trim($nameForSearch . ' ' . $cidade . ' ' . $uf);
    $findUrl = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json'
             . '?input=' . urlencode($query)
             . '&inputtype=textquery'
             . '&fields=place_id,name,formatted_address,rating,user_ratings_total,business_status,types'
             . '&language=pt-BR'
             . '&key=' . GOOGLE_PLACES_API_KEY;

    $find = cnpj_http_get_json($findUrl, 6);
    if (!$find || empty($find['candidates'])) {
        cnpj_enrich_save($cnpj14, 'gplaces', ['_found' => false], 7);
        return ['_found' => false];
    }

    $cand     = $find['candidates'][0];
    $place_id = $cand['place_id'] ?? null;
    if (!$place_id) {
        cnpj_enrich_save($cnpj14, 'gplaces', ['_found' => false], 7);
        return ['_found' => false];
    }

    // 2) Place Details — campos pra entrar no Contact + Atmosphere SKU
    $fields = 'name,formatted_address,formatted_phone_number,international_phone_number,'
            . 'website,rating,user_ratings_total,opening_hours,url,types,business_status,'
            . 'photos,reviews';
    $detUrl = 'https://maps.googleapis.com/maps/api/place/details/json'
            . '?place_id=' . urlencode($place_id)
            . '&fields='   . $fields
            . '&language=pt-BR'
            . '&key=' . GOOGLE_PLACES_API_KEY;

    $det = cnpj_http_get_json($detUrl, 8);
    if (!$det || empty($det['result'])) {
        cnpj_enrich_save($cnpj14, 'gplaces', ['_found' => false], 7);
        return ['_found' => false];
    }
    $r = $det['result'];

    // Foto URL (só o ref, montamos URL sob demanda)
    $photo_ref = $r['photos'][0]['photo_reference'] ?? null;

    // Top 3 reviews (texto curto)
    $reviews = [];
    foreach (array_slice($r['reviews'] ?? [], 0, 3) as $rev) {
        $reviews[] = [
            'author'  => $rev['author_name']        ?? null,
            'rating'  => $rev['rating']             ?? null,
            'text'    => mb_substr($rev['text'] ?? '', 0, 280),
            'time'    => $rev['relative_time_description'] ?? null,
        ];
    }

    $norm = [
        '_found'          => true,
        'place_id'        => $place_id,
        'name'            => $r['name'] ?? null,
        'address'         => $r['formatted_address'] ?? null,
        'phone'           => $r['formatted_phone_number'] ?? null,
        'phone_intl'      => $r['international_phone_number'] ?? null,
        'website'         => $r['website'] ?? null,
        'maps_url'        => $r['url'] ?? null,
        'rating'          => $r['rating'] ?? null,
        'reviews_total'   => $r['user_ratings_total'] ?? null,
        'business_status' => $r['business_status'] ?? null,
        'is_open_now'     => $r['opening_hours']['open_now'] ?? null,
        'hours'           => $r['opening_hours']['weekday_text'] ?? null,
        'types'           => $r['types'] ?? null,
        'photo_ref'       => $photo_ref,
        'reviews_sample'  => $reviews,
    ];

    cnpj_enrich_save($cnpj14, 'gplaces', $norm, 30);
    return $norm;
}

// ─── Photo URL builder (não consome quota até o cliente abrir) ───────────────
function cnpj_gplaces_photo_url(?string $ref, int $maxwidth = 400): ?string
{
    if (!$ref || !defined('GOOGLE_PLACES_API_KEY') || !GOOGLE_PLACES_API_KEY) return null;
    return 'https://maps.googleapis.com/maps/api/place/photo'
         . '?maxwidth=' . $maxwidth
         . '&photoreference=' . urlencode($ref)
         . '&key=' . GOOGLE_PLACES_API_KEY;
}

// ─── Roda tudo (ReceitaWS → fallback publica + Google Places) ────────────────
function cnpj_enrich_all(string $cnpj14, array $base): array
{
    $out = ['receitaws' => null, 'publica' => null, 'gplaces' => null];

    $out['receitaws'] = cnpj_enrich_receitaws($cnpj14);
    if (!$out['receitaws']) {
        $out['publica'] = cnpj_enrich_publica($cnpj14);
    }

    $razao    = $base['razao_social']  ?? '';
    $fantasia = $base['nome_fantasia'] ?? '';
    $cidade   = $base['municipio_nome'] ?? $base['municipio'] ?? '';
    $uf       = $base['uf'] ?? '';
    if (($razao || $fantasia) && $cidade) {
        $out['gplaces'] = cnpj_enrich_google_places($cnpj14, $razao, $fantasia, $cidade, $uf);
    }

    return $out;
}
