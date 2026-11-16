<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\B64;
use Mirror\CatalogueRejected;
use Mirror\CatalogueStore;
use Mirror\Config;
use Mirror\Crypto;
use Mirror\Json;
use Mirror\Listing;
use Mirror\Token;
use Mirror\Tests\Support\TempMirror;
use PHPUnit\Framework\TestCase;

final class FixturesTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures';

    private static function read(string $name): string
    {
        $bytes = file_get_contents(self::DIR . '/' . $name);
        self::assertIsString($bytes, "fixtures/$name is missing");
        return $bytes;
    }

    /** @return array<string, mixed> */
    private static function readJson(string $name): array
    {
        $doc = Json::decodeObject(self::read($name));
        self::assertIsArray($doc, "fixtures/$name is not a JSON object");
        return $doc;
    }

    private static function key(string $name): string
    {
        $keys = self::readJson('keys.json');
        return B64::decode($keys[$name]);
    }

    public function test_regenerated_fixtures_match_committed_bytes(): void
    {
        $out = sys_get_temp_dir() . '/coilbox-fixtures-' . bin2hex(random_bytes(8));
        $script = __DIR__ . '/../../bin/make-fixtures.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($out) . ' 2>&1';
        exec($command, $lines, $status);
        $this->assertSame(0, $status, implode("\n", $lines));

        $committed = array_values(array_diff(scandir(self::DIR), ['.', '..']));
        $generated = array_values(array_diff(scandir($out), ['.', '..']));
        $this->assertSame($committed, $generated);
        foreach ($generated as $name) {
            $this->assertSame(self::read($name), file_get_contents("$out/$name"), "fixtures/$name differs");
            unlink("$out/$name");
        }
        rmdir($out);
    }

    public function test_token_fixture_verifies(): void
    {
        $text = self::read('token.txt');
        $payload = self::readJson('token.payload.json');
        $token = Token::verify($text, [self::key('hub_public_key')], $payload['mirror']);

        $this->assertSame($payload['upload'], $token->uploadId);
        $this->assertSame($payload['sha256'], $token->sha256);
        $this->assertSame($payload['size'], $token->size);
        $this->assertSame($payload['filename'], $token->filename);
        $this->assertSame($payload['kind'], $token->kind);
        $this->assertSame($payload['start_by'], $token->startBy);

        [$encoded] = explode('.', $text);
        $this->assertSame(Json::encode($payload), B64::decode($encoded));
    }

    public function test_both_catalogue_fixtures_verify_in_order(): void
    {
        $paths = TempMirror::create();
        try {
            $config = Config::fromArray([
                'mirror_id' => 'jj-darkstars',
                'secret_key' => self::readJson('keys.json')['mirror_secret_key'],
                'hub_url' => 'https://hub.example',
                'hub_keys' => [B64::encode(self::key('hub_public_key'))],
                'partial_lifetime' => 3600,
            ]);
            $store = new CatalogueStore($paths, $config);

            $first = $store->accept(self::read('catalogue.json'), self::read('catalogue.sig'));
            $this->assertSame(1, $first['seq']);
            $this->assertCount(2, $first['mirrors']);
            $this->assertCount(1, $first['removed']);
            $this->assertSame(['map', 'game'], array_column($first['archives'], 'kind'));

            $second = $store->accept(self::read('catalogue-rotated.json'), self::read('catalogue-rotated.sig'));
            $this->assertSame(2, $second['seq']);
            $this->assertSame(
                B64::encode(self::key('rotated_hub_public_key')),
                $second['next_key']['public_key'],
            );
            $this->assertContains(self::key('rotated_hub_public_key'), $store->trustedKeys());
        } finally {
            TempMirror::destroy($paths);
        }
    }

    public function test_rotated_catalogue_alone_is_rejected_without_the_old_key(): void
    {
        $this->expectException(CatalogueRejected::class);
        \Mirror\Catalogue::verify(
            self::read('catalogue-rotated.json'),
            self::read('catalogue-rotated.sig'),
            [self::key('mirror_public_key')],
            null,
        );
    }

    public function test_report_fixtures_verify_with_mirror_key(): void
    {
        $mirror = self::key('mirror_public_key');
        foreach (['report-sync.json' => 'sync', 'report-upload.json' => 'upload'] as $file => $event) {
            $body = self::readJson($file);
            $payload = B64::decode($body['payload']);
            $sig = B64::decode($body['sig']);
            $this->assertNotNull($payload);
            $this->assertNotNull($sig);
            $this->assertTrue(Crypto::verify(Crypto::CTX_REPORT, $payload, $sig, $mirror), $file);
            $decoded = Json::decodeObject($payload);
            $this->assertSame($event, $decoded['event']);
            $this->assertSame(1, $decoded['v']);
        }
    }

    public function test_listing_and_limits_fixtures_have_the_documented_shape(): void
    {
        $this->assertTrue(Listing::isValidDoc(self::readJson('listing.json')));
        $limits = self::readJson('limits.json');
        $this->assertSame(1, $limits['v']);
        $this->assertIsInt($limits['max_chunk_bytes']);
    }
}
