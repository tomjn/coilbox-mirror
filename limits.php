<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Mirror\Limits;
use Mirror\Response;

// Tells the hub and uploaders how large one chunk can be. It needs no config and holds no secrets.
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        $response = new Response(405, ['error' => 'method_not_allowed']);
    } else {
        $max = Limits::fromIni();
        $response = $max === null
            ? new Response(503, ['error' => 'uploads_disabled'])
            : new Response(200, ['v' => 1, 'max_chunk_bytes' => $max]);
    }
    $response->send();
} catch (\Throwable $e) {
    error_log('mirror limits.php: ' . $e->getMessage());
    (new Response(500, ['error' => 'internal_error']))->send();
}
