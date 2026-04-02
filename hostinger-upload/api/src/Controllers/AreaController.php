<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Utils\Response;

final class AreaController
{
    /** Public list for service discovery (no auth). */
    public function index(): void
    {
        $pdo = Database::pdo();
        $rows = $pdo->query('SELECT id, name, slug FROM areas ORDER BY name')->fetchAll();
        Response::json(['areas' => $rows]);
    }
}
