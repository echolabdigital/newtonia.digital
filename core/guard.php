<?php
/**
 * NEWTONIA — Guards de acesso
 *
 * Toda página deve chamar uma dessas no topo:
 *   require_login()        — qualquer usuário logado
 *   require_super_admin()  — só equipe Newtonia (/admin/)
 *   require_tenant()       — usuário logado COM tenant ativo (/app/)
 */

function require_login(): void {
    auth_start_session();
    if (!auth_user_id()) {
        $back = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: /login.php?redirect=' . $back);
        exit;
    }
}

function require_super_admin(): void {
    require_login();
    if (!auth_is_super()) {
        http_response_code(403);
        _hermes_access_denied();
    }
}

function require_tenant(): array {
    require_login();
    // Super admin sem tenant selecionado → volta pro painel admin
    if (auth_is_super() && !tenant_current()) {
        header('Location: /admin/');
        exit;
    }
    $t = tenant_current();
    if (!$t) {
        header('Location: /select-tenant.php');
        exit;
    }
    if ($t['status'] === 'suspended') {
        _hermes_access_blocked('Conta suspensa', 'Sua conta está suspensa. Verifique sua cobrança em <a href="/app/billing.php">Plano e cobranças</a> ou entre em contato: <strong>contato@newtonia.digital</strong>');
    }
    if ($t['status'] === 'cancelled') {
        _hermes_access_blocked('Conta cancelada', 'Esta conta foi cancelada. Pra reativar, entre em contato: <strong>contato@newtonia.digital</strong>');
    }
    return $t;
}

// ─── Páginas de erro Newton IA ───────────────────────────────────────────────
function _hermes_access_denied(): void {
    $email = function_exists('auth_user_email') ? auth_user_email() : '';
    _hermes_error_page(
        '🚫 Acesso restrito',
        'Esta área é restrita à equipe <strong>echo_lab</strong> (super-admin).',
        '<p style="font-size:.85rem;color:#6b7280;margin-top:8px">Você está logado como <strong>' . htmlspecialchars($email) . '</strong>. Para acessar o painel admin, saia e entre com uma conta de super-admin.</p>',
        [
            ['label' => '← Voltar pro app', 'href' => '/app/', 'style' => 'ghost'],
            ['label' => '🚪 Sair e trocar de conta', 'href' => '/logout.php', 'style' => 'primary'],
        ]
    );
}

function _hermes_access_blocked(string $title, string $message): void {
    _hermes_error_page('⚠ ' . $title, $message, '', [
        ['label' => '🚪 Sair', 'href' => '/logout.php', 'style' => 'primary'],
    ]);
}

function _hermes_error_page(string $title, string $message, string $extra, array $actions): void {
    echo '<!DOCTYPE html><html lang="pt-BR"><head>';
    echo '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<link rel="icon" type="image/svg+xml" href="/favicon.svg">';
    echo '<title>' . htmlspecialchars(strip_tags($title)) . ' — Newton IA</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@500;600&display=swap" rel="stylesheet">';
    echo '<style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:Geist,system-ui,sans-serif;background:#f6f4ef;color:#18181b;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;-webkit-font-smoothing:antialiased;letter-spacing:-.01em}
        .wrap{max-width:480px;width:100%}
        .brand{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:22px;text-decoration:none;color:inherit}
        .brand-icon{width:42px;height:42px;border-radius:10px;background:#0ea5e9;color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(14,165,233,.25)}
        .brand-text{font-family:Geist Mono,monospace;font-weight:700;font-size:1.1rem;line-height:1.1}
        .brand-text .ia{color:#0ea5e9;font-size:.76em}
        .card{background:#fff;border:1px solid #e7e5e0;border-radius:14px;padding:32px;box-shadow:0 1px 6px rgba(0,0,0,.04);text-align:center}
        .card h1{font-size:1.3rem;font-weight:700;margin-bottom:10px;color:#18181b}
        .card p{font-size:.92rem;color:#3a3a40;line-height:1.55}
        .card p strong{color:#18181b;font-weight:600}
        .card a:not(.btn){color:#0ea5e9;text-decoration:none;font-weight:600}
        .actions{display:flex;gap:8px;justify-content:center;margin-top:22px;flex-wrap:wrap}
        .btn{padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.88rem;font-family:inherit;transition:all .15s;display:inline-flex;align-items:center;gap:6px}
        .btn.primary{background:#0ea5e9;color:#fff}
        .btn.primary:hover{background:#0284c7}
        .btn.ghost{background:#fff;color:#18181b;border:1px solid #e7e5e0}
        .btn.ghost:hover{background:#fafaf7;border-color:#8b8a93}
        .foot{text-align:center;margin-top:18px;font-size:.72rem;color:#8b8a93}
    </style></head><body><div class="wrap">';
    echo '<a class="brand" href="/"><div class="brand-icon"><svg viewBox="0 0 100 100" width="22" height="22" xmlns="http://www.w3.org/2000/svg"><path d="M22 20 L58 50 L22 80" stroke="#fff" stroke-width="14" stroke-linecap="round" stroke-linejoin="round" fill="none"/><rect x="62" y="68" width="24" height="11" rx="5.5" fill="#BE123C"/></svg></div><div class="brand-text">Newton<span class="ia"> IA</span></div></a>';
    echo '<div class="card"><h1>' . $title . '</h1><p>' . $message . '</p>' . $extra . '<div class="actions">';
    foreach ($actions as $a) {
        echo '<a href="' . htmlspecialchars($a['href']) . '" class="btn ' . htmlspecialchars($a['style']) . '">' . htmlspecialchars($a['label']) . '</a>';
    }
    echo '</div></div><div class="foot">newtonia.digital · by echo_lab</div></div></body></html>';
    exit;
}
