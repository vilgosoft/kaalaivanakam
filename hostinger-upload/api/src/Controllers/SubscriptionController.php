<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Middleware\AuthMiddleware;
use Paperkaaran\Services\SubscriptionCheckoutService;
use Paperkaaran\Utils\Response;
use RuntimeException;

final class SubscriptionController
{
    public function checkout(): void
    {
        $auth = AuthMiddleware::requireBearer('user');
        $userId = $auth['sub'];
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }
        $items = $body['items'] ?? null;
        if (!is_array($items)) {
            Response::error('items array is required', 400);
        }
        $service = new SubscriptionCheckoutService();
        try {
            $result = $service->checkout($userId, $items);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
        Response::json($result, 201);
    }

    public function list(): void
    {
        $auth = AuthMiddleware::requireBearer('user');
        $userId = $auth['sub'];
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT s.id, s.user_id, s.agent_id, s.status, s.total_amount, s.payment_gateway_order_id,
                    s.created_at, s.updated_at,
                    i.id AS invoice_id, i.invoice_number
             FROM subscriptions s
             LEFT JOIN invoices i ON i.subscription_id = s.id
             WHERE s.user_id = :uid
             ORDER BY s.id DESC'
        );
        $stmt->execute(['uid' => $userId]);
        Response::json(['subscriptions' => $stmt->fetchAll()]);
    }

    public function mockPay(array $params): void
    {
        $auth = AuthMiddleware::requireBearer('user');
        $userId = $auth['sub'];
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Invalid subscription id', 400);
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, user_id, status, total_amount FROM subscriptions WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $sub = $stmt->fetch();
        if ($sub === false || (int) $sub['user_id'] !== $userId) {
            Response::error('Subscription not found', 404);
        }
        if ($sub['status'] !== 'pending_payment') {
            Response::error('Subscription is not awaiting payment', 409);
        }
        $invNo = '';
        $invRowId = 0;
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare(
                "UPDATE subscriptions SET status = 'active', updated_at = NOW() WHERE id = :id"
            );
            $upd->execute(['id' => $id]);
            $tempInvoiceNo = 'TMP-' . bin2hex(random_bytes(12));
            $ins = $pdo->prepare(
                'INSERT INTO invoices (subscription_id, invoice_number, amount, snapshot_json)
                 VALUES (:sid, :num, :amt, :snap)'
            );
            $snap = json_encode([
                'mock_gateway' => 'razorpay_mock',
                'payment_id' => 'pay_mock_' . bin2hex(random_bytes(6)),
                'method' => 'upi',
                'captured_at' => gmdate('c'),
            ], JSON_THROW_ON_ERROR);
            $ins->execute([
                'sid' => $id,
                'num' => $tempInvoiceNo,
                'amt' => $sub['total_amount'],
                'snap' => $snap,
            ]);
            $invRowId = (int) $pdo->lastInsertId();
            $invNo = 'INV-' . date('Ymd') . '-' . str_pad((string) $invRowId, 6, '0', STR_PAD_LEFT);
            $fixNo = $pdo->prepare('UPDATE invoices SET invoice_number = :n WHERE id = :id');
            $fixNo->execute(['n' => $invNo, 'id' => $invRowId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Response::error('Payment processing failed', 500);
        }
        Response::json([
            'subscription_id' => $id,
            'status' => 'active',
            'invoice_id' => $invRowId,
            'invoice_number' => $invNo,
            'mock_payment_response' => [
                'razorpay_payment_id' => 'pay_mock_' . bin2hex(random_bytes(4)),
                'razorpay_order_id' => (string) ($sub['payment_gateway_order_id'] ?? ''),
                'status' => 'captured',
            ],
        ]);
    }
}
