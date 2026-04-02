<?php

declare(strict_types=1);

namespace Paperkaaran\Middleware;

use Paperkaaran\Utils\JwtHelper;
use Paperkaaran\Utils\Response;

final class AuthMiddleware
{
    /**
     * @return array{sub: int, role: string}
     */
    public static function requireBearer(string $expectedRole): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            Response::error('Missing or invalid Authorization header', 401, 'unauthorized');
        }
        try {
            $payload = JwtHelper::decode(trim($m[1]));
        } catch (\Throwable) {
            Response::error('Invalid or expired token', 401, 'unauthorized');
        }
        $role = (string) ($payload['role'] ?? '');
        if ($role !== $expectedRole) {
            Response::error('Forbidden', 403, 'forbidden');
        }
        $sub = (int) ($payload['sub'] ?? 0);
        if ($sub <= 0) {
            Response::error('Invalid token subject', 401, 'unauthorized');
        }
        return ['sub' => $sub, 'role' => $role];
    }
}
