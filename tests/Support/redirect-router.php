<?php

declare(strict_types=1);

// Router for the header tests. /start redirects once to /final, which answers with its own headers.
// Run with `php -S host:port -t docroot redirect-router.php`.

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/start') {
    header('Location: /final', true, 302);
    header('X-Stage: redirect');
    header('Content-Type: text/html');
    return;
}

header('X-Stage: final');
header('Content-Type: application/octet-stream');
echo 'done';
