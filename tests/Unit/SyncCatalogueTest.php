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
use Mirror\Listing;
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
    /** @var array<string, mixed> */
    private array $configArray;
    private ?FakeHttpClient $peerHttp = null;
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
        $this->configArray = [
            'mirror_id' => 'm1',
            'secret_key' => B64::encode($mirror['secret']),
            'hub_url' => self::HUB,
            'hub_keys' => [B64::encode($this->hubKey['public'])],
            'partial_lifetime' => 3600,
        ];
        $this->config = Config::fromArray($this->configArray);
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
            $this->peerHttp ?? $this->http,
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

    /** Pads a catalogue with archives until its JSON is over $bytes long. */
    private function docOver(int $seq, int $bytes): array
    {
        $archives = [];
        for ($i = 0; strlen(Json::encode($this->doc($seq, [], $archives))) <= $bytes; $i++) {
            $archives[] = [
                'filename' => "filler-$i.sd7",
                'kind' => 'map',
                'sha256' => hash('sha256', "filler $i"),
                'size' => 1,
                'internal_name' => 'x',
                'version' => '1',
                'shortname' => null,
                'author' => null,
                'description' => null,
            ];
        }
        return $this->doc($seq, [], $archives);
    }

    public function test_peer_catalogue_over_twice_the_held_size_is_skipped_for_next_peer(): void
    {
        $this->hold(5);
        $heldBytes = filesize($this->paths->catalogueJson());
        $this->http->on(self::HUB . '/api/v1/catalogue', new HttpResponse(0, ''));
        $this->servePeer('m2', $this->docOver(6, 2 * $heldBytes));
        $this->servePeer('m3', $this->doc(6));

        $this->assertSame('peer:m3', $this->sync()->refreshCatalogue());
        $this->assertSame(6, $this->store->heldSeq());
        $this->assertContains('catalogue from peer:m2 failed: status 0, no answer or over ' . (2 * $heldBytes) . ' bytes', $this->log);
    }

    public function test_peer_signature_longer_than_a_signature_is_skipped(): void
    {
        $this->hold(5);
        $this->http->on(self::HUB . '/api/v1/catalogue', new HttpResponse(0, ''));
        $this->servePeer('m2', $this->doc(6));
        $this->http->on('https://m2.example/catalogue.sig', new HttpResponse(200, str_repeat('A', 87)));
        $this->servePeer('m3', $this->doc(6));

        $this->assertSame('peer:m3', $this->sync()->refreshCatalogue());
        $this->assertContains('catalogue from peer:m2 failed: signature status 0, no answer or over 86 bytes', $this->log);
    }

    public function test_hub_catalogue_has_no_size_cap(): void
    {
        $this->hold(5);
        $this->serveHub($this->docOver(6, 4 * filesize($this->paths->catalogueJson())));
        $this->assertSame('hub', $this->sync()->refreshCatalogue());
    }

    public function test_peers_are_asked_through_the_peer_client(): void
    {
        $this->hold(5);
        $this->peerHttp = new FakeHttpClient();
        $this->http->on(self::HUB . '/api/v1/catalogue', new HttpResponse(0, ''));
        $json = Json::encode($this->doc(6));
        $this->peerHttp->on('https://m2.example/catalogue.json', new HttpResponse(200, $json));
        $this->peerHttp->on('https://m2.example/catalogue.sig', new HttpResponse(200, Signer::sign(Crypto::CTX_CATALOGUE, $json, $this->hubKey['secret'])));

        $this->assertSame('peer:m2', $this->sync()->refreshCatalogue());
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

    public function test_removal_deletes_an_engine_and_its_partial(): void
    {
        $this->put('engines/recoil_1_arm64-macos.7z', 'engine');
        $this->put('.partial/sync-' . hash('sha256', 'engine'), 'half');
        $this->hold(5, [['sha256' => hash('sha256', 'engine'), 'filename' => 'recoil_1_arm64-macos.7z', 'reason' => 'withdrawn', 'removed_at' => 1]]);

        $this->assertSame(['engines/recoil_1_arm64-macos.7z'], $this->sync()->applyRemovals());
        $this->assertFileDoesNotExist($this->paths->engines() . '/recoil_1_arm64-macos.7z');
        $this->assertFileDoesNotExist($this->paths->partial() . '/sync-' . hash('sha256', 'engine'));
        $this->assertArrayNotHasKey('engines', Listing::load($this->paths));
    }

    public function test_removals_take_deleted_files_out_of_the_listing(): void
    {
        $this->put('maps/gone.sd7', 'one');
        $this->put('maps/keep.sd7', 'two');
        Listing::write($this->paths, Listing::build($this->paths, null, self::NOW));
        $this->hold(5, [['sha256' => hash('sha256', 'one'), 'filename' => 'gone.sd7', 'reason' => 'withdrawn', 'removed_at' => 1]]);

        $this->sync()->applyRemovals();

        $this->assertSame(['keep.sd7'], array_column(Listing::load($this->paths)['files'], 'filename'));
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

    public function test_upload_partial_locked_by_an_upload_is_not_deleted(): void
    {
        $this->put('.partial/upload-busy', 'x', self::NOW - 7200);
        $this->put('.partial/upload-idle', 'x', self::NOW - 7200);
        $busy = fopen($this->paths->partial() . '/upload-busy', 'c+');
        flock($busy, LOCK_EX);
        try {
            $deleted = $this->sync()->cleanPartials();
        } finally {
            flock($busy, LOCK_UN);
            fclose($busy);
        }
        $this->assertSame(['upload-idle'], $deleted);
        $this->assertFileExists($this->paths->partial() . '/upload-busy');
    }

    public function test_run_records_its_start_time_in_the_lock_file(): void
    {
        $this->serveHub($this->doc(5));
        $this->http->on(self::HUB . '/api/v1/mirrors/report', new HttpResponse(200, '{}'));
        $this->sync()->run();
        $this->assertSame((string) self::NOW, file_get_contents($this->paths->state() . '/sync.lock'));
    }

    private function holdLockStartedAt(int $startedAt): Lock
    {
        $lock = Lock::acquire($this->paths->state() . '/sync.lock', false);
        $this->assertNotNull($lock);
        $this->assertTrue($lock->write((string) $startedAt));
        return $lock;
    }

    public function test_run_held_past_the_time_limit_is_a_failure(): void
    {
        $this->config = Config::fromArray(['sync_time_limit' => 3000] + $this->configArray);
        $lock = $this->holdLockStartedAt(self::NOW - 3001);
        try {
            $this->assertSame(1, $this->sync()->run());
        } finally {
            $lock->release();
        }
        $this->assertSame(['sync has been running for over 3000 seconds'], $this->log);
        $this->assertFalse(Sync::isRoutine($this->log[0]));
    }

    public function test_run_held_within_the_time_limit_is_routine(): void
    {
        $this->config = Config::fromArray(['sync_time_limit' => 3000] + $this->configArray);
        $lock = $this->holdLockStartedAt(self::NOW - 3000);
        try {
            $this->assertSame(0, $this->sync()->run());
        } finally {
            $lock->release();
        }
        $this->assertSame(['another sync is running'], $this->log);
    }

    public function test_held_lock_is_routine_when_no_time_limit_is_set(): void
    {
        $lock = $this->holdLockStartedAt(self::NOW - 999999);
        try {
            $this->assertSame(0, $this->sync()->run());
        } finally {
            $lock->release();
        }
        $this->assertSame(['another sync is running'], $this->log);
    }

    public function test_run_returns_one_when_a_phase_fails(): void
    {
        $this->serveHub($this->doc(5));
        $this->http->on(self::HUB . '/api/v1/mirrors/report', new HttpResponse(200, '{}'));
        // A folder where disk.lock should be makes every phase that takes the disk lock throw.
        mkdir($this->paths->state() . '/disk.lock');
        set_error_handler(static fn(): bool => true);
        try {
            $code = $this->sync()->run();
        } finally {
            restore_error_handler();
        }
        $this->assertSame(1, $code);
        $this->assertNotEmpty(array_filter($this->log, static fn (string $l) => str_starts_with($l, 'listing rebuild failed')));
        $this->assertCount(1, $this->http->posts);
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

    public function test_report_says_when_the_hub_has_not_approved_the_mirror(): void
    {
        $this->http->on(self::HUB . '/api/v1/mirrors/report', new HttpResponse(403, '{"error":"unknown_mirror"}'));

        $this->assertFalse($this->sync()->report());
        $this->assertSame(['sync report failed: not approved by the hub yet'], $this->log);
    }
}
