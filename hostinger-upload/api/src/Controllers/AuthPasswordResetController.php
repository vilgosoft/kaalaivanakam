<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Utils\Response;

/**
 * Forgot / reset password for super_admin and user (customer) only.
 */
final class AuthPasswordResetController
{
    public function forgotPassword(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Valid email is required', 400);
        }

        $pdo = Database::pdo();
        $accountType = null;
        $accountId = 0;

        $stmt = $pdo->prepare('SELECT id FROM super_admins WHERE email = :e LIMIT 1');
        $stmt->execute(['e' => $email]);
        $row = $stmt->fetch();
        if ($row !== false) {
            $accountType = 'super_admin';
            $accountId = (int) $row['id'];
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email_id = :e LIMIT 1');
            $stmt->execute(['e' => $email]);
            $row = $stmt->fetch();
            if ($row !== false) {
                $accountType = 'user';
                $accountId = (int) $row['id'];
            }
        }

        $generic = 'If this email is registered as a super admin or customer, you can continue below. '
            . 'Agents and delivery staff must ask their administrator to reset access.';

        if ($accountType === null) {
            Response::json([
                'ok' => true,
                'message' => $generic,
            ]);
        }

        $pdo->prepare(
            'DELETE FROM password_reset_tokens
             WHERE account_type = :t AND account_id = :id AND used_at IS NULL'
        )->execute(['t' => $accountType, 'id' => $accountId]);

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $ins = $pdo->prepare(
            'INSERT INTO password_reset_tokens (token_hash, account_type, account_id, expires_at)
             VALUES (:h, :t, :aid, :ex)'
        );
        $ins->execute([
            'h' => $tokenHash,
            't' => $accountType,
            'aid' => $accountId,
            'ex' => $expiresAt,
        ]);

        Response::json([
            'ok' => true,
            'message' => $generic,
            'mock_reset' => [
                'token' => $plainToken,
                'expires_at' => $expiresAt,
                'note' => 'Demo: paste the token on the next screen, or open the app with ?reset=TOKEN in the URL.',
            ],
        ]);
    }

    public function resetPassword(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }
        $token = trim((string) ($body['token'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
            Response::error('Invalid or missing reset token', 400);
        }
        if (strlen($password) < 8) {
            Response::error('password must be at least 8 characters', 400);
        }

        $tokenHash = hash('sha256', $token);
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, account_type, account_id FROM password_reset_tokens
             WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(['h' => $tokenHash]);
        $row = $stmt->fetch();
        if ($row === false) {
            Response::error('Invalid or expired reset token', 400);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $id = (int) $row['id'];
        $type = (string) $row['account_type'];
        $aid = (int) $row['account_id'];

        if ($type === 'super_admin') {
            $pdo->prepare('UPDATE super_admins SET password_hash = :h WHERE id = :id')->execute([
                'h' => $hash,
                'id' => $aid,
            ]);
        } else {
            $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute([
                'h' => $hash,
                'id' => $aid,
            ]);
        }

        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id')->execute(['id' => $id]);

        Response::json(['ok' => true, 'message' => 'Password updated. You can sign in with your email and new password.']);
    }
}
