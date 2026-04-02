<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Middleware\AuthMiddleware;
use Paperkaaran\Utils\Response;

final class AgentCustomerController
{
    public function index(): void
    {
        $auth = AuthMiddleware::requireBearer('agent');
        $agentId = (int) $auth['sub'];
        $pdo = Database::pdo();
        $sql = 'SELECT DISTINCT u.id, u.name, u.phone, u.email_id, u.address, u.area_id, u.latitude, u.longitude,
                       ar.name AS area_name
                FROM users u
                INNER JOIN areas ar ON ar.id = u.area_id
                INNER JOIN agent_areas aa ON aa.area_id = u.area_id AND aa.agent_id = :aid
                ORDER BY u.name, u.id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['aid' => $agentId]);
        /** @var list<array<string, mixed>> $customers */
        $customers = $stmt->fetchAll();

        $subStmt = $pdo->prepare(
            'SELECT s.id, s.user_id, s.status, s.total_amount, s.created_at, s.payment_gateway_order_id, i.invoice_number
             FROM subscriptions s
             LEFT JOIN invoices i ON i.subscription_id = s.id
             WHERE s.agent_id = :aid
             ORDER BY s.id DESC'
        );
        $subStmt->execute(['aid' => $agentId]);
        /** @var list<array<string, mixed>> $subs */
        $subs = $subStmt->fetchAll();

        $linesBySub = [];
        if ($subs !== []) {
            $subIds = array_map(static fn (array $s): int => (int) $s['id'], $subs);
            $placeholders = implode(',', array_fill(0, count($subIds), '?'));
            $lineStmt = $pdo->prepare(
                "SELECT si.subscription_id, si.quantity, si.start_date, si.end_date, si.line_total, p.title AS product_title
                 FROM subscription_items si
                 INNER JOIN products p ON p.id = si.product_id
                 WHERE si.subscription_id IN ($placeholders)
                 ORDER BY si.id ASC"
            );
            $lineStmt->execute($subIds);
            /** @var list<array<string, mixed>> $lines */
            $lines = $lineStmt->fetchAll();
            foreach ($lines as $ln) {
                $sid = (int) $ln['subscription_id'];
                if (!isset($linesBySub[$sid])) {
                    $linesBySub[$sid] = [];
                }
                $linesBySub[$sid][] = [
                    'product_title' => $ln['product_title'],
                    'quantity' => (int) $ln['quantity'],
                    'start_date' => $ln['start_date'],
                    'end_date' => $ln['end_date'],
                    'line_total' => $ln['line_total'],
                ];
            }
        }

        $subsByUser = [];
        foreach ($subs as $s) {
            $uid = (int) $s['user_id'];
            $sid = (int) $s['id'];
            if (!isset($subsByUser[$uid])) {
                $subsByUser[$uid] = [];
            }
            $subsByUser[$uid][] = [
                'id' => $sid,
                'status' => $s['status'],
                'total_amount' => $s['total_amount'],
                'created_at' => $s['created_at'],
                'payment_gateway_order_id' => $s['payment_gateway_order_id'],
                'invoice_number' => $s['invoice_number'],
                'lines' => $linesBySub[$sid] ?? [],
            ];
        }

        foreach ($customers as &$c) {
            $uid = (int) $c['id'];
            $c['subscriptions'] = $subsByUser[$uid] ?? [];
        }
        unset($c);

        Response::json(['customers' => $customers]);
    }

    /** PATCH /v1/agent/customers/{id} — edit profile + map coords for a customer you cover. */
    public function update(array $params): void
    {
        $auth = AuthMiddleware::requireBearer('agent');
        $agentId = (int) $auth['sub'];
        $userId = (int) ($params['id'] ?? 0);
        if ($userId <= 0) {
            Response::error('Invalid customer id', 400);
        }

        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }

        $pdo = Database::pdo();
        if (!self::agentCanManageCustomer($pdo, $agentId, $userId)) {
            Response::error('Customer not found or not in your coverage', 404);
        }

        $fields = [];
        $bind = ['id' => $userId];

        if (array_key_exists('name', $body)) {
            $fields[] = 'name = :name';
            $bind['name'] = trim((string) $body['name']) === '' ? null : trim((string) $body['name']);
        }
        if (array_key_exists('phone', $body)) {
            $p = trim((string) $body['phone']);
            $fields[] = 'phone = :phone';
            $bind['phone'] = $p === '' ? null : $p;
        }
        if (array_key_exists('email_id', $body)) {
            $em = strtolower(trim((string) $body['email_id']));
            if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                Response::error('Valid email_id is required when updating email', 400);
            }
            $fields[] = 'email_id = :email_id';
            $bind['email_id'] = $em;
        }
        if (array_key_exists('address', $body)) {
            $fields[] = 'address = :address';
            $bind['address'] = trim((string) $body['address']) === '' ? null : trim((string) $body['address']);
        }
        if (array_key_exists('latitude', $body)) {
            $lat = $body['latitude'];
            $fields[] = 'latitude = :latitude';
            $bind['latitude'] = $lat === null || $lat === '' ? null : $lat;
        }
        if (array_key_exists('longitude', $body)) {
            $lng = $body['longitude'];
            $fields[] = 'longitude = :longitude';
            $bind['longitude'] = $lng === null || $lng === '' ? null : $lng;
        }
        if (array_key_exists('area_id', $body)) {
            $aid = (int) $body['area_id'];
            if ($aid <= 0) {
                Response::error('area_id must be a positive id', 400);
            }
            if (!self::agentCoversArea($pdo, $agentId, $aid)) {
                Response::error('You can only assign areas you deliver in', 403);
            }
            $chk = $pdo->prepare('SELECT id FROM areas WHERE id = :id LIMIT 1');
            $chk->execute(['id' => $aid]);
            if ($chk->fetch() === false) {
                Response::error('Invalid area', 400);
            }
            $fields[] = 'area_id = :area_id';
            $bind['area_id'] = $aid;
        }
        if (array_key_exists('password', $body)) {
            $pw = (string) $body['password'];
            if ($pw !== '') {
                if (strlen($pw) < 8) {
                    Response::error('password must be at least 8 characters', 400);
                }
                $fields[] = 'password_hash = :password_hash';
                $bind['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
            }
        }

        if ($fields === []) {
            Response::error('No fields to update', 400);
        }

        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
        try {
            $pdo->prepare($sql)->execute($bind);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                Response::error('Email or phone already in use', 409);
            }
            throw $e;
        }

        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, u.phone, u.email_id, u.address, u.area_id, u.latitude, u.longitude, ar.name AS area_name
             FROM users u
             LEFT JOIN areas ar ON ar.id = u.area_id
             WHERE u.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        Response::json(['customer' => $row]);
    }

    /** DELETE /v1/agent/customers/{id} — remove customer; subscriptions cascade. */
    public function destroy(array $params): void
    {
        $auth = AuthMiddleware::requireBearer('agent');
        $agentId = (int) $auth['sub'];
        $userId = (int) ($params['id'] ?? 0);
        if ($userId <= 0) {
            Response::error('Invalid customer id', 400);
        }

        $pdo = Database::pdo();
        if (!self::agentCanManageCustomer($pdo, $agentId, $userId)) {
            Response::error('Customer not found or not in your coverage', 404);
        }

        $del = $pdo->prepare('DELETE FROM users WHERE id = :id LIMIT 1');
        $del->execute(['id' => $userId]);
        if ($del->rowCount() === 0) {
            Response::error('Customer not found', 404);
        }

        Response::json(['ok' => true]);
    }

    private static function agentCanManageCustomer(\PDO $pdo, int $agentId, int $userId): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM users u WHERE u.id = :uid AND (
              (u.area_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM agent_areas aa WHERE aa.agent_id = :aid AND aa.area_id = u.area_id
              ))
              OR EXISTS (
                SELECT 1 FROM subscriptions s WHERE s.user_id = u.id AND s.agent_id = :aid
              )
            ) LIMIT 1'
        );
        $stmt->execute(['aid' => $agentId, 'uid' => $userId]);
        return $stmt->fetch() !== false;
    }

    private static function agentCoversArea(\PDO $pdo, int $agentId, int $areaId): bool
    {
        $s = $pdo->prepare('SELECT 1 FROM agent_areas WHERE agent_id = :a AND area_id = :ar LIMIT 1');
        $s->execute(['a' => $agentId, 'ar' => $areaId]);
        return $s->fetch() !== false;
    }
}
