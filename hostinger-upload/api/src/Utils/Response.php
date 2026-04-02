<?php

declare(strict_types=1);

namespace Paperkaaran\Utils;

final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $message, int $status = 400, ?string $code = null): never
    {
        $body = ['error' => $message];
        if ($code !== null) {
            $body['code'] = $code;
        }
        self::json($body, $status);
    }

    public static function cors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }
}
