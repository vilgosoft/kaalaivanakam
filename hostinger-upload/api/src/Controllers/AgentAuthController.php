<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Utils\JwtHelper;
use Paperkaaran\Utils\Response;

final class AgentAuthController
{
    public function login(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }
        $username = isset($body['username']) ? (string) $body['username'] : '';
        $password = isset($body['password']) ? (string) $body['password'] : '';
        if ($username === '' || $password === '') {
            Response::error('username and password required', 400);
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, password_hash, status FROM agents WHERE username = :u LIMIT 1'
        );
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();
        if ($row === false || !password_verify($password, (string) $row['password_hash'])) {
            Response::error('Invalid credentials', 401);
        }
        if ($row['status'] !== 'active') {
            Response::error('Agent account is inactive', 403);
        }
        $id = (int) $row['id'];
        $token = JwtHelper::encode($id, 'agent');
        Response::json(['access_token' => $token, 'token_type' => 'Bearer', 'agent_id' => $id]);
    }
}
