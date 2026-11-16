<?php

declare(strict_types=1);

// Writes the signed example files described in PROTOCOL.md. Usage: php bin/make-fixtures.php [outdir]
// The output is the same every run. The keys come from fixed seeds, the times are fixed, Ed25519
// signatures are deterministic, and all JSON goes through Json::encode. The keys are for tests only.

require __DIR__ . '/../src/bootstrap.php';

use Mirror\B64;
use Mirror\Config;
use Mirror\Crypto;
use Mirror\Json;
use Mirror\Limits;
use Mirror\Report;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$outDir = rtrim($argv[1] ?? __DIR__ . '/../fixtures', '/');
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create $outDir\n");
    exit(1);
}

/** @return array{seed: string, secret: string, public: string} */
$keypair = static function (int $seedByte): array {
    $seed = str_repeat(chr($seedByte), SODIUM_CRYPTO_SIGN_SEEDBYTES);
    $pair = sodium_crypto_sign_seed_keypair($seed);
    return [
        'seed' => $seed,
        'secret' => sodium_crypto_sign_secretkey($pair),
        'public' => sodium_crypto_sign_publickey($pair),
    ];
};

$hub = $keypair(1);
$rotated = $keypair(2);
$mirror = $keypair(3);
$other = $keypair(4);

$write = static function (string $name, string $bytes) use ($outDir): void {
    if (file_put_contents("$outDir/$name", $bytes) === false) {
        fwrite(STDERR, "Cannot write $outDir/$name\n");
        exit(1);
    }
};

// Fixed times, in Unix seconds. 1794787200 is 2026-11-16 00:00:00 UTC.
$generatedAt = 1794787200;

// The archives. Their bytes are short fixed strings so the hashes can be checked by hand.
$mapBytes = "coilbox mirror fixture: catalogue map\n";
$gameBytes = "coilbox mirror fixture: catalogue game\n";
$newBytes = "coilbox mirror fixture: map being uploaded\n";
$removedBytes = "coilbox mirror fixture: removed map\n";

$mapName = 'Test Map (v1.2) é.sd7';
$gameName = 'Test Game v1.sdz';
$newName = 'New Map (v1.0) é.sd7';
$removedName = 'Bad Map.sd7';

$mirrorId = 'jj-darkstars';

$write('keys.json', Json::encode([
    'note' => 'These keys are for tests only. Never use them anywhere real. Each seed is 32 bytes of one repeated byte: 0x01, 0x02, 0x03 and 0x04.',
    'hub_seed' => B64::encode($hub['seed']),
    'hub_secret_key' => B64::encode($hub['secret']),
    'hub_public_key' => B64::encode($hub['public']),
    'rotated_hub_seed' => B64::encode($rotated['seed']),
    'rotated_hub_secret_key' => B64::encode($rotated['secret']),
    'rotated_hub_public_key' => B64::encode($rotated['public']),
    'mirror_id' => $mirrorId,
    'mirror_seed' => B64::encode($mirror['seed']),
    'mirror_secret_key' => B64::encode($mirror['secret']),
    'mirror_public_key' => B64::encode($mirror['public']),
    'other_mirror_public_key' => B64::encode($other['public']),
]));

// Upload token for the file being uploaded.
$tokenPayload = [
    'v' => 1,
    'mirror' => $mirrorId,
    'upload' => 'up-0001',
    'sha256' => hash('sha256', $newBytes),
    'size' => strlen($newBytes),
    'filename' => $newName,
    'kind' => 'map',
    'start_by' => $generatedAt + 3600,
];
$tokenJson = Json::encode($tokenPayload);
$write('token.payload.json', $tokenJson);
$write('token.txt', B64::encode($tokenJson) . '.' . B64::encode(Crypto::sign(Crypto::CTX_TOKEN, $tokenJson, $hub['secret'])));

// Catalogue, signed by the hub key.
$catalogue = [
    'v' => 1,
    'seq' => 1,
    'generated_at' => $generatedAt,
    'mirrors' => [
        ['id' => $mirrorId, 'base_url' => 'https://jj.darkstars.co.uk', 'public_key' => B64::encode($mirror['public'])],
        ['id' => 'example-two', 'base_url' => 'https://mirror.example.org', 'public_key' => B64::encode($other['public'])],
    ],
    'archives' => [
        [
            'filename' => $mapName, 'kind' => 'map', 'sha256' => hash('sha256', $mapBytes), 'size' => strlen($mapBytes),
            'internal_name' => 'Test Map', 'version' => 'v1.2', 'shortname' => 'testmap',
            'author' => 'A. Mapper', 'description' => 'A small map used in the protocol fixtures.',
        ],
        [
            'filename' => $gameName, 'kind' => 'game', 'sha256' => hash('sha256', $gameBytes), 'size' => strlen($gameBytes),
            'internal_name' => 'Test Game', 'version' => 'v1', 'shortname' => null,
            'author' => null, 'description' => null,
        ],
    ],
    'removed' => [
        ['sha256' => hash('sha256', $removedBytes), 'filename' => $removedName, 'reason' => 'withdrawn by the author', 'removed_at' => $generatedAt - 86400],
    ],
];
$catalogueJson = Json::encode($catalogue);
$write('catalogue.json', $catalogueJson);
$write('catalogue.sig', B64::encode(Crypto::sign(Crypto::CTX_CATALOGUE, $catalogueJson, $hub['secret'])));

// Rotated catalogue. The rotated key signs it, and the old hub key endorses that key in next_key.
$rotatedCatalogue = $catalogue;
$rotatedCatalogue['seq'] = 2;
$rotatedCatalogue['generated_at'] = $generatedAt + 3600;
$rotatedCatalogue['next_key'] = [
    'public_key' => B64::encode($rotated['public']),
    'sig' => B64::encode(Crypto::sign(Crypto::CTX_HUB_KEY, $rotated['public'], $hub['secret'])),
];
$rotatedJson = Json::encode($rotatedCatalogue);
$write('catalogue-rotated.json', $rotatedJson);
$write('catalogue-rotated.sig', B64::encode(Crypto::sign(Crypto::CTX_CATALOGUE, $rotatedJson, $rotated['secret'])));

// The mirror's listing, in the order the mirror writes it: by kind, then by filename in byte order.
$entry = static fn (string $name, string $kind, string $bytes, int $mtime): array => [
    'filename' => $name, 'kind' => $kind, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'mtime' => $mtime,
];
$files = [
    $entry($mapName, 'map', $mapBytes, $generatedAt - 7200),
    $entry($gameName, 'game', $gameBytes, $generatedAt - 7200),
    $entry($newName, 'map', $newBytes, $generatedAt + 30),
];
usort($files, static fn (array $a, array $b) => [$a['kind'], $a['filename']] <=> [$b['kind'], $b['filename']]);
$listing = ['v' => 1, 'generated_at' => $generatedAt + 60, 'files' => $files];
$write('listing.json', Json::encode($listing));

// Reports. Both use the mirror key and the first catalogue, so the new map is unknown.
$config = Config::fromArray([
    'mirror_id' => $mirrorId,
    'secret_key' => B64::encode($mirror['secret']),
    'hub_url' => 'https://hub.example',
    'hub_keys' => [B64::encode($hub['public'])],
    'partial_lifetime' => 86400,
]);
$report = static fn (string $event, ?string $uploadId, int $now): string => Report::body(
    Report::payload($config, $catalogue, $listing, $event, $uploadId, $now),
    $mirror['secret'],
);
$write('report-sync.json', $report('sync', null, $generatedAt + 120));
$write('report-upload.json', $report('upload', $tokenPayload['upload'], $generatedAt + 60));

$write('limits.json', Json::encode(['v' => 1, 'max_chunk_bytes' => Limits::maxChunkBytes('512M', '512M', true)]));
