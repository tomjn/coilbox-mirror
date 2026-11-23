<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Mirror\Config;
use Mirror\ConfigError;
use Mirror\Identity;
use Mirror\Paths;
use Mirror\Response;

// Tells the hub this mirror's ID, address and public key, and signs the hub's challenge to prove it holds the key.
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        $response = new Response(405, ['error' => 'method_not_allowed']);
    } else {
        $paths = new Paths(__DIR__);
        try {
            $config = is_file($paths->config()) ? Config::load($paths) : null;
        } catch (ConfigError $e) {
            error_log('mirror identity.php: ' . $e->getMessage());
            $config = null;
        }
        $response = Identity::respond($config, $_GET['challenge'] ?? null);
    }
    $response->send();
} catch (\Throwable $e) {
    error_log('mirror identity.php: ' . $e->getMessage());
    (new Response(500, ['error' => 'internal_error']))->send();
}
