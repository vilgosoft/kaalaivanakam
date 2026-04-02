<?php

declare(strict_types=1);

/**
 * Create super_admins table if missing and upsert a super-admin login (local / recovery).
 *
 *   php api/scripts/bootstrap_super_admin.php
 *   php api/scripts/bootstrap_super_admin.php admin@kaalaivanakam.in yourpassword
 *
 * Requires api/.env with working DB_* (or PAPERKAARAN_*). CLI only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

use Paperkaaran\Db\Database;

$email = strtolower(trim($argv[1] ?? 'admin@kaalaivanakam.in'));
$plain = $argv[2] ?? 'password';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email.\n");
    exit(1);
}

try {
    $pdo = Database::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS `super_admins` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `email` VARCHAR(255) NOT NULL,
      `password_hash` VARCHAR(255) NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_super_admins_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
);

$hash = password_hash($plain, PASSWORD_DEFAULT);
$st = $pdo->prepare(
    'INSERT INTO `super_admins` (`email`, `password_hash`) VALUES (:e, :h)
     ON DUPLICATE KEY UPDATE `password_hash` = VALUES(`password_hash`)',
);
$st->execute(['e' => $email, 'h' => $hash]);

echo "OK: super admin ready for {$email}\n";
echo "    (password set or updated). Use a strong password in production.\n";
