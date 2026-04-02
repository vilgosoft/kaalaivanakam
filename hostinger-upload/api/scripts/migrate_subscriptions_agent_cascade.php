<?php

declare(strict_types=1);

/**
 * subscriptions.agent_id → agents.id: ON DELETE CASCADE (allows deleting agents with orders).
 * Run: php scripts/migrate_subscriptions_agent_cascade.php
 */

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use Paperkaaran\Db\Database;

$pdo = Database::pdo();
$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
$st = $pdo->prepare(
    'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = :db AND CONSTRAINT_NAME = :n LIMIT 1'
);
$st->execute(['db' => $db, 'n' => 'fk_subscriptions_agent']);
$row = $st->fetchColumn();
if (is_string($row) && strtoupper($row) === 'CASCADE') {
    echo "Skip: fk_subscriptions_agent already ON DELETE CASCADE\n";
    exit(0);
}

$pdo->exec('ALTER TABLE `subscriptions` DROP FOREIGN KEY `fk_subscriptions_agent`');
$pdo->exec(
    'ALTER TABLE `subscriptions` ADD CONSTRAINT `fk_subscriptions_agent` '
    . 'FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE'
);
echo "OK: fk_subscriptions_agent → ON DELETE CASCADE\n";
