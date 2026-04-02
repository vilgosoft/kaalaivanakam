<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$config = require dirname(__DIR__) . '/config/config.php';
$db = $config['db'];

try {
    $pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->query('SELECT 1');
    echo "OK: Connected to MySQL ({$db['dsn']})\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
