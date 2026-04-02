<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Utils\Response;

final class PublicAreaController
{
    /** GET /v1/areas/:id/agent — first active agent covering area. */
    public function resolveAgent(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Invalid area id', 400);
        }
        $pdo = Database::pdo();
        $sql = 'SELECT a.id, a.name, a.phone_number, a.email_id, a.status
                FROM agent_areas aa
                INNER JOIN agents a ON a.id = aa.agent_id AND a.status = :active
                WHERE aa.area_id = :aid
                ORDER BY a.id ASC
                LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['aid' => $id, 'active' => 'active']);
        $agent = $stmt->fetch();
        if ($agent === false) {
            Response::error('No active agent for this area', 404);
        }
        Response::json(['agent' => $agent]);
    }
}
