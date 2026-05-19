<?php
/**
 * HERMES.b2b — Logout
 * Limpa a sessão e redireciona pra login.
 */
require_once __DIR__ . '/config.php';

if (function_exists('auth_logout')) {
    auth_logout();
}

header('Location: /login.php');
exit;
