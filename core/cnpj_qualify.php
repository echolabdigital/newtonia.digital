<?php
/**
 * Newton AI — Camada de qualificação (enrichment) de empresas
 * Heurísticas em cima dos dados públicos da Receita Federal,
 * sem dependência de APIs externas.
 */

// ─── Newton Score v2 (0-100) ──────────────────────────────────────────────────
// Distribuição alvo: cap real em 100 (sem inflate), red flags subtrativos,
// CNAE-vertical e tier de email corporativo como sinais diferenciadores.
//
//   Reach        (30): email corp +20 | email free +10, telefone1 +10
//   Buying power (25): porte (05=+15, 03=+10, 01=+5), capital (≥1M=+10, ≥100k=+5)
//   Fit B2B      (25): CNAE em vertical curada +15, setor B2B-friendly +5,
//                      coerência porte+capital (05 com ≥1M) +5
//   Stability    (15): situação ativa +5, idade 3-15a +10 | >15a +7 | 1-3a +3
//   Brand         (5): matriz +3, fantasia válida +2
//   Red flags     (-): fantasia censurada -10, empresa nova-zumbi -15
function cnpj_newton_score(array $r): int
{
    return cnpj_newton_score_breakdown($r)['total'];
}

// Retorna breakdown completo (usado no drawer pra explicar o score).
function cnpj_newton_score_breakdown(array $r): array
{
    $parts = [];

    // ── Reach (30) ───────────────────────────────────────────────────────
    $reach = ['cat' => 'Reach', 'icon' => '📧', 'max' => 30, 'pts' => 0, 'signals' => []];
    $email = trim((string)($r['email'] ?? ''));
    if ($email !== '') {
        $isCorp = cnpj_email_is_corporate($email);
        $pts = $isCorp ? 20 : 10;
        $reach['pts'] += $pts;
        $reach['signals'][] = [
            'label' => $isCorp ? 'Email corporativo' : 'Email genérico (gmail/hotmail)',
            'pts'   => $pts,
            'hit'   => true,
        ];
    } else {
        $reach['signals'][] = ['label' => 'Sem email cadastrado', 'pts' => 0, 'hit' => false, 'hint' => 'Enriqueça com ReceitaWS'];
    }
    if (!empty($r['telefone1'])) {
        $reach['pts'] += 10;
        $reach['signals'][] = ['label' => 'Telefone', 'pts' => 10, 'hit' => true];
    } else {
        $reach['signals'][] = ['label' => 'Sem telefone', 'pts' => 0, 'hit' => false];
    }
    $parts[] = $reach;

    // ── Buying Power (25) ────────────────────────────────────────────────
    $buy = ['cat' => 'Buying Power', 'icon' => '💰', 'max' => 25, 'pts' => 0, 'signals' => []];
    $porte = $r['porte_empresa'] ?? '';
    if     ($porte === '05') { $buy['pts'] += 15; $buy['signals'][] = ['label' => 'Porte: Demais/Grande', 'pts' => 15, 'hit' => true]; }
    elseif ($porte === '03') { $buy['pts'] += 10; $buy['signals'][] = ['label' => 'Porte: EPP',           'pts' => 10, 'hit' => true]; }
    elseif ($porte === '01') { $buy['pts'] += 5;  $buy['signals'][] = ['label' => 'Porte: ME',            'pts' => 5,  'hit' => true]; }
    else                     { $buy['signals'][] = ['label' => 'Porte não declarado (MEI ou sem)', 'pts' => 0, 'hit' => false]; }

    $cap = (float)($r['capital_social'] ?? 0);
    if     ($cap >= 1_000_000) { $buy['pts'] += 10; $buy['signals'][] = ['label' => 'Capital ≥ R$ 1M',   'pts' => 10, 'hit' => true]; }
    elseif ($cap >= 100_000)   { $buy['pts'] += 5;  $buy['signals'][] = ['label' => 'Capital ≥ R$ 100k', 'pts' => 5,  'hit' => true]; }
    else                       { $buy['signals'][] = ['label' => 'Capital social baixo ou não declarado', 'pts' => 0, 'hit' => false]; }
    $parts[] = $buy;

    // ── Fit B2B (25) — sinal diferenciador chave ──────────────────────────
    $fit = ['cat' => 'Fit B2B', 'icon' => '🎯', 'max' => 25, 'pts' => 0, 'signals' => []];
    if (!empty($r['vertical_id'])) {
        $fit['pts'] += 15;
        $fit['signals'][] = ['label' => 'CNAE em vertical B2B curada (' . ($r['vertical_nome'] ?? '') . ')', 'pts' => 15, 'hit' => true];
    } else {
        $fit['signals'][] = ['label' => 'CNAE não está em vertical curada', 'pts' => 0, 'hit' => false];
    }
    $cnae = (string)($r['cnae_principal'] ?? '');
    if ($cnae !== '') {
        $d1 = $cnae[0];
        // 2-3 = indústria, 6-7 = informação, financeiro, serviços profissionais (B2B-friendly)
        if (in_array($d1, ['2','3','6','7'], true)) {
            $fit['pts'] += 5;
            $fit['signals'][] = ['label' => 'Setor B2B-friendly (indústria, TI ou serviços pro)', 'pts' => 5, 'hit' => true];
        }
    }
    if ($porte === '05' && $cap >= 1_000_000) {
        $fit['pts'] += 5;
        $fit['signals'][] = ['label' => 'Porte coerente com capital (grande + ≥R$ 1M)', 'pts' => 5, 'hit' => true];
    }
    $parts[] = $fit;

    // ── Stability (15) ────────────────────────────────────────────────────
    $stab = ['cat' => 'Stability', 'icon' => '🏛', 'max' => 15, 'pts' => 0, 'signals' => []];
    if (($r['situacao_cadastral'] ?? '') === '02') {
        $stab['pts'] += 5;
        $stab['signals'][] = ['label' => 'Empresa ativa', 'pts' => 5, 'hit' => true];
    } else {
        $stab['signals'][] = ['label' => 'Não está ativa', 'pts' => 0, 'hit' => false];
    }
    $anos = cnpj_anos_atividade($r['data_inicio_atividade'] ?? null);
    if     ($anos >= 3 && $anos <= 15) { $stab['pts'] += 10; $stab['signals'][] = ['label' => "Idade sweet-spot ({$anos}a)", 'pts' => 10, 'hit' => true]; }
    elseif ($anos > 15)                { $stab['pts'] += 7;  $stab['signals'][] = ['label' => "Tradicional ({$anos}a)",     'pts' => 7,  'hit' => true]; }
    elseif ($anos >= 1)                { $stab['pts'] += 3;  $stab['signals'][] = ['label' => "Jovem ({$anos}a)",            'pts' => 3,  'hit' => true]; }
    else                               { $stab['signals'][] = ['label' => 'Aberta há menos de 1 ano', 'pts' => 0, 'hit' => false]; }
    $parts[] = $stab;

    // ── Brand (5) ─────────────────────────────────────────────────────────
    $brand = ['cat' => 'Brand', 'icon' => '🏷', 'max' => 5, 'pts' => 0, 'signals' => []];
    if (($r['identificador_mf'] ?? '') === '1') {
        $brand['pts'] += 3;
        $brand['signals'][] = ['label' => 'Matriz', 'pts' => 3, 'hit' => true];
    }
    $fant = trim((string)($r['nome_fantasia'] ?? ''));
    $fant_censured = ($fant !== '' && preg_match('/^\*+$/', $fant));
    if ($fant !== '' && !$fant_censured) {
        $brand['pts'] += 2;
        $brand['signals'][] = ['label' => 'Nome fantasia preenchido', 'pts' => 2, 'hit' => true];
    }
    $parts[] = $brand;

    // ── Soma das partes positivas ─────────────────────────────────────────
    $positive = 0;
    foreach ($parts as $p) $positive += $p['pts'];

    // ── Red flags (subtrativos) ──────────────────────────────────────────
    $red_flags = [];
    $penalty = 0;
    if ($fant_censured) {
        $penalty += 10;
        $red_flags[] = ['label' => 'Nome fantasia censurado (***)', 'penalty' => 10];
    }
    if ($anos < 1 && $email === '' && empty($r['telefone1'])) {
        $penalty += 15;
        $red_flags[] = ['label' => 'Nova e sem contato (possível fantasma)', 'penalty' => 15];
    }
    // DDD x UF (sinal soft — só badge, não penaliza no score core)
    $ddd_uf_ok = cnpj_ddd_uf_match($r['ddd1'] ?? null, $r['uf'] ?? null);
    if (!$ddd_uf_ok) {
        $penalty += 5;
        $red_flags[] = ['label' => 'DDD não bate com UF (dado defasado)', 'penalty' => 5];
    }

    $total = max(0, min(100, $positive - $penalty));

    // Tier
    if     ($total >= 70) { $tier = 'hot';  $tier_label = '🔥 Quente'; }
    elseif ($total >= 50) { $tier = 'warm'; $tier_label = '⭐ Bom'; }
    elseif ($total >= 25) { $tier = 'cool'; $tier_label = '🌱 Médio'; }
    else                  { $tier = 'cold'; $tier_label = '❄ Frio'; }

    return [
        'total'      => $total,
        'positive'   => $positive,
        'penalty'    => $penalty,
        'tier'       => $tier,
        'tier_label' => $tier_label,
        'parts'      => $parts,
        'red_flags'  => $red_flags,
    ];
}

function cnpj_score_class(int $s): string
{
    if ($s >= 70) return 'score-hot';
    if ($s >= 50) return 'score-warm';
    if ($s >= 25) return 'score-cool';
    return 'score-cold';
}

function cnpj_score_label(int $s): string
{
    if ($s >= 70) return '🔥 Quente';
    if ($s >= 50) return '⭐ Bom';
    if ($s >= 25) return '🌱 Médio';
    return '❄ Frio';
}

// ─── Idade em anos ────────────────────────────────────────────────────────────
function cnpj_anos_atividade(?string $dataIso): int
{
    if (!$dataIso) return 0;
    try {
        $d = new DateTime($dataIso);
        return (int) (new DateTime())->diff($d)->y;
    } catch (\Throwable $e) { return 0; }
}

// ─── Faturamento estimado (faixa) ────────────────────────────────────────────
// Baseado em limites legais brasileiros + capital social como ajuste.
function cnpj_faturamento_estimado(array $r): array
{
    $porte    = $r['porte_empresa'] ?? '';
    $capital  = (float) ($r['capital_social'] ?? 0);
    $is_mei   = !empty($r['is_mei']);

    if ($is_mei) {
        return ['faixa' => 'Até R$ 81 mil/ano', 'classe' => 'mei', 'fonte' => 'Limite legal MEI'];
    }
    switch ($porte) {
        case '01': // Micro
            return ['faixa' => 'Até R$ 360 mil/ano', 'classe' => 'me', 'fonte' => 'Limite legal ME'];
        case '03': // EPP
            return ['faixa' => 'R$ 360 mil – R$ 4,8 mi/ano', 'classe' => 'epp', 'fonte' => 'Limite legal EPP'];
        case '05': // Demais
            if ($capital >= 50_000_000) return ['faixa' => 'Acima de R$ 100 mi/ano',  'classe' => 'grande', 'fonte' => 'Estimado por capital social'];
            if ($capital >= 10_000_000) return ['faixa' => 'R$ 30 mi – R$ 100 mi/ano', 'classe' => 'medio',  'fonte' => 'Estimado por capital social'];
            if ($capital >=  1_000_000) return ['faixa' => 'R$ 5 mi – R$ 30 mi/ano',   'classe' => 'medio',  'fonte' => 'Estimado por capital social'];
            return ['faixa' => 'Acima de R$ 4,8 mi/ano', 'classe' => 'medio', 'fonte' => 'Porte: Demais'];
    }
    // Sem porte: usa capital
    if ($capital >= 10_000_000) return ['faixa' => 'R$ 30 mi – R$ 100 mi/ano (estimado)', 'classe' => 'medio', 'fonte' => 'Estimado por capital'];
    if ($capital >=  1_000_000) return ['faixa' => 'R$ 4 mi – R$ 20 mi/ano (estimado)',   'classe' => 'medio', 'fonte' => 'Estimado por capital'];
    if ($capital >=    100_000) return ['faixa' => 'R$ 500 mil – R$ 4 mi/ano (estimado)', 'classe' => 'epp',   'fonte' => 'Estimado por capital'];
    return ['faixa' => 'Não estimado', 'classe' => 'na', 'fonte' => 'Dados insuficientes'];
}

// ─── Funcionários estimados ───────────────────────────────────────────────────
// Combina porte × setor (CNAE 1º dígito).
function cnpj_funcionarios_estimado(array $r): array
{
    $porte  = $r['porte_empresa'] ?? '';
    $cnae   = (string) ($r['cnae_principal'] ?? '');
    $is_mei = !empty($r['is_mei']);
    // Setor pelo primeiro dígito do CNAE
    $primeiroDigito = $cnae !== '' ? (int) $cnae[0] : 0;
    // 1-3 = indústria, 4 = comércio/transporte, 5+ = serviços
    $industria = ($primeiroDigito >= 1 && $primeiroDigito <= 3);

    if ($is_mei) return ['faixa' => '1 funcionário (MEI)', 'fonte' => 'Limite legal MEI'];

    if ($porte === '01') { // ME
        return $industria
            ? ['faixa' => 'Até 19 funcionários', 'fonte' => 'ME (indústria)']
            : ['faixa' => 'Até 9 funcionários',  'fonte' => 'ME (serviços/comércio)'];
    }
    if ($porte === '03') { // EPP
        return $industria
            ? ['faixa' => '20 – 99 funcionários', 'fonte' => 'EPP (indústria)']
            : ['faixa' => '10 – 49 funcionários', 'fonte' => 'EPP (serviços/comércio)'];
    }
    if ($porte === '05') { // Demais
        return $industria
            ? ['faixa' => '100+ funcionários', 'fonte' => 'Grande porte (indústria)']
            : ['faixa' => '50+ funcionários',  'fonte' => 'Grande porte (serviços/comércio)'];
    }
    return ['faixa' => 'Não estimado', 'fonte' => 'Porte não informado'];
}

// ─── Slugify para URLs ────────────────────────────────────────────────────────
function cnpj_slugify(string $s): string
{
    // Remove sufixos societários comuns
    $s = preg_replace('/\b(LTDA|S\/A|SA|S\.A\.|ME|EPP|EIRELI|MEI|EI|LTDA ME|ME LTDA)\b\.?/iu', '', $s);
    $s = preg_replace('/&/u', ' e ', $s);
    // Remove acentos
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

// ─── Domínio inferido do e-mail ───────────────────────────────────────────────
function cnpj_email_domain(?string $email): ?string
{
    if (!$email) return null;
    $email = strtolower(trim($email));
    if (!str_contains($email, '@')) return null;
    $dom = explode('@', $email, 2)[1] ?? '';
    // Ignora domínios genéricos (gmail, hotmail, etc) — não são domínio da empresa
    $genericos = ['gmail.com','hotmail.com','outlook.com','yahoo.com','yahoo.com.br',
                  'live.com','bol.com.br','uol.com.br','ig.com.br','terra.com.br',
                  'icloud.com','msn.com','globomail.com','r7.com'];
    if (in_array($dom, $genericos, true)) return null;
    return $dom ?: null;
}

// ─── Setor pelo CNAE (primeiros 2 dígitos = seção CNAE 2.3) ───────────────────
function cnpj_setor(?string $cnae): array
{
    if (!$cnae) return ['nome' => '—', 'cor' => '#9ca3af', 'icon' => '📦'];
    $d2 = (int) substr($cnae, 0, 2);
    if ($d2 >= 1 && $d2 <= 3)   return ['nome' => 'Agropecuária',     'cor' => '#16a34a', 'icon' => '🌾'];
    if ($d2 >= 5 && $d2 <= 9)   return ['nome' => 'Extração mineral', 'cor' => '#78716c', 'icon' => '⛏'];
    if ($d2 >= 10 && $d2 <= 33) return ['nome' => 'Indústria',        'cor' => '#dc2626', 'icon' => '🏭'];
    if ($d2 == 35)              return ['nome' => 'Energia',          'cor' => '#f59e0b', 'icon' => '⚡'];
    if ($d2 >= 36 && $d2 <= 39) return ['nome' => 'Saneamento',       'cor' => '#0ea5e9', 'icon' => '💧'];
    if ($d2 >= 41 && $d2 <= 43) return ['nome' => 'Construção',       'cor' => '#ea580c', 'icon' => '🏗'];
    if ($d2 >= 45 && $d2 <= 47) return ['nome' => 'Comércio',         'cor' => '#3b82f6', 'icon' => '🛒'];
    if ($d2 >= 49 && $d2 <= 53) return ['nome' => 'Transporte',       'cor' => '#0891b2', 'icon' => '🚚'];
    if ($d2 >= 55 && $d2 <= 56) return ['nome' => 'Alimentação',      'cor' => '#f97316', 'icon' => '🍽'];
    if ($d2 >= 58 && $d2 <= 63) return ['nome' => 'TI e Mídia',       'cor' => '#8b5cf6', 'icon' => '💻'];
    if ($d2 >= 64 && $d2 <= 66) return ['nome' => 'Financeiro',       'cor' => '#059669', 'icon' => '🏦'];
    if ($d2 == 68)              return ['nome' => 'Imobiliário',      'cor' => '#d97706', 'icon' => '🏘'];
    if ($d2 >= 69 && $d2 <= 75) return ['nome' => 'Serviços técnicos','cor' => '#6366f1', 'icon' => '💼'];
    if ($d2 >= 77 && $d2 <= 82) return ['nome' => 'Adm. e suporte',   'cor' => '#7c3aed', 'icon' => '📋'];
    if ($d2 == 84)              return ['nome' => 'Adm. pública',     'cor' => '#1e40af', 'icon' => '🏛'];
    if ($d2 == 85)              return ['nome' => 'Educação',         'cor' => '#0d9488', 'icon' => '🎓'];
    if ($d2 >= 86 && $d2 <= 88) return ['nome' => 'Saúde e social',   'cor' => '#db2777', 'icon' => '⚕'];
    if ($d2 >= 90 && $d2 <= 93) return ['nome' => 'Arte e lazer',     'cor' => '#c026d3', 'icon' => '🎨'];
    if ($d2 >= 94 && $d2 <= 96) return ['nome' => 'Outros serviços',  'cor' => '#64748b', 'icon' => '🔧'];
    if ($d2 == 97)              return ['nome' => 'Doméstico',        'cor' => '#a3a3a3', 'icon' => '🏠'];
    return ['nome' => 'Outros', 'cor' => '#9ca3af', 'icon' => '📦'];
}

// ─── Categoria de idade da empresa ────────────────────────────────────────────
function cnpj_idade_categoria(?string $dataIso): array
{
    $anos = cnpj_anos_atividade($dataIso);
    if ($anos < 1)   return ['label' => '🆕 Nova',          'class' => 'age-novo',    'anos' => $anos];
    if ($anos < 3)   return ['label' => '⚡ Jovem',          'class' => 'age-jovem',   'anos' => $anos];
    if ($anos < 10)  return ['label' => '🌱 Estabelecida',  'class' => 'age-estab',   'anos' => $anos];
    return                    ['label' => '🏛 Tradicional',  'class' => 'age-tradic',  'anos' => $anos];
}

// ─── E-mail é corporativo (não free)? ────────────────────────────────────────
function cnpj_email_is_corporate(?string $email): bool
{
    $dom = cnpj_email_domain($email); // já retorna NULL para genéricos
    return $dom !== null;
}

// ─── Brand inferida do domínio do e-mail ─────────────────────────────────────
// Útil quando razão social vem censurada ou ausente. "contabil@discfone.com.br" → "Discfone"
function cnpj_brand_from_email(?string $email): ?string
{
    $dom = cnpj_email_domain($email);
    if (!$dom) return null;
    $parts = explode('.', $dom);
    $root  = $parts[0] ?? '';
    if (strlen($root) < 2) return null;
    // capitaliza
    return mb_convert_case($root, MB_CASE_TITLE, 'UTF-8');
}

// ─── CEP formatado ───────────────────────────────────────────────────────────
function cnpj_cep_fmt(?string $cep): string
{
    if (!$cep) return '';
    $d = preg_replace('/\D/', '', $cep);
    if (strlen($d) !== 8) return $cep;
    return substr($d, 0, 5) . '-' . substr($d, 5);
}

// ─── DDD bate com a UF? (detecta dados defasados) ─────────────────────────────
function cnpj_ddd_uf_match(?string $ddd, ?string $uf): bool
{
    if (!$ddd || !$uf) return true;
    static $map = [
        'AC' => ['68'], 'AL' => ['82'], 'AM' => ['92','97'], 'AP' => ['96'],
        'BA' => ['71','73','74','75','77'], 'CE' => ['85','88'], 'DF' => ['61'],
        'ES' => ['27','28'], 'GO' => ['62','64'], 'MA' => ['98','99'],
        'MG' => ['31','32','33','34','35','37','38'], 'MS' => ['67'], 'MT' => ['65','66'],
        'PA' => ['91','93','94'], 'PB' => ['83'], 'PE' => ['81','87'],
        'PI' => ['86','89'], 'PR' => ['41','42','43','44','45','46'],
        'RJ' => ['21','22','24'], 'RN' => ['84'], 'RO' => ['69'], 'RR' => ['95'],
        'RS' => ['51','53','54','55'], 'SC' => ['47','48','49'], 'SE' => ['79'],
        'SP' => ['11','12','13','14','15','16','17','18','19'], 'TO' => ['63'],
    ];
    $ddds = $map[strtoupper($uf)] ?? null;
    if (!$ddds) return true;
    return in_array(preg_replace('/\D/', '', $ddd), $ddds, true);
}

// ─── Mensagem WhatsApp template ───────────────────────────────────────────────
function cnpj_wa_template(array $r): string
{
    $nome = $r['nome_fantasia'] ?: $r['razao_social'] ?: '';
    $msg  = "Olá! Sou do Newtonia e ajudamos empresas como a *" . $nome . "* a conquistar mais clientes via WhatsApp. Posso te mostrar como funciona?";
    return rawurlencode($msg);
}

// ─── Links sociais (URLs prováveis, não garantidas) ──────────────────────────
function cnpj_links_externos(array $r): array
{
    $razao    = $r['razao_social']  ?? '';
    $fantasia = $r['nome_fantasia'] ?? '';
    $cidade   = $r['municipio_nome'] ?? $r['municipio'] ?? '';
    $uf       = $r['uf'] ?? '';

    $slugRazao    = cnpj_slugify($razao);
    $slugFantasia = $fantasia ? cnpj_slugify($fantasia) : '';
    $slugUsado    = $slugFantasia ?: $slugRazao;

    $domain = cnpj_email_domain($r['email'] ?? null);

    $googleQuery = urlencode(trim(($fantasia ?: $razao) . ' ' . $cidade . ' ' . $uf));

    // Search específicos
    $linkedin = $slugRazao
        ? 'https://www.linkedin.com/search/results/companies/?keywords=' . urlencode($razao)
        : null;

    // WhatsApp com template de mensagem
    $tel_digits = '';
    if (!empty($r['ddd1']) && !empty($r['telefone1'])) {
        $tel_digits = preg_replace('/\D/', '', '55' . $r['ddd1'] . $r['telefone1']);
    }
    $wa_template = cnpj_wa_template($r);
    $wa = $tel_digits ? "https://wa.me/{$tel_digits}?text={$wa_template}" : null;

    // Email mailto com template
    $mailto = null;
    if (!empty($r['email'])) {
        $subj = rawurlencode('Parceria comercial — ' . ($fantasia ?: $razao));
        $body = rawurlencode("Olá, tudo bem?\n\nSou do Newtonia e gostaria de apresentar uma proposta de parceria que pode ajudar a " . ($fantasia ?: $razao) . " a conquistar mais clientes.\n\nPodemos conversar?");
        $mailto = 'mailto:' . $r['email'] . '?subject=' . $subj . '&body=' . $body;
    }

    return [
        'google'    => 'https://www.google.com/search?q=' . $googleQuery,
        'maps'      => 'https://www.google.com/maps/search/' . $googleQuery,
        'linkedin'  => $linkedin,
        'instagram' => $slugUsado ? 'https://www.instagram.com/' . $slugUsado : null,
        'site'      => $domain ? 'https://' . $domain : null,
        'site_dom'  => $domain,
        'whatsapp'  => $wa,
        'mailto'    => $mailto,
    ];
}
