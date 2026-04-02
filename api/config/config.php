<?php

declare(strict_types=1);

/**
 * Read env vars from Dotenv / the process environment.
 * On Windows + PHP built-in server, `getenv()` often misses values that vlucas/phpdotenv
 * only puts in $_ENV / $_SERVER — that caused wrong DB port and disabled debug details.
 */
if (!function_exists('paperkaaran_env')) {
    function paperkaaran_env(string $key, string $default = ''): string
    {
    if (array_key_exists($key, $_ENV)) {
        return (string) $_ENV[$key];
    }
    if (array_key_exists($key, $_SERVER)) {
        return (string) $_SERVER[$key];
    }
    $g = getenv($key);
    if ($g !== false) {
        return (string) $g;
    }

    return $default;
    }
}

$dbDsn = paperkaaran_env('PAPERKAARAN_DB_DSN');
if ($dbDsn === '') {
    $host = paperkaaran_env('PAPERKAARAN_DB_HOST');
    if ($host === '') {
        $host = paperkaaran_env('DB_HOST', '127.0.0.1');
    }
    $port = paperkaaran_env('PAPERKAARAN_DB_PORT');
    if ($port === '') {
        $port = paperkaaran_env('DB_PORT', '3306');
    }
    $name = paperkaaran_env('PAPERKAARAN_DB_NAME');
    if ($name === '') {
        $name = paperkaaran_env('DB_NAME', 'paperkaaran');
    }
    $dbDsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $name,
    );
}

$dbUser = paperkaaran_env('PAPERKAARAN_DB_USER');
if ($dbUser === '') {
    $dbUser = paperkaaran_env('DB_USER', 'root');
}
$dbPass = paperkaaran_env('PAPERKAARAN_DB_PASS');
if ($dbPass === '') {
    $dbPass = paperkaaran_env('DB_PASS', '');
}

$jwtSecret = paperkaaran_env('PAPERKAARAN_JWT_SECRET');
if ($jwtSecret === '') {
    $jwtSecret = paperkaaran_env(
        'JWT_SECRET',
        'dev-only-change-in-production-paperkaaran-secret',
    );
}

$jwtTtl = paperkaaran_env('PAPERKAARAN_JWT_TTL');
if ($jwtTtl === '') {
    $jwtTtl = paperkaaran_env('JWT_ACCESS_TTL', '86400');
}
$jwtTtlSeconds = filter_var($jwtTtl, FILTER_VALIDATE_INT);
if ($jwtTtlSeconds === false || $jwtTtlSeconds < 60) {
    $jwtTtlSeconds = 86400;
}

$debugRaw = paperkaaran_env('PAPERKAARAN_DEBUG');
if ($debugRaw === '') {
    $debugRaw = paperkaaran_env('APP_DEBUG', 'false');
}

return [
    'debug' => filter_var($debugRaw, FILTER_VALIDATE_BOOLEAN),

    'db' => [
        'dsn' => $dbDsn,
        'user' => $dbUser,
        'pass' => $dbPass,
    ],
    'jwt' => [
        'secret' => $jwtSecret,
        'issuer' => 'paperkaaran-api',
        'ttl_seconds' => $jwtTtlSeconds,
    ],
];
