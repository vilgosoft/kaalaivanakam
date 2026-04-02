<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Db\Database;
use Paperkaaran\Utils\Response;

/** Master product catalog (titles) for agents / reference UIs. */
final class ProductController
{
    public function index(): void
    {
        $pdo = Database::pdo();
        $rows = $pdo->query('SELECT id, type, title FROM products ORDER BY type, title')->fetchAll();
        Response::json(['products' => $rows]);
    }
}
