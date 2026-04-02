<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Middleware\AuthMiddleware;
use Paperkaaran\Utils\Response;

/** Agent self-service: list and update own service areas (super admin cannot map areas). */
final class AgentAreaController
{
    public function index(): void
    {
        $auth = AuthMiddleware::requireBearer('agent');
        $agentId = $auth['sub'];
        $pdo = Database::pdo();
        Response::json(['areas' => $this->areasForAgent($pdo, $agentId)]);
    }

    /** POST /v1/agent/areas — replace this agent's area assignments. */
    public function save(): void
    {
        $auth = AuthMiddleware::requireBearer('agent');
        $agentId = $auth['sub'];
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }
        $ids = $body['area_ids'] ?? null;
        if (!is_array($ids)) {
            Response::error('area_ids array required', 400);
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM agent_areas WHERE agent_id = :aid')->execute(['aid' => $agentId]);
            $ins = $pdo->prepare('INSERT INTO agent_areas (agent_id, area_id) VALUES (:aid, :arid)');
            foreach ($ids as $ar) {
                $arid = (int) $ar;
                if ($arid <= 0) {
                    continue;
                }
                $ins->execute(['aid' => $agentId, 'arid' => $arid]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Response::error('Failed to save areas', 500);
        }
        Response::json(['ok' => true, 'areas' => $this->areasForAgent($pdo, $agentId)]);
    }

    /** POST /v1/agent/catalog/areas — create a neighborhood and assign it to this agent. */
    public function createCatalog(): void
    {
        $auth = AuthMiddleware::requireBearer('agent');
        $agentId = $auth['sub'];
        $raw = file_get_contents('php://input') ?: '';
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('Invalid JSON body', 400);
        }
        $name = isset($body['name']) ? trim((string) $body['name']) : '';
        if ($name === '' || mb_strlen($name) > 128) {
            Response::error('name required (1–128 characters)', 400);
        }
        $pdo = Database::pdo();
        $baseSlug = self::slugify($name);
        $slug = $baseSlug;
        $n = 1;
        $chk = $pdo->prepare('SELECT id FROM areas WHERE slug = :s LIMIT 1');
        while (true) {
            $chk->execute(['s' => $slug]);
            if ($chk->fetch() === false) {
                break;
            }
            $slug = $baseSlug . '-' . ++$n;
        }
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT INTO areas (name, slug) VALUES (:n, :s)');
            $ins->execute(['n' => $name, 's' => $slug]);
            $areaId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO agent_areas (agent_id, area_id) VALUES (:a, :r)')->execute([
                'a' => $agentId,
                'r' => $areaId,
            ]);
            $pdo->commit();
        } catch (\Throwable) {
            $pdo->rollBack();
            Response::error('Failed to create area', 500);
        }
        Response::json([
            'area' => ['id' => $areaId, 'name' => $name, 'slug' => $slug],
        ], 201);
    }

    /** DELETE /v1/agent/catalog/areas/{id} — remove neighborhood if safe (single agent or unused). */
    public function deleteCatalog(array $params): void
    {
        $auth = AuthMiddleware::requireBearer('agent');
        $agentId = (int) $auth['sub'];
        $areaId = (int) ($params['id'] ?? 0);
        if ($areaId <= 0) {
            Response::error('Invalid area id', 400);
        }
        $pdo = Database::pdo();
        $exists = $pdo->prepare('SELECT id FROM areas WHERE id = :id LIMIT 1');
        $exists->execute(['id' => $areaId]);
        if ($exists->fetch() === false) {
            Response::error('Area not found', 404);
        }
        $cntStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT agent_id) AS c FROM agent_areas WHERE area_id = :a'
        );
        $cntStmt->execute(['a' => $areaId]);
        $cntRow = $cntStmt->fetch();
        $n = (int) ($cntRow['c'] ?? 0);
        if ($n > 1) {
            Response::error(
                'This area is covered by more than one agent and cannot be removed from the catalog.',
                409,
                'area_in_use',
            );
        }
        if ($n === 1) {
            $who = $pdo->prepare('SELECT agent_id FROM agent_areas WHERE area_id = :a LIMIT 1');
            $who->execute(['a' => $areaId]);
            $row = $who->fetch();
            if ($row === false || (int) $row['agent_id'] !== $agentId) {
                Response::error(
                    'Only the agent who alone covers this area can delete it.',
                    403,
                    'forbidden',
                );
            }
        }
        $pdo->prepare('DELETE FROM areas WHERE id = :id')->execute(['id' => $areaId]);
        Response::json(['ok' => true]);
    }

    private static function slugify(string $name): string
    {
        $s = mb_strtolower(trim($name), 'UTF-8');
        $s = preg_replace('/\s+/u', '-', $s) ?? '';
        $s = preg_replace('/[^\p{L}\p{N}-]+/u', '', $s) ?? '';
        $s = trim($s, '-');
        if ($s === '') {
            $s = 'area';
        }
        if (mb_strlen($s) > 150) {
            $s = mb_substr($s, 0, 150, 'UTF-8');
            $s = rtrim($s, '-');
        }
        return $s;
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
