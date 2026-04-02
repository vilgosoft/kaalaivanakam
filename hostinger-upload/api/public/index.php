<?php

declare(strict_types=1);

use Paperkaaran\Router;
use Paperkaaran\Utils\Response;

require dirname(__DIR__) . '/bootstrap.php';

Response::cors();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Prefer client path /v1/...; some hosts pass the internal script URI after rewrite.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path) || $path === '') {
    $path = '/';
}
$path = rtrim($path, '/') ?: '/';

if ($path !== '/' && !str_starts_with($path, '/v1')) {
    foreach (['REDIRECT_URL', 'REDIRECT_URI'] as $key) {
        $raw = $_SERVER[$key] ?? '';
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        $candidate = parse_url($raw, PHP_URL_PATH);
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }
        $candidate = rtrim($candidate, '/') ?: '/';
        if (str_starts_with($candidate, '/v1')) {
            $path = $candidate;
            break;
        }
    }
}

if (!str_starts_with($path, '/v1')) {
    $pi = $_SERVER['PATH_INFO'] ?? '';
    if (is_string($pi) && $pi !== '' && str_starts_with($pi, '/v1')) {
        $path = rtrim($pi, '/') ?: '/';
    }
}

try {
    (new Router())->dispatch($method, $path);
} catch (Throwable $e) {
    error_log((string) $e);
    $config = require dirname(__DIR__) . '/config/config.php';
    if (!empty($config['debug'])) {
        Response::json(
            [
                'error' => 'Internal server error',
                'detail' => $e->getMessage(),
                'type' => $e::class,
            ],
            500,
        );
    }
    Response::error('Internal server error', 500);
}
