<?php

/**
 * Router for PHP’s built-in server (`php -S … router.php`).
 * Forwards all non-file requests to index.php so /v1/* works locally.
 */
declare(strict_types=1);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($uri !== '/' && $uri !== '' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

require __DIR__ . '/index.php';
