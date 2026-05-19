<?php
/**
 * NEWTONIA — Utilitários
 */

function audit_log(string $action, ?string $targetType = null, $targetId = null, array $meta = []): void {
    try {
        db_insert('audit_log', [
            'tenant_id'   => $_SESSION['tenant_id'] ?? null,
            'user_id'     => $_SESSION['user_id']   ?? null,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId !== null ? (string) $targetId : null,
            'meta'        => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {
        // log não derruba a aplicação
        error_log('[audit_log] ' . $e->getMessage());
    }
}

function csrf_token(): string {
    auth_start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool {
    auth_start_session();
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

function require_csrf(): void {
    if (!csrf_check()) {
        http_response_code(419);
        die('CSRF token inválido. Recarregue a página.');
    }
}

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function slugify(string $text): string {
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $text));
    return trim($text, '-');
}

function brl_cents(int $cents): string {
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function flash_set(string $type, string $message): void {
    auth_start_session();
    $_SESSION['_flash'] = ['type' => $type, 'msg' => $message];
}

function flash_get(): ?array {
    auth_start_session();
    if (empty($_SESSION['_flash'])) return null;
    $f = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $f;
}

function flash_render(): string {
    $f = flash_get();
    if (!$f) return '';
    $color = $f['type'] === 'error' ? '#dc2626' : ($f['type'] === 'success' ? '#16a34a' : '#0c0c0d');
    $bg    = $f['type'] === 'error' ? '#fee2e2' : ($f['type'] === 'success' ? '#dcfce7' : '#f5f3ef');
    return '<div class="flash" style="background:' . $bg . ';color:' . $color . ';padding:12px 16px;border-radius:10px;margin-bottom:1rem;font-size:.85rem;font-weight:600;">' . e($f['msg']) . '</div>';
}
