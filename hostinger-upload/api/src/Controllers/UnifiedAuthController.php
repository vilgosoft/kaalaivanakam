<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Utils\JwtHelper;
use Paperkaaran\Utils\Response;
use PDOException;
use Throwable;

/**
 * Single sign-in with email + password for super admin, agent, delivery staff, and customer.
 * Resolution order: super_admin → agent → employee → user (first match wins).
 */
final class UnifiedAuthController
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
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Valid email and password are required', 400);
        }
        if ($password === '') {
            Response::error('Valid email and password are required', 400);
        }

        try {
            $pdo = Database::pdo();
        } catch (PDOException $e) {
            error_log('Unified login DB connect: ' . $e->getMessage());
            self::dbUnavailableResponse();
        }

        try {
            self::attemptLogin($pdo, $email, $password);
        } catch (PDOException $e) {
            error_log('Unified login SQL: ' . $e->getMessage());
            self::dbUnavailableResponse();
        } catch (\RuntimeException $e) {
            error_log('Unified login: ' . $e->getMessage());
            Response::error('Server misconfiguration: ' . $e->getMessage(), 500);
        } catch (Throwable $e) {
            error_log('Unified login: ' . $e->getMessage());
            $config = require dirname(__DIR__, 2) . '/config/config.php';
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
    }

    private static function dbUnavailableResponse(): never
    {
        Response::error(
            'Database unavailable. Check api/.env (DB_*), start MySQL, import your schema, then run: php api/scripts/bootstrap_super_admin.php',
            503,
        );
    }

    private static function attemptLogin(\PDO $pdo, string $email, string $password): void
    {
        $stmt = $pdo->prepare('SELECT id, password_hash FROM super_admins WHERE email = :e LIMIT 1');
        $stmt->execute(['e' => $email]);
        $row = $stmt->fetch();
        if ($row !== false && password_verify($password, (string) $row['password_hash'])) {
            $id = (int) $row['id'];
            Response::json([
                'access_token' => JwtHelper::encode($id, 'super_admin'),
                'token_type' => 'Bearer',
                'role' => 'super_admin',
                'super_admin_id' => $id,
            ]);
        }

        $stmt = $pdo->prepare(
            'SELECT id, password_hash, status FROM agents WHERE email_id = :e LIMIT 1'
        );
        $stmt->execute(['e' => $email]);
        $row = $stmt->fetch();
        if ($row !== false && $row['status'] === 'active'
            && password_verify($password, (string) $row['password_hash'])) {
            $id = (int) $row['id'];
            Response::json([
                'access_token' => JwtHelper::encode($id, 'agent'),
                'token_type' => 'Bearer',
                'role' => 'agent',
                'agent_id' => $id,
            ]);
        }

        $stmt = $pdo->prepare(
            'SELECT e.id, e.password_hash, e.status, e.agent_id, a.status AS agent_status
             FROM agent_employees e
             INNER JOIN agents a ON a.id = e.agent_id
             WHERE e.email_id = :e
             LIMIT 1'
        );
        $stmt->execute(['e' => $email]);
        $row = $stmt->fetch();
        if ($row !== false && $row['status'] === 'active' && $row['agent_status'] === 'active'
            && password_verify($password, (string) $row['password_hash'])) {
            $id = (int) $row['id'];
            Response::json([
                'access_token' => JwtHelper::encode($id, 'employee'),
                'token_type' => 'Bearer',
                'role' => 'employee',
                'employee_id' => $id,
                'agent_id' => (int) $row['agent_id'],
            ]);
        }

        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email_id = :e LIMIT 1');
        $stmt->execute(['e' => $email]);
        $row = $stmt->fetch();
        if ($row !== false && password_verify($password, (string) $row['password_hash'])) {
            $id = (int) $row['id'];
            Response::json([
                'access_token' => JwtHelper::encode($id, 'user'),
                'token_type' => 'Bearer',
                'role' => 'user',
                'user_id' => $id,
            ]);
        }

        Response::error('Invalid email or password', 401);
    }
}
