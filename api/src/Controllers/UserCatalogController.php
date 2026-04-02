<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Middleware\AuthMiddleware;
use Paperkaaran\Services\SubscriptionCheckoutService;
use Paperkaaran\Utils\Response;

final class UserCatalogController
{
    public function index(): void
    {
        $auth = AuthMiddleware::requireBearer('user');
        $userId = $auth['sub'];
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, area_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if ($user === false) {
            Response::error('User not found', 404);
        }
        $areaId = $user['area_id'] !== null ? (int) $user['area_id'] : null;
        if ($areaId === null || $areaId <= 0) {
            Response::error('Set your service area in profile to load catalog', 422);
        }
        $service = new SubscriptionCheckoutService();
        $agentId = $service->getAgentIdForUser($userId);
        if ($agentId === null) {
            Response::error('No active agent for your area', 404);
        }
        $sql = 'SELECT p.id AS product_id, p.type, p.title,
                       ap.daily_price, ap.monthly_subscription_price
                FROM agent_products ap
                INNER JOIN products p ON p.id = ap.product_id
                WHERE ap.agent_id = :aid
                ORDER BY p.type, p.title';
        $q = $pdo->prepare($sql);
        $q->execute(['aid' => $agentId]);
        Response::json([
            'agent_id' => $agentId,
            'area_id' => $areaId,
            'items' => $q->fetchAll(),
        ]);
    }
}
