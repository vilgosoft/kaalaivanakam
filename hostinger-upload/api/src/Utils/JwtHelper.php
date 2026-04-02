<?php

declare(strict_types=1);

namespace Paperkaaran\Utils;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class JwtHelper
{
    public static function encode(int $subjectId, string $role): string
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $jwt = $config['jwt'];
        $secret = (string) ($jwt['secret'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException(
                'JWT secret is empty. Set JWT_SECRET or PAPERKAARAN_JWT_SECRET in api/.env (no quotes needed unless the value contains spaces).',
            );
        }
        $now = time();
        $payload = [
            'iss' => $jwt['issuer'],
            'iat' => $now,
            'exp' => $now + (int) $jwt['ttl_seconds'],
            'sub' => (string) $subjectId,
            'role' => $role,
        ];
        return JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * @return array{sub: string, role: string, iat: int, exp: int}
     */
    public static function decode(string $token): array
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $jwt = $config['jwt'];
        $secret = (string) ($jwt['secret'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException('JWT secret is empty; check api/.env');
        }
        $decoded = JWT::decode($token, new Key($secret, 'HS256'));
        return (array) $decoded;
    }
}
