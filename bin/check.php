<?php

declare(strict_types=1);

// Checks the hosting setup after install. Run it from the command line:
//
//   php bin/check.php [--root=<folder>] [--url=https://mirror.example] [--probe-file=maps/<name>]
//
// With --url it also requests the mirror over HTTP to check what the web server exposes.
// It prints each problem and exits 1, or prints ok and exits 0. It writes no files.

require __DIR__ . '/../src/bootstrap.php';

use Mirror\Json;
use Mirror\Limits;
use Mirror\Paths;
use Mirror\Requirements;
use Mirror\StreamHttpClient;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$usage = "Usage: php bin/check.php [--root=<folder>] [--url=https://...] [--probe-file=maps/<name>]\n";
$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(root|url|probe-file)=(.+)\z/s', $arg, $m) !== 1) {
        fwrite(STDERR, "Unknown argument: $arg\n$usage");
        exit(2);
    }
    $options[$m[1]] = $m[2];
}
if (isset($options['probe-file']) && !isset($options['url'])) {
    fwrite(STDERR, "--probe-file needs --url.\n$usage");
    exit(2);
}

$paths = new Paths($options['root'] ?? dirname(__DIR__));

$ini = [];
foreach (['allow_url_fopen', 'file_uploads', 'upload_max_filesize', 'post_max_size'] as $name) {
    $ini[$name] = (string) ini_get($name);
}
$problems = Requirements::local($paths, $ini, get_loaded_extensions());

$webChunk = null;
if (isset($options['url'])) {
    $problems = [...$problems, ...Requirements::remote(new StreamHttpClient(), $options['url'], $options['probe-file'] ?? null, $webChunk)];
}

// The held catalogue shows how fresh this mirror's copy is.
$raw = @file_get_contents($paths->catalogueJson());
if ($raw === false) {
    echo "no catalogue held\n";
} else {
    $held = Json::decodeObject($raw);
    if (is_int($held['seq'] ?? null) && is_int($held['generated_at'] ?? null)) {
        echo 'catalogue seq ' . $held['seq'] . ', generated ' . gmdate('Y-m-d\TH:i:s\Z', $held['generated_at']) . "\n";
    } else {
        $problems[] = 'catalogue.json is held but cannot be read as a catalogue.';
    }
}

if ($webChunk !== null) {
    echo "web chunk size $webChunk bytes\n";
}

if ($problems !== []) {
    foreach ($problems as $problem) {
        echo "problem: $problem\n";
    }
    exit(1);
}

echo 'ok, chunk size ' . Limits::fromIni() . " bytes\n";
