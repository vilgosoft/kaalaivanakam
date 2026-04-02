<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Utils\Response;
use Throwable;

final class HealthController
{
    /** GET /v1/health — verify API + MySQL (no auth). */
    public function index(): void
    {
        try {
            $pdo = Database::pdo();
            $pdo->query('SELECT 1')->fetchColumn();
            Response::json([
                'status' => 'ok',
                'database' => 'connected',
            ]);
        } catch (Throwable $e) {
            error_log('Health check DB error: ' . $e->getMessage());
            Response::json(
                [
                    'status' => 'degraded',
                    'database' => 'disconnected',
                    'message' => 'Cannot reach MySQL. Check api/.env and that the server is running.',
                ],
                503,
            );
        }
    }
}
