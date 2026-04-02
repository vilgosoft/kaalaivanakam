<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Middleware\AuthMiddleware;
use Paperkaaran\Services\AgentIdResequenceService;
use Paperkaaran\Utils\Response;

final class AgentController
{
    public function index(): void
    {
        AuthMiddleware::requireBearer('super_admin');
        $pdo = Database::pdo();
        $agents = $pdo->query(
            'SELECT a.id, a.agent_code, a.name, a.phone_number, a.email_id, a.address, a.status, a.created_at,
                    (SELECT COUNT(*) FROM subscriptions s WHERE s.agent_id = a.id) AS subscription_count
             FROM agents a ORDER BY a.id'
        )->fetchAll();
        foreach ($agents as &$a) {
            $a['subscription_count'] = (int) ($a['subscription_count'] ?? 0);
            $a['areas'] = $this->areasForAgent($pdo, (int) $a['id']);
        }
        unset($a);
        Response::json(['agents' => $agents]);
    }

    public function create(): void
    {
        AuthMiddleware::requireBearer('super_admin');
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }
        $name = trim((string) ($body['name'] ?? ''));
        $phone = preg_replace('/\D/', '', (string) ($body['phone_number'] ?? '')) ?? '';
        $email = trim((string) ($body['email_id'] ?? ''));
        $address = trim((string) ($body['address'] ?? ''));
        if ($name === '' || strlen($phone) !== 10 || $email === '' || $address === '') {
            Response::error('name, 10-digit phone_number, email_id, and address are required', 400);
        }
        $passwordIn = isset($body['password']) ? trim((string) $body['password']) : '';
        if ($passwordIn !== '' && strlen($passwordIn) < 8) {
            Response::error('password must be at least 8 characters, or omit to auto-generate', 400);
        }
        $plainPassword = $passwordIn !== '' ? $passwordIn : bin2hex(random_bytes(4));
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
        $base = trim($base, '_') ?: 'agent';
        $username = $base . '_' . substr(bin2hex(random_bytes(2)), 0, 4);

        $pdo = Database::pdo();
        $codeRaw = isset($body['agent_code']) ? trim((string) $body['agent_code']) : '';
        $codeNorm = strtoupper(preg_replace('/\s+/', '', $codeRaw) ?? '');
        if ($codeNorm !== '') {
            if (strlen($codeNorm) !== 8 || !ctype_alnum($codeNorm)) {
                Response::error('agent_code must be exactly 8 letters or numbers (or omit for auto-generated)', 400);
            }
            $dup = $pdo->prepare('SELECT 1 FROM agents WHERE agent_code = :c LIMIT 1');
            $dup->execute(['c' => $codeNorm]);
            if ($dup->fetch() !== false) {
                Response::error('This agent code is already in use', 409);
            }
            $agentCode = $codeNorm;
        } else {
            try {
                $agentCode = self::generateUniqueAgentCode($pdo);
            } catch (\RuntimeException) {
                Response::error('Could not allocate agent code; try again', 500);
            }
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO agents (agent_code, name, phone_number, email_id, address, status, username, password_hash)
                 VALUES (:code, :n, :p, :e, :a, :st, :u, :ph)'
            );
            $stmt->execute([
                'code' => $agentCode,
                'n' => $name,
                'p' => $phone,
                'e' => $email,
                'a' => $address,
                'st' => ($body['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
                'u' => $username,
                'ph' => $hash,
            ]);
        } catch (\PDOException $ex) {
            if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
                $msg = (string) ($ex->errorInfo[2] ?? '');
                if (str_contains($msg, 'agent_code') || str_contains($msg, 'uq_agents_agent_code')) {
                    Response::error('This agent code is already in use', 409);
                }
                Response::error('Duplicate phone, email, or agent code', 409);
            }
            throw $ex;
        }
        $id = (int) $pdo->lastInsertId();
        $codeLine = "Customer signup code: {$agentCode} (share this so new customers join your deliveries).";
        Response::json([
            'agent' => [
                'id' => $id,
                'agent_code' => $agentCode,
                'name' => $name,
                'phone_number' => $phone,
                'email_id' => $email,
                'status' => ($body['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ],
            'mock_dispatch' => [
                'email' => [
                    'to' => $email,
                    'subject' => 'Kaalaivanakam — Agent account credentials',
                    'body' => $passwordIn !== ''
                        ? "Welcome {$name}. Sign in at Kaalaivanakam with email {$email} and the password you set.\n{$codeLine}"
                        : "Welcome {$name}. Sign in with email {$email} and this password: {$plainPassword}.\n{$codeLine}",
                ],
                'sms' => [
                    'to' => '+91' . $phone,
                    'body' => $passwordIn !== ''
                        ? "Kaalaivanakam: Sign in with {$email} and your chosen password. Code for customers: {$agentCode}"
                        : "Kaalaivanakam: {$email} / {$plainPassword}. Customer code: {$agentCode}",
                ],
            ],
        ], 201);
    }

    public function update(array $params): void
    {
        AuthMiddleware::requireBearer('super_admin');
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Invalid agent id', 400);
        }
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }
        $status = $body['status'] ?? null;
        if ($status !== 'active' && $status !== 'inactive') {
            Response::error('status must be active or inactive', 400);
        }
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE agents SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $id]);
        Response::json(['ok' => true, 'id' => $id, 'status' => $status]);
    }

    public function delete(array $params): void
    {
        AuthMiddleware::requireBearer('super_admin');
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Invalid agent id', 400);
        }
        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            $del = $pdo->prepare('DELETE FROM agents WHERE id = :id');
            $del->execute(['id' => $id]);
            if ($del->rowCount() === 0) {
                $pdo->rollBack();
                Response::error('Agent not found', 404);
            }
            AgentIdResequenceService::resequence($pdo);
            $pdo->commit();
        } catch (\PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($ex->errorInfo[1] ?? 0) === 1451) {
                Response::error(
                    'Cannot delete this agent: related records still exist (e.g. subscriptions). '
                    . 'Deactivate the agent instead.',
                    409,
                    'foreign_key_violation',
                );
            }
            throw $ex;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        Response::json(['ok' => true]);
    }

    /** Uppercase 8-char code; retries on rare collision. */
    private static function generateUniqueAgentCode(\PDO $pdo): string
    {
        for ($t = 0; $t < 24; $t++) {
            $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
            $chk = $pdo->prepare('SELECT 1 FROM agents WHERE agent_code = :c LIMIT 1');
            $chk->execute(['c' => $code]);
            if ($chk->fetch() === false) {
                return $code;
            }
        }
        throw new \RuntimeException('Could not allocate a unique agent code');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function areasForAgent(\PDO $pdo, int $agentId): array
    {
        $sql = 'SELECT ar.id, ar.name, ar.slug
                FROM agent_areas aa
                INNER JOIN areas ar ON ar.id = aa.area_id
                WHERE aa.agent_id = :aid
                ORDER BY ar.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['aid' => $agentId]);
        return $stmt->fetchAll();
    }
}
