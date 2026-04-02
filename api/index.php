<?php

declare(strict_types=1);

/**
 * Hostinger (and similar): visiting /api/ with no index would return 403 (no directory listing).
 * The real entrypoint is api/public/index.php, reached via site root /v1/* rewrite.
 * Redirect browsers to a useful JSON endpoint.
 */
header('Location: /v1/health', true, 302);
header('Cache-Control: no-store');
exit;
