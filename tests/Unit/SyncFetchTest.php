<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\B64;
use Mirror\CatalogueStore;
use Mirror\Config;
use Mirror\Crypto;
use Mirror\Deadline;
use Mirror\DownloadResult;
use Mirror\HttpResponse;
use Mirror\Json;
use Mirror\Listing;
use Mirror\Paths;
use Mirror\Sync;
use Mirror\Tests\Support\FakeHttpClient;
use Mirror\Tests\Support\Signer;
use Mirror\Tests\Support\TempMirror;
use PHPUnit\Framework\TestCase;

final class SyncFetchTest extends TestCase
{
    private const NOW = 1795000000;
    private const HUB = 'https://hub.example';

    private Paths $paths;
    private FakeHttpClient $http;
    private CatalogueStore $store;
    private Config $config;
    /** @var list<string> */
    private array $log = [];
    private float $time = 0.0;
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

    private function sync(?Deadline $deadline = null): Sync
    {
        return new Sync(
            $this->paths,
            $this->config,
            $this->store,
            $this->http,
            $deadline ?? new Deadline(0, static fn (): float => 0.0),
            static fn (): int => self::NOW,
            function (string $line): void {
                $this->log[] = $line;
            },
        );
    }

    /** @return array<string, mixed> */
    private static function archive(string $filename, string $bytes, string $kind = 'map'): array
    {
        return [
            'filename' => $filename,
            'kind' => $kind,
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
            'internal_name' => 'x',
            'version' => '1',
            'shortname' => null,
            'author' => null,
            'description' => null,
        ];
    }

    /** Holds a catalogue listing the given archives and removals. */
    private function hold(array $archives, array $removed = []): void
    {
        $key = B64::encode(Signer::keypair(9)['public']);
        $doc = [
            'v' => 1,
            'seq' => 1,
            'generated_at' => self::NOW,
            'mirrors' => [
                ['id' => 'm1', 'base_url' => 'https://m1.example', 'public_key' => $key],
                ['id' => 'm2', 'base_url' => 'https://m2.example', 'public_key' => $key],
                ['id' => 'm3', 'base_url' => 'https://m3.example', 'public_key' => $key],
            ],
            'archives' => $archives,
            'removed' => $removed,
        ];
        $json = Json::encode($doc);
        $this->store->accept($json, Signer::sign(Crypto::CTX_CATALOGUE, $json, $this->hubKey['secret']));
    }

    /** @param list<array<string, mixed>> $archives the archives this peer says it holds */
    private function peerHas(string $id, array $archives): void
    {
        $files = array_map(static fn (array $a) => [
            'filename' => $a['filename'],
            'kind' => $a['kind'],
            'size' => $a['size'],
            'sha256' => $a['sha256'],
            'mtime' => 1795000000,
        ], $archives);
        $listing = ['v' => 1, 'generated_at' => self::NOW, 'files' => $files];
        $this->http->on("https://$id.example/listing.json", new HttpResponse(200, Json::encode($listing)));
    }

    private function peerServes(string $id, string $folder, string $filename, string $bytes): void
    {
        $this->http->on("https://$id.example/$folder/" . rawurlencode($filename), new HttpResponse(200, $bytes));
    }

    public function test_missing_archive_is_downloaded_from_peer_that_lists_it(): void
    {
        $a = self::archive('one.sd7', 'map bytes');
        $this->hold([$a]);
        $this->peerHas('m2', [$a]);
        $this->peerServes('m2', 'maps', 'one.sd7', 'map bytes');

        $this->sync()->fetchMissing();

        $this->assertSame('map bytes', file_get_contents($this->paths->maps() . '/one.sd7'));
        $this->assertSame(['https://m2.example/maps/one.sd7'], $this->http->downloads);
        $this->assertSame([$this->paths->partial() . '/sync-' . $a['sha256']], $this->http->downloadDests);
        $this->assertFileDoesNotExist($this->paths->partial() . '/sync-' . $a['sha256']);
        $this->assertSame($a['sha256'], Listing::find(Listing::load($this->paths), 'map', 'one.sd7')['sha256']);
    }

    public function test_game_archive_is_requested_from_the_games_folder(): void
    {
        $a = self::archive('rules.sdz', 'game bytes', 'game');
        $this->hold([$a]);
        $this->peerHas('m2', [$a]);
        $this->peerServes('m2', 'games', 'rules.sdz', 'game bytes');

        $this->sync()->fetchMissing();

        $this->assertSame('game bytes', file_get_contents($this->paths->games() . '/rules.sdz'));
    }

    public function test_filename_with_spaces_and_accents_is_url_encoded(): void
    {
        $name = 'Test Map (v1.2) é.sd7';
        $a = self::archive($name, 'abc');
        $this->hold([$a]);
        $this->peerHas('m2', [$a]);
        $this->peerServes('m2', 'maps', $name, 'abc');

        $this->sync()->fetchMissing();

        $this->assertSame(['https://m2.example/maps/Test%20Map%20%28v1.2%29%20%C3%A9.sd7'], $this->http->downloads);
        $this->assertFileExists($this->paths->maps() . '/' . $name);
    }

    public function test_peer_serving_wrong_bytes_is_skipped_for_next_peer(): void
    {
        $a = self::archive('one.sd7', 'good bytes');
        $this->hold([$a]);
        $this->peerHas('m2', [$a]);
        $this->peerHas('m3', [$a]);
        $this->peerServes('m2', 'maps', 'one.sd7', 'evil bytes');
        $this->peerServes('m3', 'maps', 'one.sd7', 'good bytes');

        $this->sync()->fetchMissing();

        $this->assertSame(['https://m2.example/maps/one.sd7', 'https://m3.example/maps/one.sd7'], $this->http->downloads);
        $this->assertSame('good bytes', file_get_contents($this->paths->maps() . '/one.sd7'));
        $this->assertSame([], glob($this->paths->partial() . '/sync-*'));
        $this->assertNotEmpty(array_filter($this->log, static fn (string $l) => str_contains($l, 'hash mismatch')));
    }

    public function test_only_wrong_bytes_leaves_nothing_behind(): void
    {
        $a = self::archive('one.sd7', 'good bytes');
        $this->hold([$a]);
        $this->peerHas('m2', [$a]);
        $this->peerServes('m2', 'maps', 'one.sd7', 'evil bytes');

        $this->sync()->fetchMissing();

        $this->assertFileDoesNotExist($this->paths->maps() . '/one.sd7');
        $this->assertSame([], glob($this->paths->partial() . '/sync-*'));
    }

    public function test_existing_partial_of_the_right_size_is_hashed_too(): void
    {
        $a = self::archive('one.sd7', 'good bytes');
        $this->hold([$a]);
        $this->peerHas('m2', [$a]);
        // The real client skips the request when the partial already has the expected size.
        file_put_contents($this->paths->partial() . '/sync-' . $a['sha256'], 'evil bytes');
        $this->http->onDownload('https://m2.example/maps/one.sd7', DownloadResult::Complete);

        $this->sync()->fetchMissing();

        $this->assertFileDoesNotExist($this->paths->maps() . '/one.sd7');
        $this->assertSame([], glob($this->paths->partial() . '/sync-*'));
    }

    public function test_removed_archive_is_not_fetched(): void
    {
        $a = self::archive('one.sd7', 'abc');
        $this->hold([$a], [['sha256' => $a['sha256'], 'filename' => 'one.sd7', 'reason' => 'withdrawn', 'removed_at' => 1]]);
        $this->peerHas('m2', [$a]);
        $this->peerServes('m2', 'maps', 'one.sd7', 'abc');

        $this->sync()->fetchMissing();

        $this->assertSame([], $this->http->downloads);
        $this->assertFileDoesNotExist($this->paths->maps() . '/one.sd7');
    }

    public function test_archive_already_on_disk_is_not_fetched(): void
    {
        $a = self::archive('one.sd7', 'abc');
        $this->hold([$a]);
        $this->peerHas('m2', [$a]);
        file_put_contents($this->paths->maps() . '/one.sd7', 'abc');
        Listing::write($this->paths, Listing::build($this->paths, null, self::NOW));

        $this->sync()->fetchMissing();

        $this->assertSame([], $this->http->downloads);
    }

    public function test_expired_deadline_stops_before_next_archive(): void
    {
        $a = self::archive('one.sd7', 'aaa');
        $b = self::archive('two.sd7', 'bbb');
        $this->hold([$a, $b]);
        $this->peerHas('m2', [$a, $b]);
        $this->peerServes('m2', 'maps', 'one.sd7', 'aaa');
        $this->peerServes('m2', 'maps', 'two.sd7', 'bbb');
        // The clock jumps past the limit once the first download has happened.
        $deadline = new Deadline(1, fn (): float => $this->http->downloads === [] ? 0.0 : 5.0);

        $this->sync($deadline)->fetchMissing();

        $this->assertSame(['https://m2.example/maps/one.sd7'], $this->http->downloads);
        $this->assertFileExists($this->paths->maps() . '/one.sd7');
        $this->assertFileDoesNotExist($this->paths->maps() . '/two.sd7');
    }

    public function test_existing_name_with_other_hash_is_left_alone(): void
    {
        $a = self::archive('one.sd7', 'new bytes');
        $this->hold([$a]);
        $this->peerHas('m2', [$a]);
        $this->peerServes('m2', 'maps', 'one.sd7', 'new bytes');
        Listing::write($this->paths, Listing::build($this->paths, null, self::NOW));
        file_put_contents($this->paths->maps() . '/one.sd7', 'old bytes');

        $this->sync()->fetchMissing();

        $this->assertSame('old bytes', file_get_contents($this->paths->maps() . '/one.sd7'));
        $this->assertSame([], glob($this->paths->partial() . '/sync-*'));
        $this->assertNotEmpty(array_filter($this->log, static fn (string $l) => str_contains($l, 'conflict')));
    }

    public function test_existing_name_in_another_letter_case_is_not_duplicated(): void
    {
        $a = self::archive('one.sd7', 'same bytes');
        $this->hold([$a]);
        $this->peerHas('m2', [$a]);
        $this->peerServes('m2', 'maps', 'one.sd7', 'same bytes');
        Listing::write($this->paths, Listing::build($this->paths, null, self::NOW));
        file_put_contents($this->paths->maps() . '/ONE.sd7', 'same bytes');

        $this->sync()->fetchMissing();

        $this->assertSame(['ONE.sd7'], array_values(array_diff(scandir($this->paths->maps()), ['.', '..', '.htaccess'])));
        $this->assertSame([], glob($this->paths->partial() . '/sync-*'));
    }

    public function test_stopped_download_ends_the_fetch_and_keeps_the_partial(): void
    {
        $a = self::archive('one.sd7', 'aaaa');
        $b = self::archive('two.sd7', 'bbbb');
        $this->hold([$a, $b]);
        $this->peerHas('m2', [$a, $b]);
        $this->http->onDownload('https://m2.example/maps/one.sd7', DownloadResult::Stopped, 'aa');
        $this->peerServes('m2', 'maps', 'two.sd7', 'bbbb');

        $this->sync()->fetchMissing();

        $this->assertSame(['https://m2.example/maps/one.sd7'], $this->http->downloads);
        $this->assertSame('aa', file_get_contents($this->paths->partial() . '/sync-' . $a['sha256']));
        $this->assertFileDoesNotExist($this->paths->maps() . '/one.sd7');
    }

    public function test_failed_download_deletes_the_partial_and_tries_the_next_peer(): void
    {
        $a = self::archive('one.sd7', 'good bytes');
        $this->hold([$a]);
        $this->peerHas('m2', [$a]);
        $this->peerHas('m3', [$a]);
        $this->http->onDownload('https://m2.example/maps/one.sd7', DownloadResult::Failed, 'junk');
        $this->peerServes('m3', 'maps', 'one.sd7', 'good bytes');

        $this->sync()->fetchMissing();

        $this->assertSame('good bytes', file_get_contents($this->paths->maps() . '/one.sd7'));
        $this->assertSame([], glob($this->paths->partial() . '/sync-*'));
    }

    public function test_failed_download_from_the_only_peer_leaves_no_partial(): void
    {
        $a = self::archive('one.sd7', 'good bytes');
        $this->hold([$a]);
        $this->peerHas('m2', [$a]);
        $this->http->onDownload('https://m2.example/maps/one.sd7', DownloadResult::Failed, 'junk');

        $this->sync()->fetchMissing();

        $this->assertSame([], glob($this->paths->partial() . '/sync-*'));
    }

    public function test_peer_with_a_different_hash_or_size_is_not_a_candidate(): void
    {
        $a = self::archive('one.sd7', 'good bytes');
        $this->hold([$a]);
        $otherHash = self::archive('one.sd7', 'good bytez');
        $otherSize = [...$a, 'size' => $a['size'] + 1];
        $otherKind = self::archive('one.sd7', 'good bytes', 'game');
        $this->peerHas('m2', [$otherHash]);
        $this->peerHas('m3', [$otherSize, $otherKind]);

        $this->sync()->fetchMissing();

        $this->assertSame([], $this->http->downloads);
    }

    public function test_peer_with_a_malformed_listing_is_skipped(): void
    {
        $a = self::archive('one.sd7', 'good bytes');
        $this->hold([$a]);
        $bad = ['v' => 1, 'generated_at' => self::NOW, 'files' => [
            ['filename' => 'one.sd7', 'kind' => 'map', 'size' => $a['size'], 'sha256' => $a['sha256']],
        ]];
        $this->http->on('https://m2.example/listing.json', new HttpResponse(200, Json::encode($bad)));
        $this->http->on('https://m3.example/listing.json', new HttpResponse(200, '<html>oops</html>'));
        $this->peerServes('m2', 'maps', 'one.sd7', 'good bytes');

        $this->sync()->fetchMissing();

        $this->assertSame([], $this->http->downloads);
    }

    public function test_this_mirror_is_not_asked_for_its_own_files(): void
    {
        $a = self::archive('one.sd7', 'good bytes');
        $this->hold([$a]);
        $this->peerHas('m1', [$a]);
        $this->peerServes('m1', 'maps', 'one.sd7', 'good bytes');

        $this->sync()->fetchMissing();

        $this->assertSame([], $this->http->downloads);
    }
}
