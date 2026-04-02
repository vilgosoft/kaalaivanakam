<?php

declare(strict_types=1);

/**
 * Runtime configuration is driven by `api/config/config.php`, which reads
 * environment variables (from `api/.env` via `bootstrap.php` or your server).
 *
 * Copy `api/.env.example` to `api/.env` and set:
 *   PAPERKAARAN_DB_HOST, PAPERKAARAN_DB_PORT, PAPERKAARAN_DB_NAME,
 *   PAPERKAARAN_DB_USER, PAPERKAARAN_DB_PASS
 *
 * Or set a full DSN: PAPERKAARAN_DB_DSN=mysql:host=...;dbname=...;charset=utf8mb4
 *
 * Verify: GET /v1/health
 */
return [];
