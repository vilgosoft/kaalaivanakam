<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Middleware\AuthMiddleware;
use Paperkaaran\Utils\Response;

final class AgentEmployeeController
{
    public function index(): void
    {
        $auth = AuthMiddleware::requireBearer('agent');
        $agentId = (int) $auth['sub'];
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, agent_id, name, phone_number, email_id, address, status, created_at
             FROM agent_employees
             WHERE agent_id = :aid
             ORDER BY name, id'
        );
        $stmt->execute(['aid' => $agentId]);
        Response::json(['employees' => $stmt->fetchAll()]);
    }

    public function create(): void
    {
        $auth = AuthMiddleware::requireBearer('agent');
        $agentId = (int) $auth['sub'];
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }

        $password = isset($body['password']) ? (string) $body['password'] : '';
        $name = isset($body['name']) ? trim((string) $body['name']) : '';
        $phone = isset($body['phone_number']) ? trim((string) $body['phone_number']) : '';
        $email = strtolower(trim((string) ($body['email_id'] ?? '')));
        $address = isset($body['address']) ? trim((string) $body['address']) : '';

        if (strlen($password) < 8) {
            Response::error('password must be at least 8 characters', 400);
        }
        if ($name === '' || mb_strlen($name) > 255) {
            Response::error('name required (1–255 characters)', 400);
        }
        if ($phone !== '' && mb_strlen($phone) > 20) {
            Response::error('phone_number too long', 400);
        }
        if ($email === '' || mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Valid email_id is required (same email is used to sign in)', 400);
        }

        $pdo = Database::pdo();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO agent_employees (agent_id, username, password_hash, name, phone_number, email_id, address)
             VALUES (:aid, :u, :h, :n, :p, :e, :a)'
        );

        $maxAttempts = 6;
        $insertOk = false;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $username = 'emp_' . bin2hex(random_bytes(12));
            try {
                $stmt->execute([
                    'aid' => $agentId,
                    'u' => $username,
                    'h' => $hash,
                    'n' => $name,
                    'p' => $phone === '' ? null : $phone,
                    'e' => $email,
                    'a' => $address === '' ? null : $address,
                ]);
                $insertOk = true;
                break;
            } catch (\PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains((string) $e->getMessage(), 'username')) {
                    continue;
                }
                if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate')) {
                    Response::error('This email is already in use for an employee', 409);
                }
                Response::error('Could not create employee', 500);
            }
        }
        if (!$insertOk) {
            Response::error('Could not create employee', 500);
        }

        $id = (int) $pdo->lastInsertId();
        $createdAt = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        Response::json([
            'employee' => [
                'id' => $id,
                'agent_id' => $agentId,
                'name' => $name,
                'phone_number' => $phone === '' ? null : $phone,
                'email_id' => $email === '' ? null : $email,
                'address' => $address === '' ? null : $address,
                'status' => 'active',
                'created_at' => $createdAt,
            ],
        ], 201);
    }
}
