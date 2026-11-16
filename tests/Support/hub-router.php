<?php

declare(strict_types=1);

// Router for the fake hub in the integration tests. Run with `php -S host:port -t docroot hub-router.php`.
// The docroot holds catalogue.json and catalogue.sig. Each report the hub receives is appended to reports.jsonl.

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$root = $_SERVER['DOCUMENT_ROOT'];

$serve = static function (string $file, string $type) use ($root): void {
    $bytes = @file_get_contents("$root/$file");
    if ($bytes === false) {
        http_response_code(404);
        return;
    }
    header("Content-Type: $type");
    echo $bytes;
};

if ($path === '/api/v1/catalogue') {
    $serve('catalogue.json', 'application/json');
} elseif ($path === '/api/v1/catalogue.sig') {
    $serve('catalogue.sig', 'text/plain');
} elseif ($path === '/api/v1/mirrors/report' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    file_put_contents("$root/reports.jsonl", file_get_contents('php://input') . "\n", FILE_APPEND | LOCK_EX);
    header('Content-Type: application/json');
    echo '{}';
} else {
    http_response_code(404);
}
