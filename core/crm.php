<?php
/**
 * Newton CRM — helpers básicos
 */

// Auto-cria as tabelas na 1ª chamada (idempotente)
function crm_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    try {
        db_q("CREATE TABLE IF NOT EXISTS crm_columns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            name VARCHAR(60) NOT NULL,
            color VARCHAR(20) DEFAULT '#6366f1',
            position INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant_pos (tenant_id, position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_q("CREATE TABLE IF NOT EXISTS crm_cards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            column_id INT NOT NULL,
            cnpj VARCHAR(14) NULL,
            razao_social VARCHAR(255),
            nome_fantasia VARCHAR(255),
            telefone VARCHAR(20),
            email VARCHAR(120),
            cidade_uf VARCHAR(80),
            cnae VARCHAR(20),
            capital DECIMAL(15,2) NULL,
            score INT DEFAULT 0,
            notes TEXT,
            position INT DEFAULT 0,
            last_action TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant_col (tenant_id, column_id, position),
            INDEX idx_cnpj (cnpj)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_q("CREATE TABLE IF NOT EXISTS crm_card_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            card_id INT NOT NULL,
            user_id INT NULL,
            action VARCHAR(40) NOT NULL,
            detail TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_card_time (card_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // === v2: Tags coloridas ===
        db_q("CREATE TABLE IF NOT EXISTS crm_tags (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            name VARCHAR(40) NOT NULL,
            color VARCHAR(20) DEFAULT '#10b981',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_tenant_name (tenant_id, name),
            INDEX idx_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db_q("CREATE TABLE IF NOT EXISTS crm_card_tags (
            card_id INT NOT NULL,
            tag_id INT NOT NULL,
            PRIMARY KEY (card_id, tag_id),
            INDEX idx_tag (tag_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // === v2: Comentários por card (com autor + timestamp) ===
        db_q("CREATE TABLE IF NOT EXISTS crm_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            card_id INT NOT NULL,
            user_id INT NOT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_card_time (card_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // === v2: Responsável (assigned_user_id em crm_cards) ===
        // ALTER TABLE não tem IF NOT EXISTS confiável no MySQL — try/catch para idempotência
        try { db_q("ALTER TABLE crm_cards ADD COLUMN assigned_user_id INT NULL, ADD INDEX idx_assigned (assigned_user_id)"); } catch (\Throwable $e) {}
        // v3: produto + prazo (agenda)
        try { db_q("ALTER TABLE crm_cards ADD COLUMN product_name VARCHAR(120) NULL"); } catch (\Throwable $e) {}
        try { db_q("ALTER TABLE crm_cards ADD COLUMN due_date DATE NULL"); } catch (\Throwable $e) {}
        try { db_q("ALTER TABLE crm_cards ADD INDEX idx_due (due_date)"); } catch (\Throwable $e) {}

        db_q("CREATE TABLE IF NOT EXISTS wa_instances (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            name VARCHAR(60),
            provider VARCHAR(20) DEFAULT 'zapi',
            instance_id VARCHAR(80),
            token VARCHAR(120),
            phone VARCHAR(20),
            status VARCHAR(20) DEFAULT 'disconnected',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_q("CREATE TABLE IF NOT EXISTS wa_campaigns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            instance_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            template TEXT NOT NULL,
            list_id INT NULL,
            delay_min_s INT DEFAULT 30,
            delay_max_s INT DEFAULT 90,
            daily_limit INT DEFAULT 100,
            status VARCHAR(20) DEFAULT 'draft',
            total_targets INT DEFAULT 0,
            sent_count INT DEFAULT 0,
            failed_count INT DEFAULT 0,
            started_at TIMESTAMP NULL,
            finished_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_q("CREATE TABLE IF NOT EXISTS wa_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            campaign_id INT NOT NULL,
            cnpj VARCHAR(14) NULL,
            phone VARCHAR(20) NOT NULL,
            razao_social VARCHAR(255),
            message TEXT,
            status VARCHAR(20) DEFAULT 'pending',
            provider_msg_id VARCHAR(80),
            error VARCHAR(255),
            scheduled_at TIMESTAMP NULL,
            sent_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_campaign_status (campaign_id, status),
            INDEX idx_phone (phone),
            INDEX idx_scheduled (scheduled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $done = true;
    } catch (\Throwable $e) {
        // não trava o app
    }
}

function crm_ensure_columns(int $tenantId): array
{
    crm_ensure_schema();
    $cols = db_all('SELECT * FROM crm_columns WHERE tenant_id = ? ORDER BY position', [$tenantId]);
    if (!empty($cols)) return $cols;

    // Cria colunas padrão na 1ª vez
    $defaults = [
        ['Novo',         '#94a3b8', 0],
        ['Contatado',    '#6366f1', 1],
        ['Qualificado',  '#f59e0b', 2],
        ['Fechado',      '#22c55e', 3],
    ];
    foreach ($defaults as [$name, $color, $pos]) {
        db_insert('crm_columns', [
            'tenant_id' => $tenantId,
            'name'      => $name,
            'color'     => $color,
            'position'  => $pos,
        ]);
    }
    return db_all('SELECT * FROM crm_columns WHERE tenant_id = ? ORDER BY position', [$tenantId]);
}

function crm_cards_by_column(int $tenantId, int $columnId): array
{
    return db_all(
        'SELECT c.*, u.name AS assigned_user_name
         FROM crm_cards c
         LEFT JOIN users u ON u.id = c.assigned_user_id
         WHERE c.tenant_id = ? AND c.column_id = ?
         ORDER BY c.position, c.created_at DESC',
        [$tenantId, $columnId]
    );
}

function crm_add_card(int $tenantId, int $columnId, array $data): int
{
    return (int) db_insert('crm_cards', [
        'tenant_id'     => $tenantId,
        'column_id'     => $columnId,
        'cnpj'          => $data['cnpj']          ?? null,
        'razao_social'  => $data['razao_social']  ?? null,
        'nome_fantasia' => $data['nome_fantasia'] ?? null,
        'telefone'      => $data['telefone']      ?? null,
        'email'         => $data['email']         ?? null,
        'cidade_uf'     => $data['cidade_uf']     ?? null,
        'cnae'          => $data['cnae']          ?? null,
        'capital'       => $data['capital']       ?? null,
        'score'         => (int) ($data['score']   ?? 0),
        'notes'         => $data['notes']         ?? null,
    ]);
}

function crm_move_card(int $cardId, int $newColumnId, int $position = 0): void
{
    db_q('UPDATE crm_cards SET column_id = ?, position = ?, last_action = NOW() WHERE id = ?',
        [$newColumnId, $position, $cardId]);
    crm_log_history($cardId, 'moved', "Movido para coluna #$newColumnId");
}

function crm_log_history(int $cardId, string $action, string $detail = ''): void
{
    db_insert('crm_card_history', [
        'card_id' => $cardId,
        'action'  => $action,
        'detail'  => $detail,
    ]);
}

function crm_stats(int $tenantId): array
{
    $row = db_one(
        'SELECT
            COUNT(*) AS total_cards,
            SUM(CASE WHEN cnpj IS NOT NULL THEN 1 ELSE 0 END) AS from_cnpj,
            COUNT(DISTINCT column_id) AS used_columns
         FROM crm_cards WHERE tenant_id = ?',
        [$tenantId]
    );
    return $row ?: ['total_cards' => 0, 'from_cnpj' => 0, 'used_columns' => 0];
}

// ─── v2: Tags ────────────────────────────────────────────────────────────────
function crm_tags_list(int $tenantId): array
{
    return db_all('SELECT * FROM crm_tags WHERE tenant_id = ? ORDER BY name', [$tenantId]);
}

function crm_tag_create(int $tenantId, string $name, string $color = '#10b981'): int
{
    // INSERT IGNORE: se já existir (uniq tenant+name), retorna o id existente
    try {
        return (int) db_insert('crm_tags', ['tenant_id' => $tenantId, 'name' => $name, 'color' => $color]);
    } catch (\Throwable $e) {
        $id = (int) db_val('SELECT id FROM crm_tags WHERE tenant_id = ? AND name = ?', [$tenantId, $name]);
        return $id;
    }
}

function crm_tag_delete(int $tenantId, int $tagId): void
{
    db_q('DELETE FROM crm_card_tags WHERE tag_id = ?', [$tagId]);
    db_q('DELETE FROM crm_tags WHERE id = ? AND tenant_id = ?', [$tagId, $tenantId]);
}

function crm_card_tags(int $cardId): array
{
    return db_all(
        'SELECT t.id, t.name, t.color FROM crm_tags t
         JOIN crm_card_tags ct ON ct.tag_id = t.id
         WHERE ct.card_id = ? ORDER BY t.name',
        [$cardId]
    );
}

// Mapa card_id => [tags] em uma única query (evita N+1 no render do Kanban)
function crm_tags_by_cards(array $cardIds): array
{
    if (empty($cardIds)) return [];
    $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
    $rows = db_all(
        "SELECT ct.card_id, t.id, t.name, t.color FROM crm_card_tags ct
         JOIN crm_tags t ON t.id = ct.tag_id
         WHERE ct.card_id IN ($placeholders)
         ORDER BY t.name",
        $cardIds
    );
    $out = [];
    foreach ($rows as $r) {
        $out[$r['card_id']][] = ['id' => $r['id'], 'name' => $r['name'], 'color' => $r['color']];
    }
    return $out;
}

function crm_card_set_tags(int $cardId, array $tagIds): void
{
    db_q('DELETE FROM crm_card_tags WHERE card_id = ?', [$cardId]);
    foreach (array_unique(array_map('intval', $tagIds)) as $tid) {
        if ($tid <= 0) continue;
        try { db_q('INSERT INTO crm_card_tags (card_id, tag_id) VALUES (?, ?)', [$cardId, $tid]); }
        catch (\Throwable $e) {} // PK dup — ignora
    }
}

// ─── v2: Comentários ─────────────────────────────────────────────────────────
function crm_comments_list(int $cardId): array
{
    return db_all(
        'SELECT c.id, c.user_id, c.body, c.created_at, u.name AS user_name, u.email AS user_email
         FROM crm_comments c
         JOIN users u ON u.id = c.user_id
         WHERE c.card_id = ? ORDER BY c.created_at DESC',
        [$cardId]
    );
}

function crm_comment_add(int $cardId, int $userId, string $body): int
{
    return (int) db_insert('crm_comments', [
        'card_id' => $cardId,
        'user_id' => $userId,
        'body'    => $body,
    ]);
}

function crm_comment_delete(int $commentId, int $userId): bool
{
    // Só o autor pode apagar o próprio comentário
    $rows = db_q('DELETE FROM crm_comments WHERE id = ? AND user_id = ?', [$commentId, $userId]);
    return true;
}

// ─── v2: Tenant users (para responsável) ─────────────────────────────────────
function crm_tenant_users(int $tenantId): array
{
    return db_all(
        'SELECT u.id, u.name, u.email, tu.role
         FROM users u
         JOIN tenant_users tu ON tu.user_id = u.id
         WHERE tu.tenant_id = ?
         ORDER BY u.name',
        [$tenantId]
    );
}

function crm_card_assign(int $tenantId, int $cardId, ?int $userId): void
{
    // Valida: se userId, ele tem que pertencer ao tenant
    if ($userId !== null && $userId > 0) {
        $ok = (int) db_val('SELECT 1 FROM tenant_users WHERE tenant_id = ? AND user_id = ?', [$tenantId, $userId]);
        if (!$ok) $userId = null;
    } else {
        $userId = null;
    }
    db_q('UPDATE crm_cards SET assigned_user_id = ?, last_action = NOW() WHERE id = ? AND tenant_id = ?',
        [$userId, $cardId, $tenantId]);
}
