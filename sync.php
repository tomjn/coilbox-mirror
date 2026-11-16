<?php

declare(strict_types=1);

// Run from cron, never from the web.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Forbidden\n";
    exit;
}

require __DIR__ . '/src/bootstrap.php';

use Mirror\CatalogueStore;
use Mirror\Config;
use Mirror\ConfigError;
use Mirror\Deadline;
use Mirror\Paths;
use Mirror\StreamHttpClient;
use Mirror\Sync;

$verbose = in_array('--verbose', $argv, true);

// Cron emails anything a job prints, so a quiet run must print nothing. Sync reports every event through one
// callback with no severity, so this file names the lines that mean "all is well" and treats every other line
// as a failure. A new kind of log line is therefore shown until it is added here.
$routine = static fn (string $line): bool => $line === 'another sync is running'
    || str_starts_with($line, 'catalogue accepted from ')
    || str_starts_with($line, 'fetched ')
    || str_starts_with($line, 'removed ')
    || str_ends_with($line, ' is already present');

$log = static function (string $line) use ($verbose, $routine): void {
    if ($verbose) {
        fwrite(STDOUT, $line . "\n");
    } elseif (!$routine($line)) {
        fwrite(STDERR, $line . "\n");
    }
};

try {
    $paths = new Paths(__DIR__);
    $config = Config::load($paths);
    $sync = new Sync(
        $paths,
        $config,
        new CatalogueStore($paths, $config),
        new StreamHttpClient(),
        Deadline::fromIni(),
        static fn (): int => time(),
        $log,
    );
    $code = $sync->run();
} catch (ConfigError $e) {
    fwrite(STDERR, 'Config problem in private/config.php: ' . $e->getMessage() . "\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Sync failed: ' . $e->getMessage() . "\n");
    exit(1);
}

// A failing exit code with no failure line would leave cron mail empty, so say it.
if ($code !== 0 && !$verbose) {
    fwrite(STDERR, "Sync finished with exit code $code\n");
}
exit($code);
