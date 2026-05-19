<?php
/**
 * Newton CNPJ — Sinais (PASCAL / TESLA / ATLAS / PRISMA).
 * Detecção a partir de fontes públicas e gratuitas.
 *
 * Cache em cnpj_enrichment_cache (source='signals').
 */

// ── HTTP genérico ─────────────────────────────────────────────────────────────
function cnpj_sig_http(string $url, int $timeout = 6): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NewtonAI/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_MAXREDIRS      => 4,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return ['body' => $body ?: '', 'code' => $http, 'final_url' => $final];
}

// ── 1) SITE SCRAPE — detecta stack, chat, plataforma, páginas de maturidade ──
function cnpj_sig_site(string $domain): array
{
    $domain = strtolower(trim($domain, '/'));
    if (!$domain) return ['ok' => false, 'reason' => 'sem_dominio'];

    // Tenta https primeiro, depois http
    $candidates = ["https://{$domain}", "https://www.{$domain}", "http://{$domain}"];
    $r = null;
    foreach ($candidates as $u) {
        $r = cnpj_sig_http($u, 7);
        if ($r['code'] >= 200 && $r['code'] < 400 && strlen($r['body']) > 200) break;
    }
    if (!$r || $r['code'] === 0 || empty($r['body'])) {
        return ['ok' => false, 'reason' => 'site_inacessivel'];
    }

    $html = $r['body'];
    $low  = strtolower($html);
    $final = $r['final_url'];

    // ── Chat widgets → TESLA (ausência = oportunidade) ────────────────────────
    $chats = [];
    $widget_map = [
        'Zendesk'   => '/zendesk\.com|zopim|zdassets/i',
        'Intercom'  => '/intercom\.io|intercom-widget|intercomcdn/i',
        'Tawk.to'   => '/tawk\.to|embed\.tawk/i',
        'JivoChat'  => '/jivosite|jivochat/i',
        'Blip'      => '/take\.net|blip\.ai/i',
        'Crisp'     => '/crisp\.chat/i',
        'HubSpot Chat' => '/hs-scripts|hubspot.*messenger/i',
        'RD Conversas' => '/rdstation.*conv|conversational(?:bot)?/i',
        'Drift'     => '/drift\.com|js\.driftt/i',
        'Olark'     => '/olark\.com/i',
        'LiveChat'  => '/livechatinc\.com/i',
    ];
    foreach ($widget_map as $name => $rx) {
        if (preg_match($rx, $html)) $chats[] = $name;
    }

    // ── Stack CRM / Marketing → PASCAL (ausência + porte = oportunidade) ─────
    $stack = [];
    $stack_map = [
        'HubSpot'         => '/hs-scripts|hsforms|hubspot\.com\/cs|js\.hsforms\.net/i',
        'RD Station'      => '/rdstation\.com\.br|rdmail|rdstation_pop/i',
        'Pipedrive'       => '/pipedrive\.com|leadbooster/i',
        'Salesforce'      => '/salesforce\.com|sfdc-static|pardot/i',
        'Mailchimp'       => '/mailchimp\.com|mc\.us\d+\.list-manage|mailchimp-?form/i',
        'ActiveCampaign'  => '/activecampaign\.com|activehosted/i',
        'Google Analytics 4' => '/gtag\.js\?id=g-|googletagmanager\.com\/gtag\/js\?id=g-/i',
        'Universal GA'    => '/google-analytics\.com\/analytics\.js/i',
        'Google Tag Manager' => '/googletagmanager\.com\/gtm\.js/i',
        'Meta Pixel'      => '/connect\.facebook\.net.*fbevents|fbq\([\'"]init/i',
        'TikTok Pixel'    => '/analytics\.tiktok\.com|ttq\.load/i',
    ];
    foreach ($stack_map as $name => $rx) {
        if (preg_match($rx, $html)) $stack[] = $name;
    }

    // ── Plataforma amadora → PRISMA (presença = sinal forte) ──────────────────
    $platforms = [];
    $plat_map = [
        'Wix (free)'        => '/wix\.com|wixstatic\.com|w-rich-text-link/i',
        'WordPress.com (free)' => '/wp\.com\/wp-content|wordpress\.com\/wp-content/i',
        'Webflow.io (free)' => '/\.webflow\.io|webflow-style-input/i',
        'Webnode'           => '/webnode\.com|webnode\.com\.br/i',
        'Google Sites'      => '/sites\.google\.com/i',
        'Squarespace'       => '/squarespace\.com|squarespace-cdn/i',
        'Lojas Loja Integrada' => '/lojaintegrada\.com\.br/i',
    ];
    foreach ($plat_map as $name => $rx) {
        if (preg_match($rx, $html)) $platforms[] = $name;
    }
    // Detecta WordPress profissional vs free
    $is_wp_self = (bool) preg_match('/wp-content\/themes|wp-content\/plugins/i', $html)
                && !preg_match('/wordpress\.com\/wp-content/i', $html);

    // ── Páginas de maturidade ─────────────────────────────────────────────────
    $maturity = [
        'contato'      => (bool) preg_match('/href=[\'"][^\'"]*\/(contato|contact|fale-conosco)/i', $html),
        'privacidade'  => (bool) preg_match('/href=[\'"][^\'"]*\/(privacidade|politica|privacy|lgpd)/i', $html),
        'sobre'        => (bool) preg_match('/href=[\'"][^\'"]*\/(sobre|about|quem-somos|empresa)/i', $html),
        'termos'       => (bool) preg_match('/href=[\'"][^\'"]*\/(termos|terms)/i', $html),
    ];
    $maturity_score = array_sum($maturity); // 0-4

    // Tem certificado SSL? (https acessível com sucesso)
    $has_https = strpos($final, 'https://') === 0;

    return [
        'ok'           => true,
        'final_url'    => $final,
        'has_https'    => $has_https,
        'chats'        => $chats,
        'stack'        => $stack,
        'platforms'    => $platforms,
        'wp_self_hosted' => $is_wp_self,
        'maturity'     => $maturity,
        'maturity_score' => $maturity_score,
        'html_bytes'   => strlen($html),
    ];
}

// ── 2) DNS / MX via Google DNS-over-HTTPS ────────────────────────────────────
function cnpj_sig_dns(string $domain): array
{
    $domain = strtolower(trim($domain, '/'));
    if (!$domain) return ['ok' => false];

    $r = cnpj_sig_http("https://dns.google/resolve?name={$domain}&type=MX", 5);
    if (!$r['body']) return ['ok' => false];
    $j = json_decode($r['body'], true);
    $answers = $j['Answer'] ?? [];

    $records = [];
    foreach ($answers as $a) {
        $records[] = trim($a['data'] ?? '', '.');
    }

    // Detecta provedor
    $provider = '—';
    $is_pro   = false;
    foreach ($records as $rec) {
        $rl = strtolower($rec);
        if (str_contains($rl, 'google.com') || str_contains($rl, 'googlemail.com')) {
            $provider = 'Google Workspace'; $is_pro = true; break;
        }
        if (str_contains($rl, 'outlook.com') || str_contains($rl, 'office365') || str_contains($rl, 'microsoft.com')) {
            $provider = 'Microsoft 365'; $is_pro = true; break;
        }
        if (str_contains($rl, 'locaweb'))    { $provider = 'Locaweb'; break; }
        if (str_contains($rl, 'uolhost') || str_contains($rl, 'uol.com.br')) { $provider = 'UOL Host'; break; }
        if (str_contains($rl, 'hostgator')) { $provider = 'HostGator'; break; }
        if (str_contains($rl, 'kinghost'))  { $provider = 'KingHost'; break; }
        if (str_contains($rl, 'zoho.com'))  { $provider = 'Zoho Mail'; $is_pro = true; break; }
    }
    if ($provider === '—' && !empty($records)) $provider = $records[0];

    return [
        'ok'         => true,
        'has_mx'     => !empty($records),
        'records'    => $records,
        'provider'   => $provider,
        'is_pro_email' => $is_pro,
    ];
}

// ── 3) Cross-check e-mail × domínio ──────────────────────────────────────────
function cnpj_sig_email_domain_match(?string $email, ?string $site_domain): array
{
    $email_domain = cnpj_email_domain($email); // null se for gmail/hotmail genérico
    if (!$email_domain) {
        return ['email_genérico' => true, 'match' => false, 'observação' => 'E-mail genérico (gmail/hotmail/etc)'];
    }
    if (!$site_domain) {
        return ['email_genérico' => false, 'match' => null, 'observação' => 'Sem domínio do site para comparar'];
    }
    $email_root = preg_replace('/^www\./', '', strtolower($email_domain));
    $site_root  = preg_replace('/^www\./', '', strtolower($site_domain));
    $match = ($email_root === $site_root);
    return [
        'email_genérico' => false,
        'match'      => $match,
        'observação' => $match ? 'E-mail e site no mesmo domínio' : "E-mail @{$email_root} ≠ site @{$site_root}",
    ];
}

// ── 4) WhatsApp existência (presença simples) ────────────────────────────────
function cnpj_sig_whatsapp(?string $ddd, ?string $tel): array
{
    if (!$ddd || !$tel) return ['url' => null];
    $clean = preg_replace('/\D/', '', "55{$ddd}{$tel}");
    if (strlen($clean) < 12) return ['url' => null];
    return [
        'url' => "https://wa.me/{$clean}",
        // Detecção Business vs pessoal exige browser headless — fica pra fase 2.
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// AGREGADOR — chama tudo + computa flags PASCAL/TESLA/ATLAS/PRISMA + cacheia
// ─────────────────────────────────────────────────────────────────────────────
function cnpj_signals_run(string $cnpj14, array $base): array
{
    $cached = cnpj_enrich_get($cnpj14, 'signals');
    if ($cached !== null && empty($_GET['refresh'])) return $cached;

    $razao    = $base['razao_social']  ?? '';
    $fantasia = $base['nome_fantasia'] ?? '';
    $porte    = $base['porte_empresa'] ?? '';

    // Determina o domínio do site: prioridade pra Google Places (já cacheado),
    // depois pro domínio do e-mail corporativo.
    $site_domain = null;
    $places = cnpj_enrich_get($cnpj14, 'gplaces');
    if ($places && !empty($places['website'])) {
        $u = parse_url($places['website']);
        $site_domain = strtolower($u['host'] ?? '');
        $site_domain = preg_replace('/^www\./', '', $site_domain);
    }
    if (!$site_domain) {
        $site_domain = cnpj_email_domain($base['email'] ?? null);
    }

    // 1) Site scrape
    $site = $site_domain ? cnpj_sig_site($site_domain) : ['ok' => false, 'reason' => 'sem_dominio'];

    // 2) DNS
    $dns  = $site_domain ? cnpj_sig_dns($site_domain) : ['ok' => false];

    // 3) Email × domínio
    $ed   = cnpj_sig_email_domain_match($base['email'] ?? null, $site_domain);

    // 4) WhatsApp link
    $wa   = cnpj_sig_whatsapp($base['ddd1'] ?? null, $base['telefone1'] ?? null);

    // ─── COMPUTA PRODUTOS ──────────────────────────────────────────────────
    $products = [
        'PASCAL' => ['fit' => false, 'reasons' => []],
        'TESLA'  => ['fit' => false, 'reasons' => []],
        'ATLAS'  => ['fit' => false, 'reasons' => []],
        'PRISMA' => ['fit' => false, 'reasons' => []],
    ];

    // PASCAL — CRM/organização: porte > ME E sem ferramentas comerciais detectadas
    $crm_tools = array_filter(($site['stack'] ?? []), fn($s) => in_array($s, ['HubSpot','RD Station','Pipedrive','Salesforce','ActiveCampaign'], true));
    if (in_array($porte, ['03','05'], true) && empty($crm_tools)) {
        $products['PASCAL']['fit'] = true;
        $products['PASCAL']['reasons'][] = "Porte " . cnpj_porte_label($porte) . " sem CRM detectado";
    }
    if (!empty($site['ok']) && !empty($site['maturity']) && $site['maturity_score'] <= 1) {
        $products['PASCAL']['fit'] = true;
        $products['PASCAL']['reasons'][] = "Site sem páginas de maturidade (contato/privacidade)";
    }

    // TESLA — atendimento: sem chat widget detectado
    if (!empty($site['ok']) && empty($site['chats'])) {
        $products['TESLA']['fit'] = true;
        $products['TESLA']['reasons'][] = "Nenhum chat/widget no site";
    }

    // ATLAS — infraestrutura: e-mail genérico, sem MX pro, sem HTTPS
    if (!empty($ed['email_genérico'])) {
        $products['ATLAS']['fit'] = true;
        $products['ATLAS']['reasons'][] = "E-mail genérico (gmail/hotmail) em vez de @empresa";
    }
    if (!empty($dns['ok']) && empty($dns['is_pro_email']) && !empty($dns['has_mx'])) {
        $products['ATLAS']['fit'] = true;
        $products['ATLAS']['reasons'][] = "E-mail não é Google Workspace/M365 (provedor: {$dns['provider']})";
    }
    if (!empty($site['ok']) && !$site['has_https']) {
        $products['ATLAS']['fit'] = true;
        $products['ATLAS']['reasons'][] = "Site sem HTTPS";
    }

    // PRISMA — marca/UX: plataforma amadora, sem site, Google Places ruim
    if (!empty($site['platforms'])) {
        $products['PRISMA']['fit'] = true;
        $products['PRISMA']['reasons'][] = "Plataforma amadora: " . implode(', ', $site['platforms']);
    }
    if (empty($site['ok'])) {
        $products['PRISMA']['fit'] = true;
        $products['PRISMA']['reasons'][] = "Site não responde ou inexistente";
    }
    if ($places && !empty($places['_found'])) {
        $rating = (float)($places['rating'] ?? 0);
        $reviews = (int)($places['reviews_total'] ?? 0);
        if ($rating > 0 && $rating < 4.0 && $reviews < 30) {
            $products['PRISMA']['fit'] = true;
            $products['PRISMA']['reasons'][] = "Google: {$rating}★ em {$reviews} reviews (baixo)";
        }
    }

    $result = [
        'site'     => $site,
        'dns'      => $dns,
        'email_x_dominio' => $ed,
        'whatsapp' => $wa,
        'products' => $products,
        'site_domain' => $site_domain,
        '_analyzed_at' => date('Y-m-d H:i:s'),
    ];

    cnpj_enrich_save($cnpj14, 'signals', $result, 14); // cache 14 dias
    return $result;
}
