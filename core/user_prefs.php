<?php
/**
 * HERMES.b2b — Preferências por usuário
 * Tabela key-value simples. Cada pref é salva individualmente.
 */

function user_prefs_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    try {
        db_q("CREATE TABLE IF NOT EXISTS user_preferences (
            user_id   INT NOT NULL,
            pref_key  VARCHAR(80) NOT NULL,
            pref_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, pref_key),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { /* já existe */ }
    $done = true;
}

/** Lê uma preferência do usuário. Retorna $default se não existir. */
function user_pref_get(int $userId, string $key, $default = null)
{
    user_prefs_ensure_schema();
    $row = db_one('SELECT pref_value FROM user_preferences WHERE user_id = ? AND pref_key = ?', [$userId, $key]);
    return $row !== null ? $row['pref_value'] : $default;
}

/** Lê TODAS as preferências de um usuário como array [key => value]. */
function user_prefs_all(int $userId): array
{
    user_prefs_ensure_schema();
    $rows = db_all('SELECT pref_key, pref_value FROM user_preferences WHERE user_id = ?', [$userId]);
    $out = [];
    foreach ($rows as $r) $out[$r['pref_key']] = $r['pref_value'];
    return $out;
}

/** Salva (upsert) uma preferência. */
function user_pref_set(int $userId, string $key, $value): void
{
    user_prefs_ensure_schema();
    db_q(
        "INSERT INTO user_preferences (user_id, pref_key, pref_value)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = NOW()",
        [$userId, $key, (string) $value]
    );
}

/** Salva múltiplas prefs de uma vez. */
function user_prefs_set_many(int $userId, array $data): void
{
    foreach ($data as $k => $v) user_pref_set($userId, $k, $v);
}

/**
 * Definição de todas as prefs do HERMES.b2b com defaults.
 * Usado tanto na página de config quanto pra ler prefs em outros módulos.
 */
function hermes_pref_defaults(): array
{
    return [
        // Notificações
        'notif_billing'           => '1',  // e-mail de cobrança/fatura
        'notif_quota_alert'       => '1',  // alerta 80%/90% da cota Radar
        'notif_news'              => '1',  // novidades e releases HERMES

        // Painel
        'default_module'          => 'overview', // módulo ao logar: overview|crm|cnpj|maillab
        'sidebar_pinned'          => '0',         // sidebar fixada expandida

        // Radar Leads
        'radar_per_page'          => '25',  // resultados por página: 10|25|50
        'radar_default_sort'      => 'score_desc', // score_desc|name_asc|city_asc
        'radar_show_score_detail' => '1',   // mostrar breakdown do score

        // Pipeline
        'pipeline_show_archived'  => '0',   // mostrar cards arquivados por padrão
        'pipeline_default_view'   => 'kanban', // kanban|list
        'pipeline_cards_per_col'  => '50',  // limite visual de cards por coluna
        'pipeline_show_value'     => '1',   // mostrar campo valor (R$) nos cards
        'pipeline_show_due_date'  => '1',   // mostrar campo de prazo/vencimento
        'pipeline_notify_due'     => '1',   // notificar por e-mail quando prazo vence
        'pipeline_product_label'  => '',    // rótulo do produto/serviço que está sendo vendido
        'pipeline_card_color'     => 'score', // colorir cards por: score|column|mono
    ];
}

/** Lê todas as prefs do usuário com fallback pros defaults. */
function user_prefs_with_defaults(int $userId): array
{
    $saved    = user_prefs_all($userId);
    $defaults = hermes_pref_defaults();
    return array_merge($defaults, $saved); // saved sobrescreve defaults
}
