<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Middleware\AuthMiddleware;
use Paperkaaran\Utils\Response;

final class AgentProductController
{
    public function index(): void
    {
        $auth = AuthMiddleware::requireBearer('agent');
        $agentId = $auth['sub'];
        $pdo = Database::pdo();
        $sql = 'SELECT ap.id, ap.product_id, p.type, p.title, ap.daily_price, ap.monthly_subscription_price
                FROM agent_products ap
                INNER JOIN products p ON p.id = ap.product_id
                WHERE ap.agent_id = :aid
                ORDER BY p.type, p.title';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['aid' => $agentId]);
        Response::json(['products' => $stmt->fetchAll()]);
    }

    public function upsert(): void
    {
        $auth = AuthMiddleware::requireBearer('agent');
        $agentId = $auth['sub'];
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }
        $productId = (int) ($body['product_id'] ?? 0);
        $daily = $body['daily_price'] ?? null;
        $monthly = $body['monthly_subscription_price'] ?? null;
        if ($productId <= 0 || $daily === null || $monthly === null) {
            Response::error('product_id, daily_price, monthly_subscription_price required', 400);
        }
        $pdo = Database::pdo();
        $chk = $pdo->prepare('SELECT id FROM products WHERE id = :id LIMIT 1');
        $chk->execute(['id' => $productId]);
        if ($chk->fetch() === false) {
            Response::error('Unknown product_id', 400);
        }
        $sql = 'INSERT INTO agent_products (agent_id, product_id, daily_price, monthly_subscription_price)
                VALUES (:aid, :pid, :d, :m)
                ON DUPLICATE KEY UPDATE daily_price = VALUES(daily_price), monthly_subscription_price = VALUES(monthly_subscription_price)';
        $pdo->prepare($sql)->execute([
            'aid' => $agentId,
            'pid' => $productId,
            'd' => $daily,
            'm' => $monthly,
        ]);
        Response::json(['ok' => true]);
    }

    /** POST /v1/agent/catalog/products — add a master title (newspaper or magazine / book). */
    public function createMaster(): void
    {
        $auth = AuthMiddleware::requireBearer('agent');
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }
        $title = isset($body['title']) ? trim((string) $body['title']) : '';
        $type = isset($body['type']) ? (string) $body['type'] : '';
        if ($title === '' || mb_strlen($title) > 255) {
            Response::error('title required (1–255 characters)', 400);
        }
        if ($type !== 'newspaper' && $type !== 'book') {
            Response::error('type must be newspaper or book', 400);
        }
        $pdo = Database::pdo();
        try {
            $stmt = $pdo->prepare('INSERT INTO products (type, title) VALUES (:t, :ti)');
            $stmt->execute(['t' => $type, 'ti' => $title]);
            $id = (int) $pdo->lastInsertId();
        } catch (\Throwable) {
            Response::error('Failed to create product', 500);
        }
        Response::json(['product' => ['id' => $id, 'type' => $type, 'title' => $title]], 201);
    }

    /** DELETE /v1/agent/catalog/products/{id} — remove master title when safe. */
    public function deleteMaster(array $params): void
    {
        $auth = AuthMiddleware::requireBearer('agent');
        $agentId = (int) $auth['sub'];
        $productId = (int) ($params['id'] ?? 0);
        if ($productId <= 0) {
            Response::error('Invalid product id', 400);
        }
        $pdo = Database::pdo();
        $exists = $pdo->prepare('SELECT id FROM products WHERE id = :id LIMIT 1');
        $exists->execute(['id' => $productId]);
        if ($exists->fetch() === false) {
            Response::error('Product not found', 404);
        }
        $sub = $pdo->prepare('SELECT 1 FROM subscription_items WHERE product_id = :p LIMIT 1');
        $sub->execute(['p' => $productId]);
        if ($sub->fetch() !== false) {
            Response::error(
                'This title is on a subscription and cannot be deleted.',
                409,
                'product_in_use',
            );
        }
        $cntStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT agent_id) AS c FROM agent_products WHERE product_id = :p'
        );
        $cntStmt->execute(['p' => $productId]);
        $cntRow = $cntStmt->fetch();
        $n = (int) ($cntRow['c'] ?? 0);
        if ($n > 1) {
            Response::error(
                'Other agents offer this title; it cannot be removed from the shared catalog.',
                409,
                'product_shared',
            );
        }
        if ($n === 1) {
            $who = $pdo->prepare('SELECT agent_id FROM agent_products WHERE product_id = :p LIMIT 1');
            $who->execute(['p' => $productId]);
            $row = $who->fetch();
            if ($row === false || (int) $row['agent_id'] !== $agentId) {
                Response::error(
                    'Only the agent who alone offers this title can delete it.',
                    403,
                    'forbidden',
                );
            }
        }
        $pdo->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $productId]);
        Response::json(['ok' => true]);
    }
}
