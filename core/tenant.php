<?php
/**
 * NEWTONIA — Contexto de tenant
 *
 * REGRA DE OURO: toda página/API do /app/ chama require_tenant() (em guard.php),
 * que garante que tenant_current() retorna um tenant válido.
 * Toda query SQL DOS MÓDULOS deve filtrar por tenant_id().
 */

function tenant_set(int $tenantId): bool {
    auth_start_session();
    $uid = auth_user_id();
    if (!$uid) return false;

    if (auth_is_super()) {
        // Super admin pode entrar em qualquer tenant (suporte/debug)
        $t = db_one('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
    } else {
        $t = db_one(
            'SELECT t.* FROM tenants t
             JOIN tenant_users tu ON tu.tenant_id = t.id
             WHERE t.id = ? AND tu.user_id = ? AND t.status IN (?, ?)
             LIMIT 1',
            [$tenantId, $uid, 'active', 'pending']
        );
    }
    if (!$t) return false;

    $_SESSION['tenant_id'] = (int) $t['id'];
    return true;
}

function tenant_clear(): void {
    auth_start_session();
    unset($_SESSION['tenant_id']);
}

function tenant_current(): ?array {
    auth_start_session();
    if (empty($_SESSION['tenant_id'])) return null;

    static $cache = null;
    if ($cache !== null && (int) $cache['id'] === (int) $_SESSION['tenant_id']) return $cache;

    $t = db_one('SELECT * FROM tenants WHERE id = ?', [$_SESSION['tenant_id']]);
    if (!$t) {
        unset($_SESSION['tenant_id']);
        return null;
    }
    $cache = $t;
    return $t;
}

function tenant_id(): ?int {
    $t = tenant_current();
    return $t ? (int) $t['id'] : null;
}

function tenant_role(): ?string {
    $t = tenant_current();
    $uid = auth_user_id();
    if (!$t || !$uid) return null;
    if (auth_is_super()) return 'owner';
    $r = db_val('SELECT role FROM tenant_users WHERE tenant_id = ? AND user_id = ?', [$t['id'], $uid]);
    return $r ?: null;
}

function tenant_brand(): array {
    $t = tenant_current();
    if (!$t) {
        return ['name' => APP_NAME, 'color' => '#be123c', 'logo' => null];
    }
    return [
        'name'  => $t['brand_name'] ?: $t['name'],
        'color' => $t['brand_color'] ?: '#be123c',
        'logo'  => $t['brand_logo_url'],
    ];
}

/**
 * Carrega config Z-API do tenant atual.
 * Usado por Disparador, Worker, Webhook (Fase 3).
 */
function tenant_zapi(): ?array {
    $t = tenant_current();
    if (!$t || empty($t['zapi_instance'])) return null;
    return [
        'base'         => ZAPI_BASE,
        'instance'     => $t['zapi_instance'],
        'token'        => $t['zapi_token'],
        'client_token' => $t['zapi_client_token'],
        'phone'        => $t['zapi_phone'],
        'status'       => $t['zapi_status'],
    ];
}

/**
 * Atribui uma instância disponível do pool a um tenant.
 * Retorna true em sucesso, false se pool vazio.
 */
function tenant_assign_zapi(int $tenantId): bool {
    db()->beginTransaction();
    try {
        $pool = db_one("SELECT * FROM zapi_pool WHERE status = 'available' ORDER BY id LIMIT 1 FOR UPDATE");
        if (!$pool) { db()->rollBack(); return false; }

        db_update('zapi_pool', [
            'status'      => 'assigned',
            'assigned_to' => $tenantId,
            'assigned_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $pool['id']]);

        db_update('tenants', [
            'zapi_instance'     => $pool['instance_id'],
            'zapi_token'        => $pool['token'],
            'zapi_client_token' => $pool['client_token'],
            'zapi_status'       => 'disconnected',
        ], 'id = :id', ['id' => $tenantId]);

        db()->commit();
        audit_log('zapi.assigned', 'tenant', $tenantId, ['pool_id' => $pool['id']]);
        return true;
    } catch (\Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

/**
 * Devolve a instância do tenant pro pool (ao cancelar/suspender).
 */
function tenant_release_zapi(int $tenantId): void {
    $t = db_one('SELECT zapi_instance FROM tenants WHERE id = ?', [$tenantId]);
    if (!$t || empty($t['zapi_instance'])) return;

    db()->beginTransaction();
    try {
        db_update('zapi_pool', [
            'status'      => 'available',
            'assigned_to' => null,
            'assigned_at' => null,
        ], 'instance_id = :iid', ['iid' => $t['zapi_instance']]);

        db_update('tenants', [
            'zapi_instance'     => null,
            'zapi_token'        => null,
            'zapi_client_token' => null,
            'zapi_phone'        => null,
            'zapi_status'       => 'disconnected',
        ], 'id = :id', ['id' => $tenantId]);

        db()->commit();
        audit_log('zapi.released', 'tenant', $tenantId);
    } catch (\Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}
