<?php

declare(strict_types=1);

/**
 * PHP 8.4: vlucas/phpdotenv v5.6 (and similar) emit E_DEPRECATED to output. That runs before
 * Response::json() can send headers and breaks the API ("headers already sent").
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

/**
 * Load Composer autoload and optional `.env` from the `api/` directory.
 */
$apiRoot = __DIR__;

require $apiRoot . '/vendor/autoload.php';

$envFile = $apiRoot . '/.env';
if (is_readable($envFile)) {
    Dotenv\Dotenv::createImmutable($apiRoot)->load();
}
