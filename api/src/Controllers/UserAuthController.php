<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Utils\JwtHelper;
use Paperkaaran\Utils\Response;

final class UserAuthController
{
    public function login(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }
        $email = self::normalizeEmail(isset($body['email']) ? (string) $body['email'] : '');
        $password = isset($body['password']) ? (string) $body['password'] : '';
        if ($email === '' || $password === '') {
            Response::error('email and password are required', 400);
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, password_hash FROM users WHERE email_id = :e LIMIT 1'
        );
        $stmt->execute(['e' => $email]);
        $row = $stmt->fetch();
        if ($row === false || !password_verify($password, (string) $row['password_hash'])) {
            Response::error('Invalid email or password', 401);
        }
        $userId = (int) $row['id'];
        $token = JwtHelper::encode($userId, 'user');
        Response::json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user_id' => $userId,
        ]);
    }

    public function register(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }
        $email = self::normalizeEmail(isset($body['email']) ? (string) $body['email'] : '');
        $password = isset($body['password']) ? (string) $body['password'] : '';
        $name = isset($body['name']) ? trim((string) $body['name']) : '';
        $phoneRaw = isset($body['phone']) ? (string) $body['phone'] : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Valid email is required', 400);
        }
        if (strlen($password) < 8) {
            Response::error('Password must be at least 8 characters', 400);
        }
        $phone = $phoneRaw !== '' ? self::normalizeIndianPhone($phoneRaw) : null;
        if ($phoneRaw !== '' && $phone === null) {
            Response::error('Invalid Indian mobile; use 10 digits or +91 prefix, or omit phone', 400);
        }
        $agentCodeRaw = isset($body['agent_code']) ? trim((string) $body['agent_code']) : '';
        if ($agentCodeRaw === '') {
            Response::error('agent_code is required (from your delivery agent)', 400);
        }
        $agentCode = strtoupper(preg_replace('/\s+/', '', $agentCodeRaw) ?? '');
        if (strlen($agentCode) !== 8 || !ctype_alnum($agentCode)) {
            Response::error('agent_code must be 8 letters or numbers', 400);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo = Database::pdo();

        $agStmt = $pdo->prepare('SELECT id, status FROM agents WHERE agent_code = :c LIMIT 1');
        $agStmt->execute(['c' => $agentCode]);
        $agentRow = $agStmt->fetch();
        if ($agentRow === false) {
            Response::error('Invalid agent code', 404);
        }
        if (($agentRow['status'] ?? '') !== 'active') {
            Response::error('This agent code is not active', 403);
        }
        $agentId = (int) $agentRow['id'];

        $areaStmt = $pdo->prepare(
            'SELECT area_id FROM agent_areas WHERE agent_id = :aid ORDER BY area_id ASC LIMIT 1'
        );
        $areaStmt->execute(['aid' => $agentId]);
        $areaRow = $areaStmt->fetch();
        if ($areaRow === false) {
            Response::error(
                'This agent has no delivery areas yet. Ask them to add neighborhoods in their workspace, then sign up again.',
                422,
            );
        }
        $defaultAreaId = (int) $areaRow['area_id'];

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (name, phone, email_id, password_hash, assigned_agent_id, area_id)
                 VALUES (:n, :p, :e, :h, :ag, :ar)'
            );
            $stmt->execute([
                'n' => $name !== '' ? $name : null,
                'p' => $phone,
                'e' => $email,
                'h' => $hash,
                'ag' => $agentId,
                'ar' => $defaultAreaId,
            ]);
        } catch (\PDOException $ex) {
            if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
                Response::error('Email or phone is already registered', 409);
            }
            throw $ex;
        }
        $userId = (int) $pdo->lastInsertId();
        $token = JwtHelper::encode($userId, 'user');
        Response::json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user_id' => $userId,
        ], 201);
    }

    private static function normalizeEmail(string $input): string
    {
        return strtolower(trim($input));
    }

    private static function normalizeIndianPhone(string $input): ?string
    {
        $digits = preg_replace('/\D/', '', $input) ?? '';
        if (strlen($digits) === 10) {
            return '+91' . $digits;
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return '+' . $digits;
        }
        return null;
    }
}
