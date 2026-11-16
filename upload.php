<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Mirror\CatalogueStore;
use Mirror\Config;
use Mirror\ConfigError;
use Mirror\Paths;
use Mirror\Request;
use Mirror\Response;
use Mirror\StreamHttpClient;
use Mirror\UploadHandler;

// Error messages go to the server log. The response never carries them.
try {
    $paths = new Paths(__DIR__);
    $config = Config::load($paths);
    $handler = new UploadHandler(
        $paths,
        $config,
        new CatalogueStore($paths, $config),
        new StreamHttpClient(),
        static fn (): int => time(),
    );
    $handler->handle(Request::fromGlobals())->send();
} catch (ConfigError $e) {
    error_log('mirror upload.php: ' . $e->getMessage());
    (new Response(503, ['error' => 'not_configured']))->send();
} catch (\Throwable $e) {
    error_log('mirror upload.php: ' . $e->getMessage());
    (new Response(500, ['error' => 'internal_error']))->send();
}
