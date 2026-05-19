<?php
/**
 * NEWTONIA — Camada de banco
 * PDO singleton + helpers diretos. Sem ORM, sem mágica.
 */

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '-03:00'"
        ]);
    }
    return $pdo;
}

function db_q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function db_one(string $sql, array $params = []): ?array {
    $row = db_q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array {
    return db_q($sql, $params)->fetchAll();
}

function db_val(string $sql, array $params = []) {
    $v = db_q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function db_insert(string $table, array $data): int {
    $cols = array_keys($data);
    $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) '
          . 'VALUES (:' . implode(', :', $cols) . ')';
    db_q($sql, $data);
    return (int) db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $whereParams = []): int {
    $set = [];
    foreach (array_keys($data) as $col) $set[] = '`' . $col . '` = :' . $col;
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $where;
    return db_q($sql, array_merge($data, $whereParams))->rowCount();
}

function db_delete(string $table, string $where, array $whereParams = []): int {
    return db_q('DELETE FROM `' . $table . '` WHERE ' . $where, $whereParams)->rowCount();
}
