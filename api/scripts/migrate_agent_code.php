<?php

declare(strict_types=1);

/**
 * One-time (idempotent) migration: agents.agent_code + users.assigned_agent_id.
 * Run from the api folder: php scripts/migrate_agent_code.php
 */

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use Paperkaaran\Db\Database;

$pdo = Database::pdo();

function dbName(PDO $pdo): string
{
    $n = $pdo->query('SELECT DATABASE()')->fetchColumn();
    return is_string($n) ? $n : '';
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $db = dbName($pdo);
    if ($db === '') {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
    );
    $st->execute(['db' => $db, 't' => $table, 'c' => $column]);

    return $st->fetch() !== false;
}

function indexExists(PDO $pdo, string $table, string $indexName): bool
{
    $db = dbName($pdo);
    if ($db === '') {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND INDEX_NAME = :i LIMIT 1'
    );
    $st->execute(['db' => $db, 't' => $table, 'i' => $indexName]);

    return $st->fetch() !== false;
}

function fkExists(PDO $pdo, string $table, string $constraintName): bool
{
    $db = dbName($pdo);
    if ($db === '') {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND CONSTRAINT_NAME = :n
           AND CONSTRAINT_TYPE = :type LIMIT 1'
    );
    $st->execute([
        'db' => $db,
        't' => $table,
        'n' => $constraintName,
        'type' => 'FOREIGN KEY',
    ]);

    return $st->fetch() !== false;
}

echo "Kaalaivanakam migrate_agent_code — database: " . dbName($pdo) . "\n";

if (!columnExists($pdo, 'agents', 'agent_code')) {
    echo "Adding agents.agent_code…\n";
    $pdo->exec('ALTER TABLE `agents` ADD COLUMN `agent_code` CHAR(8) NULL AFTER `id`');
    $pdo->exec("UPDATE `agents` SET `agent_code` = CONCAT('A', LPAD(`id`, 7, '0')) WHERE `agent_code` IS NULL");
    $pdo->exec('ALTER TABLE `agents` MODIFY `agent_code` CHAR(8) NOT NULL');
}

if (!indexExists($pdo, 'agents', 'uq_agents_agent_code')) {
    echo "Adding unique key uq_agents_agent_code…\n";
    $pdo->exec('ALTER TABLE `agents` ADD UNIQUE KEY `uq_agents_agent_code` (`agent_code`)');
}

if (!columnExists($pdo, 'users', 'assigned_agent_id')) {
    echo "Adding users.assigned_agent_id…\n";
    $pdo->exec(
        'ALTER TABLE `users` ADD COLUMN `assigned_agent_id` INT UNSIGNED NULL AFTER `area_id`'
    );
}

if (!indexExists($pdo, 'users', 'idx_users_assigned_agent')) {
    echo "Adding index idx_users_assigned_agent…\n";
    $pdo->exec('ALTER TABLE `users` ADD KEY `idx_users_assigned_agent` (`assigned_agent_id`)');
}

if (!fkExists($pdo, 'users', 'fk_users_assigned_agent')) {
    echo "Adding FK fk_users_assigned_agent…\n";
    $pdo->exec(
        'ALTER TABLE `users` ADD CONSTRAINT `fk_users_assigned_agent`
         FOREIGN KEY (`assigned_agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL'
    );
}

// Best-effort: link existing customers to an active agent for their area (signup code feature).
echo "Backfilling users.assigned_agent_id from area coverage…\n";
$pdo->exec(
    "UPDATE `users` u
     INNER JOIN (
       SELECT aa.area_id, MIN(aa.agent_id) AS agent_id
       FROM agent_areas aa
       INNER JOIN agents a ON a.id = aa.agent_id AND a.status = 'active'
       GROUP BY aa.area_id
     ) x ON x.area_id = u.area_id
     SET u.assigned_agent_id = x.agent_id
     WHERE u.assigned_agent_id IS NULL AND u.area_id IS NOT NULL"
);

$st = $pdo->query('SELECT COUNT(*) FROM agents');
$nAgents = (int) $st->fetchColumn();
echo "Done. Agents: {$nAgents}. Reload Super admin.\n";
