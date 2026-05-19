<?php
/**
 * NEWTONIA — Autenticação multi-tenant
 *
 * Modelo:
 *  - Usuário tem email + senha global
 *  - Usuário pode estar em N tenants (tabela tenant_users)
 *  - Super admin (is_super_admin=1) acessa /admin/ e pode "logar-as" em qualquer tenant
 *  - Tenant user (regular) escolhe qual tenant entrar se tiver mais de um
 */

function auth_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => APP_ENV === 'production',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function auth_login(string $email, string $password): array {
    auth_start_session();

    $u = db_one('SELECT * FROM users WHERE email = ? LIMIT 1', [strtolower(trim($email))]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return ['ok' => false, 'error' => 'Credenciais inválidas.'];
    }

    $_SESSION['user_id']         = (int) $u['id'];
    $_SESSION['user_email']      = $u['email'];
    $_SESSION['user_name']       = $u['name'];
    $_SESSION['is_super_admin']  = ((int) $u['is_super_admin']) === 1;

    db_q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$u['id']]);

    // Carrega tenants ativos do usuário
    $tenants = db_all(
        'SELECT t.id, t.slug, t.name, t.brand_name, t.brand_color, tu.role
         FROM tenants t
         JOIN tenant_users tu ON tu.tenant_id = t.id
         WHERE tu.user_id = ? AND t.status IN (?, ?)
         ORDER BY t.name',
        [$u['id'], 'active', 'pending']
    );
    $_SESSION['user_tenants'] = $tenants;

    audit_log('user.login');

    // Decide redirect
    if ($_SESSION['is_super_admin']) {
        return ['ok' => true, 'redirect' => 'admin/'];
    }
    if (count($tenants) === 0) {
        return ['ok' => false, 'error' => 'Usuário sem tenants ativos. Contate o suporte.'];
    }
    if (count($tenants) === 1) {
        $_SESSION['tenant_id'] = (int) $tenants[0]['id'];
        return ['ok' => true, 'redirect' => 'app/'];
    }
    return ['ok' => true, 'redirect' => 'select-tenant.php'];
}

function auth_logout(): void {
    auth_start_session();
    if (!empty($_SESSION['user_id'])) audit_log('user.logout');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function auth_user_id(): ?int {
    auth_start_session();
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function auth_user_email(): ?string {
    auth_start_session();
    return $_SESSION['user_email'] ?? null;
}

function auth_user_name(): ?string {
    auth_start_session();
    return $_SESSION['user_name'] ?? null;
}

function auth_is_super(): bool {
    auth_start_session();
    return !empty($_SESSION['is_super_admin']);
}

function auth_user_tenants(): array {
    auth_start_session();
    return $_SESSION['user_tenants'] ?? [];
}
