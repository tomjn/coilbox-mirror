<?php

declare(strict_types=1);

// Prints the link that asks the hub to add this mirror. Open it while signed in to the hub.
// setup.php shows the same link. It writes no files and sends nothing.

require __DIR__ . '/../src/bootstrap.php';

use Mirror\Config;
use Mirror\ConfigError;
use Mirror\Paths;
use Mirror\Setup;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $link = Setup::registrationLink(Config::load(new Paths(dirname(__DIR__))));
} catch (ConfigError $e) {
    fwrite(STDERR, 'Config problem in private/config.php: ' . $e->getMessage() . "\n");
    exit(1);
}
if ($link === null) {
    fwrite(STDERR, "Add base_url and mirror_name to private/config.php first. The README explains them.\n");
    exit(1);
}

echo "Open this link while signed in to the hub, check the details and send the request:\n\n$link\n";
