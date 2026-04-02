<?php

declare(strict_types=1);

namespace Paperkaaran\Controllers;

use Paperkaaran\Utils\Response;

final class HomeController
{
    /** GET / — quick discovery when opening the PHP server root in a browser. */
    public function index(): void
    {
        Response::json([
            'name' => 'Kaalaivanakam API',
            'version' => 'v1',
            'health' => '/v1/health',
        ]);
    }
}
