<?php

declare(strict_types=1);

namespace Paperkaaran\Db;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
            $db = $config['db'];
            self::$pdo = new PDO(
                $db['dsn'],
                $db['user'],
                $db['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        }

        return self::$pdo;
    }

    public static function resetForTesting(): void
    {
        self::$pdo = null;
    }
}
