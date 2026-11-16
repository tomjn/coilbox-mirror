<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\B64;
use Mirror\CatalogueStore;
use Mirror\Config;
use Mirror\Crypto;
use Mirror\Deadline;
use Mirror\HttpResponse;
use Mirror\Json;
use Mirror\Lock;
use Mirror\Paths;
use Mirror\Sync;
use Mirror\Tests\Support\FakeHttpClient;
use Mirror\Tests\Support\Signer;
use Mirror\Tests\Support\TempMirror;
use PHPUnit\Framework\TestCase;

final class SyncCatalogueTest extends TestCase
{
    private const NOW = 1795000000;
    private const HUB = 'https://hub.example';

    private Paths $paths;
    private FakeHttpClient $http;
    private CatalogueStore $store;
    private Config $config;
    /** @var list<string> */
    private array $log = [];
    /** @var array{secret: string, public: string} */
    private array $hubKey;

    protected function setUp(): void
    {
        $this->paths = TempMirror::create();
        $this->http = new FakeHttpClient();
        $this->hubKey = Signer::keypair(1);
        $mirror = Signer::keypair(8);
        $this->config = Config::fromArray([
            'mirror_id' => 'm1',
            'secret_key' => B64::encode($mirror['secret']),
            'hub_url' => self::HUB,
            'hub_keys' => [B64::encode($this->hubKey['public'])],
            'partial_lifetime' => 3600,
        ]);
        $this->store = new CatalogueStore($this->paths, $this->config);
    }

    protected function tearDown(): void
    {
        TempMirror::destroy($this->paths);
    }

    private function sync(): Sync
    {
        return new Sync(
            $this->paths,
            $this->config,
            $this->store,
            $this->http,
            new Deadline(0, static fn (): float => 0.0),
            static fn (): int => self::NOW,
            function (string $line): void {
                $this->log[] = $line;
            },
        );
    }

    /** @param list<array<string, mixed>> $removed */
    private function doc(int $seq, array $removed = [], array $archives = []): array
    {
        $key = B64::encode(Signer::keypair(9)['public']);
        return [
            'v' => 1,
            'seq' => $seq,
            'generated_at' => self::NOW,
            'mirrors' => [
                ['id' => 'm1', 'base_url' => 'https://m1.example', 'public_key' => $key],
                ['id' => 'm2', 'base_url' => 'https://m2.example', 'public_key' => $key],
                ['id' => 'm3', 'base_url' => 'https://m3.example', 'public_key' => $key],
            ],
            'archives' => $archives,
            'removed' => $removed,
        ];
    }

    private function serve(string $jsonUrl, string $sigUrl, array $doc, ?string $secret = null): void
    {
        $json = Json::encode($doc);
        $this->http->on($jsonUrl, new HttpResponse(200, $json));
        $this->http->on($sigUrl, new HttpResponse(200, Signer::sign(Crypto::CTX_CATALOGUE, $json, $secret ?? $this->hubKey['secret'])));
    }

    private function serveHub(array $doc): void
    {
        $this->serve(self::HUB . '/api/v1/catalogue', self::HUB . '/api/v1/catalogue.sig', $doc);
    }

    private function servePeer(string $id, array $doc, ?string $secret = null): void
    {
        $this->serve("https://$id.example/catalogue.json", "https://$id.example/catalogue.sig", $doc, $secret);
    }

    /** Holds a catalogue at the given seq, as if an earlier sync had accepted it. */
    private function hold(int $seq, array $removed = []): void
    {
        $this->serveHub($this->doc($seq, $removed));
        $this->assertSame('hub', $this->sync()->refreshCatalogue());
    }

    private function put(string $path, string $bytes = 'data', ?int $mtime = null): void
    {
        file_put_contents($this->paths->root() . '/' . $path, $bytes);
        if ($mtime !== null) {
            touch($this->paths->root() . '/' . $path, $mtime);
        }
    }

    public function test_new_hub_catalogue_is_accepted(): void
    {
        $this->serveHub($this->doc(5));
        $this->assertSame('hub', $this->sync()->refreshCatalogue());
        $this->assertSame(5, $this->store->heldSeq());
    }

    public function test_hub_not_newer_does_not_ask_peers(): void
    {
        $this->hold(5);
        $this->servePeer('m2', $this->doc(6));
        $this->assertNull($this->sync()->refreshCatalogue());
        $this->assertSame(5, $this->store->heldSeq());
    }

    public function test_hub_down_falls_back_to_peer(): void
    {
        $this->hold(5);
        $this->http->on(self::HUB . '/api/v1/catalogue', new HttpResponse(0, ''));
        $this->servePeer('m2', $this->doc(6));
        $this->assertSame('peer:m2', $this->sync()->refreshCatalogue());
        $this->assertSame(6, $this->store->heldSeq());
        $this->assertContains('catalogue from hub failed: status 0', $this->log);
    }

    public function test_hub_html_500_keeps_held_catalogue_and_tries_peers(): void
    {
        $this->hold(5);
        $this->http->on(self::HUB . '/api/v1/catalogue', new HttpResponse(500, '<html>Server error</html>'));
        $this->http->on(self::HUB . '/api/v1/catalogue.sig', new HttpResponse(500, '<html>Server error</html>'));
        $this->assertNull($this->sync()->refreshCatalogue());
        $this->assertSame(5, $this->store->heldSeq());

        $this->servePeer('m2', $this->doc(6));
        $this->assertSame('peer:m2', $this->sync()->refreshCatalogue());
        $this->assertSame(6, $this->store->heldSeq());
    }

    public function test_hub_html_200_keeps_held_catalogue_and_tries_peers(): void
    {
        $this->hold(5);
        $this->http->on(self::HUB . '/api/v1/catalogue', new HttpResponse(200, '<html>Maintenance</html>'));
        $this->http->on(self::HUB . '/api/v1/catalogue.sig', new HttpResponse(200, '<html>Maintenance</html>'));
        $this->servePeer('m2', $this->doc(6));
        $this->assertSame('peer:m2', $this->sync()->refreshCatalogue());
        $this->assertSame(6, $this->store->heldSeq());
    }

    public function test_hub_truncated_catalogue_keeps_held_catalogue_and_tries_peers(): void
    {
        $this->hold(5);
        $full = Json::encode($this->doc(6));
        $this->http->on(self::HUB . '/api/v1/catalogue', new HttpResponse(200, substr($full, 0, intdiv(strlen($full), 2))));
        $this->http->on(self::HUB . '/api/v1/catalogue.sig', new HttpResponse(200, Signer::sign(Crypto::CTX_CATALOGUE, $full, $this->hubKey['secret'])));
        $this->servePeer('m2', $this->doc(6));
        $this->assertSame('peer:m2', $this->sync()->refreshCatalogue());
        $this->assertSame(6, $this->store->heldSeq());
    }

    public function test_peer_with_bad_signature_is_skipped_for_next_peer(): void
    {
        $this->hold(5);
        $this->http->on(self::HUB . '/api/v1/catalogue', new HttpResponse(0, ''));
        $this->servePeer('m2', $this->doc(6), Signer::keypair(2)['secret']);
        $this->servePeer('m3', $this->doc(6));
        $this->assertSame('peer:m3', $this->sync()->refreshCatalogue());
        $this->assertContains('catalogue from peer:m2 rejected: bad_signature', $this->log);
    }

    public function test_removal_deletes_by_hash_and_by_filename_any_case(): void
    {
        $this->put('maps/by_hash.sd7', 'one');
        $this->put('maps/Mixed_Case.sd7', 'two');
        $this->put('games/by_hash_game.sdz', 'three');
        $this->put('maps/keep.sd7', 'four');
        $this->put('.partial/sync-' . hash('sha256', 'three'), 'half');
        $this->hold(5, [
            ['sha256' => hash('sha256', 'one'), 'filename' => 'other.sd7', 'reason' => 'withdrawn', 'removed_at' => 1],
            ['sha256' => str_repeat('0', 64), 'filename' => 'mixed_case.SD7', 'reason' => 'withdrawn', 'removed_at' => 1],
            ['sha256' => hash('sha256', 'three'), 'filename' => 'gone.sdz', 'reason' => 'withdrawn', 'removed_at' => 1],
        ]);

        $deleted = $this->sync()->applyRemovals();
        sort($deleted);
        $this->assertSame(['games/by_hash_game.sdz', 'maps/Mixed_Case.sd7', 'maps/by_hash.sd7'], $deleted);
        $this->assertFileExists($this->paths->maps() . '/keep.sd7');
        $this->assertFileDoesNotExist($this->paths->maps() . '/by_hash.sd7');
        $this->assertFileDoesNotExist($this->paths->maps() . '/Mixed_Case.sd7');
        $this->assertFileDoesNotExist($this->paths->games() . '/by_hash_game.sdz');
        $this->assertFileDoesNotExist($this->paths->partial() . '/sync-' . hash('sha256', 'three'));
    }

    public function test_files_not_in_catalogue_are_kept(): void
    {
        $this->put('maps/unknown.sd7');
        $this->hold(5);
        $this->assertSame([], $this->sync()->applyRemovals());
        $this->assertFileExists($this->paths->maps() . '/unknown.sd7');
    }

    public function test_old_partials_deleted_and_fresh_kept(): void
    {
        $this->put('.partial/sync-old', 'x', self::NOW - 3601);
        $this->put('.partial/upload-old', 'x', self::NOW - 7200);
        $this->put('.partial/sync-fresh', 'x', self::NOW - 10);
        $this->put('.partial/.htaccess', 'Deny from all', self::NOW - 99999);

        $deleted = $this->sync()->cleanPartials();
        sort($deleted);
        $this->assertSame(['sync-old', 'upload-old'], $deleted);
        $this->assertFileExists($this->paths->partial() . '/sync-fresh');
        $this->assertFileExists($this->paths->partial() . '/.htaccess');
    }

    public function test_second_run_while_locked_exits_zero_without_work(): void
    {
        $lock = Lock::acquire($this->paths->state() . '/sync.lock', false);
        $this->assertNotNull($lock);
        $this->serveHub($this->doc(5));

        $this->assertSame(0, $this->sync()->run());
        $this->assertSame(['another sync is running'], $this->log);
        $this->assertNull($this->store->held());
        $this->assertSame([], $this->http->posts);
        $lock->release();
    }

    public function test_run_sends_sync_report_listing_unknown_files(): void
    {
        $this->put('maps/stranger.sd7', 'strange');
        $this->serveHub($this->doc(5));
        $this->http->on(self::HUB . '/api/v1/mirrors/report', new HttpResponse(200, '{}'));

        $this->assertSame(0, $this->sync()->run());

        $this->assertCount(1, $this->http->posts);
        [$url, $body] = $this->http->posts[0];
        $this->assertSame(self::HUB . '/api/v1/mirrors/report', $url);
        $envelope = json_decode($body, true);
        $payloadJson = B64::decode($envelope['payload']);
        $this->assertTrue(Crypto::verify(Crypto::CTX_REPORT, $payloadJson, B64::decode($envelope['sig']), $this->config->publicKey));
        $payload = json_decode($payloadJson, true);
        $this->assertSame('sync', $payload['event']);
        $this->assertSame(5, $payload['catalogue_seq']);
        $this->assertSame('stranger.sd7', $payload['unknown'][0]['filename']);
        $this->assertSame(hash('sha256', 'strange'), $payload['unknown'][0]['sha256']);
    }

    public function test_run_returns_one_when_the_report_fails(): void
    {
        $this->serveHub($this->doc(5));
        $this->assertSame(1, $this->sync()->run());
    }

    public function test_run_returns_one_when_no_catalogue_is_held(): void
    {
        $this->http->on(self::HUB . '/api/v1/mirrors/report', new HttpResponse(200, '{}'));
        $this->assertSame(1, $this->sync()->run());
        $this->assertCount(1, $this->http->posts);
    }
}
