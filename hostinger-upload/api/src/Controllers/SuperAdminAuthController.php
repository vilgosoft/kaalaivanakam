<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Utils\JwtHelper;
use Paperkaaran\Utils\Response;

final class SuperAdminAuthController
{
    public function login(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = isset($body['password']) ? (string) $body['password'] : '';
        if ($email === '' || $password === '') {
            Response::error('email and password required', 400);
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, password_hash FROM super_admins WHERE email = :e LIMIT 1');
        $stmt->execute(['e' => $email]);
        $row = $stmt->fetch();
        if ($row === false || !password_verify($password, (string) $row['password_hash'])) {
            Response::error('Invalid credentials', 401);
        }
        $id = (int) $row['id'];
        $token = JwtHelper::encode($id, 'super_admin');
        Response::json(['access_token' => $token, 'token_type' => 'Bearer', 'super_admin_id' => $id]);
    }
}
