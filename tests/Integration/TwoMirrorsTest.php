<?php

declare(strict_types=1);

namespace Mirror\Tests\Integration;

use Mirror\B64;
use Mirror\CatalogueStore;
use Mirror\Config;
use Mirror\Crypto;
use Mirror\Deadline;
use Mirror\Filename;
use Mirror\Json;
use Mirror\Listing;
use Mirror\Paths;
use Mirror\StreamHttpClient;
use Mirror\Sync;
use Mirror\Tests\Support\Server;
use Mirror\Tests\Support\Signer;
use Mirror\Tests\Support\TempMirror;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs a fake hub and two real mirror folders on PHP's built-in server, and talks to them over HTTP. */
final class TwoMirrorsTest extends TestCase
{
    private const MAP = 'Test Map (v1.2) é.sd7';
    private const ENGINE = 'recoil_2026.09.01-137-g15f18d5+mac_arm64-macos.7z';
    private const GAME_ID = '9f2c4b1ad07e5386c1f4a92b6e8d0375';
    private const PROJECT = __DIR__ . '/../..';
    private const DEPLOYED = ['upload.php', 'limits.php', 'sync.php', '.htaccess', 'src'];

    private string $base;
    private string $hubDir;
    /** @var array<string, Server> keyed by hub, a and b */
    private array $servers = [];
    /** @var array{secret: string, public: string} */
    private array $hubKey;
    /** @var array<string, array{secret: string, public: string}> */
    private array $mirrorKeys;
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->base = TempMirror::create()->root();
        $this->hubKey = Signer::keypair(1);
        $this->mirrorKeys = ['a' => Signer::keypair(8), 'b' => Signer::keypair(9)];

        $this->hubDir = $this->base . '/hub';
        mkdir($this->hubDir);
        $this->servers['hub'] = Server::start($this->hubDir, __DIR__ . '/../Support/hub-router.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        TempMirror::destroy(new Paths($this->base));
    }

    private function startMirror(string $id, bool $configured = true): void
    {
        $dir = "{$this->base}/$id";
        foreach (['maps', 'games', 'engines', 'replays', 'tracks', '.partial', 'private'] as $sub) {
            mkdir("$dir/$sub", 0777, true);
            copy(self::PROJECT . "/$sub/.htaccess", "$dir/$sub/.htaccess");
        }
        foreach (self::DEPLOYED as $name) {
            self::copyTree(self::PROJECT . "/$name", "$dir/$name");
        }
        if ($configured) {
            $config = [
                'mirror_id' => $id,
                'secret_key' => B64::encode($this->mirrorKeys[$id]['secret']),
                'hub_url' => $this->servers['hub']->url(),
                'hub_keys' => [B64::encode($this->hubKey['public'])],
                'partial_lifetime' => 3600,
            ];
            file_put_contents("$dir/private/config.php", '<?php return ' . var_export($config, true) . ';');
        }
        $this->servers[$id] = Server::start($dir, __DIR__ . '/../Support/mirror-router.php');
    }

    private function startBothMirrors(): void
    {
        $this->startMirror('a');
        $this->startMirror('b');
    }

    private static function copyTree(string $from, string $to): void
    {
        if (is_file($from)) {
            copy($from, $to);
            return;
        }
        if (!is_dir($to)) {
            mkdir($to, 0777, true);
        }
        foreach (scandir($from) as $name) {
            if ($name !== '.' && $name !== '..') {
                self::copyTree("$from/$name", "$to/$name");
            }
        }
    }

    private function paths(string $id): Paths
    {
        return new Paths("{$this->base}/$id");
    }

    private function mirrorUrl(string $id): string
    {
        return $this->servers[$id]->url();
    }

    /** @return array<string, mixed> */
    private function archive(string $bytes): array
    {
        return [
            'shortname' => null,
            'author' => null,
            'description' => null,
            'filename' => self::MAP,
            'kind' => 'map',
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
            'internal_name' => 'Test Map',
            'version' => 'v1.2',
        ];
    }

    /** @return array{0: string, 1: string} the catalogue JSON and its signature */
    private function signedCatalogue(int $seq, array $archives, array $removed = [], array $lists = []): array
    {
        $mirrors = [];
        foreach (['a', 'b'] as $id) {
            $mirrors[] = [
                'id' => $id,
                'base_url' => $this->mirrorUrl($id),
                'public_key' => B64::encode($this->mirrorKeys[$id]['public']),
            ];
        }
        $json = Json::encode([
            'v' => 1,
            'seq' => $seq,
            'generated_at' => time(),
            'mirrors' => $mirrors,
            'archives' => $archives,
            'removed' => $removed,
        ] + $lists);
        return [$json, Signer::sign(Crypto::CTX_CATALOGUE, $json, $this->hubKey['secret'])];
    }

    private function publishToHub(int $seq, array $archives, array $removed = [], array $lists = []): void
    {
        [$json, $sig] = $this->signedCatalogue($seq, $archives, $removed, $lists);
        file_put_contents("{$this->hubDir}/catalogue.json", $json);
        file_put_contents("{$this->hubDir}/catalogue.sig", $sig);
    }

    /** @return array<string, mixed> an engines entry as the hub writes it */
    private function engine(string $bytes): array
    {
        return [
            'filename' => self::ENGINE,
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
            'version' => '2026.09.01-137-g15f18d5+mac',
            'platform' => 'arm64-macos',
            'note' => 'Tests the Metal path.',
        ];
    }

    private function putEngine(string $id, string $bytes): void
    {
        $paths = $this->paths($id);
        file_put_contents($paths->engines() . '/' . self::ENGINE, $bytes);
        Listing::write($paths, Listing::build($paths, null, time()));
    }

    private function putMap(string $id, string $bytes): void
    {
        $paths = $this->paths($id);
        file_put_contents($paths->maps() . '/' . self::MAP, $bytes);
        Listing::write($paths, Listing::build($paths, null, time()));
    }

    private function sync(string $id): int
    {
        $paths = $this->paths($id);
        $config = Config::load($paths);
        $sync = new Sync(
            $paths,
            $config,
            new CatalogueStore($paths, $config),
            new StreamHttpClient(followRedirects: true),
            new StreamHttpClient(followRedirects: false),
            Deadline::fromIni($config->syncTimeLimit),
            static fn (): int => time(),
            function (string $line): void {
                $this->log[] = $line;
            },
        );
        return $sync->run();
    }

    private function store(string $id): CatalogueStore
    {
        $paths = $this->paths($id);
        return new CatalogueStore($paths, Config::load($paths));
    }

    /** @return list<array<string, mixed>> the payload of every report the hub received */
    private function hubReports(): array
    {
        $file = "{$this->hubDir}/reports.jsonl";
        if (!is_file($file)) {
            return [];
        }
        $payloads = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $envelope = json_decode($line, true);
            $payloads[] = json_decode(B64::decode($envelope['payload']), true);
        }
        return $payloads;
    }

    /** @return array{0: int, 1: string} status and body, without following failures as errors */
    private function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $http = ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true];
        if ($body !== null) {
            $http['content'] = $body;
        }
        $text = file_get_contents($url, false, stream_context_create(['http' => $http]));
        $this->assertNotFalse($text, "$method $url failed");
        preg_match('#^HTTP/\S+\s+(\d{3})#', http_get_last_response_headers()[0], $m);
        return [(int) $m[1], $text];
    }

    private function uploadChunk(string $token, int $offset, string $data): array
    {
        $boundary = 'cbm' . bin2hex(random_bytes(8));
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"chunk\"; filename=\"chunk\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n$data\r\n--$boundary--\r\n";
        [$status, $text] = $this->request('POST', $this->mirrorUrl('a') . '/upload.php', [
            "Content-Type: multipart/form-data; boundary=$boundary",
            "X-Mirror-Token: $token",
            "X-Mirror-Offset: $offset",
        ], $body);
        return [$status, json_decode($text, true)];
    }

    public function test_limits_php_returns_positive_chunk_size(): void
    {
        $this->startMirror('a');
        [$status, $body] = $this->request('GET', $this->mirrorUrl('a') . '/limits.php');
        $this->assertSame(200, $status);
        $decoded = json_decode($body, true);
        $this->assertSame(1, $decoded['v']);
        $this->assertGreaterThan(0, $decoded['max_chunk_bytes']);

        [$status] = $this->request('POST', $this->mirrorUrl('a') . '/limits.php', [], '');
        $this->assertSame(405, $status);
    }

    public function test_upload_in_chunks_through_upload_php_lands_in_maps(): void
    {
        $this->startBothMirrors();
        [, $limits] = $this->request('GET', $this->mirrorUrl('a') . '/limits.php');
        // Two full chunks and a short one, whatever the server's limit is, without sending more than 1 MiB at once.
        $chunkSize = min(json_decode($limits, true)['max_chunk_bytes'], 1048576);
        $bytes = random_bytes(2 * $chunkSize + 10);

        $token = Signer::token([
            'v' => 1,
            'mirror' => 'a',
            'upload' => 'up-1',
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
            'filename' => self::MAP,
            'kind' => 'map',
            'start_by' => time() + 3600,
        ], $this->hubKey['secret']);

        $offset = 0;
        foreach (str_split($bytes, $chunkSize) as $i => $chunk) {
            [$status, $body] = $this->uploadChunk($token, $offset, $chunk);
            $offset += strlen($chunk);
            if ($offset < strlen($bytes)) {
                $this->assertSame(200, $status, "chunk $i");
                $this->assertSame(['received' => $offset, 'complete' => false], $body);
            } else {
                $this->assertSame(201, $status);
                $this->assertSame(
                    ['complete' => true, 'received' => strlen($bytes), 'path' => 'maps/' . self::MAP, 'reported' => true],
                    $body,
                );
            }
        }

        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $this->paths('a')->maps() . '/' . self::MAP));
        $reports = $this->hubReports();
        $this->assertCount(1, $reports);
        $this->assertSame('upload', $reports[0]['event']);
        $this->assertSame('up-1', $reports[0]['upload']);
        $this->assertContains(hash('sha256', $bytes), $reports[0]['held']);
    }

    public function test_sync_copies_map_from_a_to_b_and_reports(): void
    {
        $this->startBothMirrors();
        $bytes = random_bytes(300000);
        $this->putMap('a', $bytes);
        $this->publishToHub(1, [$this->archive($bytes)]);

        $this->assertSame(0, $this->sync('b'), implode("\n", $this->log));

        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $this->paths('b')->maps() . '/' . self::MAP));
        $reports = $this->hubReports();
        $this->assertCount(1, $reports);
        $this->assertSame('b', $reports[0]['mirror']);
        $this->assertSame('sync', $reports[0]['event']);
        $this->assertSame([hash('sha256', $bytes)], $reports[0]['held']);
    }

    public function test_engine_upload_through_upload_php_lands_in_engines(): void
    {
        $this->startBothMirrors();
        $bytes = random_bytes(5000);
        $token = Signer::token([
            'v' => 1,
            'mirror' => 'a',
            'upload' => 'up-engine',
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
            'filename' => self::ENGINE,
            'kind' => 'engine',
            'start_by' => time() + 3600,
        ], $this->hubKey['secret']);

        [$status, $body] = $this->uploadChunk($token, 0, $bytes);

        $this->assertSame(201, $status);
        $this->assertSame('engines/' . self::ENGINE, $body['path']);
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $this->paths('a')->engines() . '/' . self::ENGINE));
        [$status, $served] = $this->request('GET', $this->mirrorUrl('a') . '/engines/' . rawurlencode(self::ENGINE));
        $this->assertSame(200, $status);
        $this->assertSame($bytes, $served);
        $report = $this->hubReports()[0];
        $this->assertSame(['map', 'game', 'engine', 'replay', 'track'], $report['kinds']);
        $this->assertSame([['filename' => self::ENGINE, 'kind' => 'engine', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]], $report['unknown']);
    }

    public function test_sync_copies_engine_from_a_to_b_and_reports_kinds(): void
    {
        $this->startBothMirrors();
        $bytes = random_bytes(300000);
        $this->putEngine('a', $bytes);
        $this->publishToHub(1, [], [], ['engines' => [$this->engine($bytes)]]);

        $this->assertSame(0, $this->sync('b'), implode("\n", $this->log));

        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $this->paths('b')->engines() . '/' . self::ENGINE));
        $report = $this->hubReports()[0];
        $this->assertSame(['map', 'game', 'engine', 'replay', 'track'], $report['kinds']);
        $this->assertSame([hash('sha256', $bytes)], $report['held']);
        $this->assertSame([], $report['unknown']);
    }

    public function test_engine_removal_deletes_from_both_mirrors(): void
    {
        $this->startBothMirrors();
        $bytes = random_bytes(1000);
        $this->putEngine('a', $bytes);
        $this->putEngine('b', $bytes);
        $this->publishToHub(1, [], [], ['engines' => [$this->engine($bytes)]]);
        $this->assertSame(0, $this->sync('a'), implode("\n", $this->log));
        $this->assertSame(0, $this->sync('b'), implode("\n", $this->log));

        $this->publishToHub(2, [], [[
            'sha256' => hash('sha256', $bytes),
            'filename' => self::ENGINE,
            'reason' => 'withdrawn',
            'removed_at' => time(),
        ]]);
        $this->assertSame(0, $this->sync('a'), implode("\n", $this->log));
        $this->assertSame(0, $this->sync('b'), implode("\n", $this->log));

        $this->assertFileDoesNotExist($this->paths('a')->engines() . '/' . self::ENGINE);
        $this->assertFileDoesNotExist($this->paths('b')->engines() . '/' . self::ENGINE);
    }

    /** A mirror on the release before engines checks a peer listing with these rules, from Listing::isValidDoc. */
    public function test_a_listing_with_engines_passes_the_previous_release_rules(): void
    {
        $this->startMirror('a');
        $this->putMap('a', 'map bytes');
        $this->putEngine('a', 'engine bytes');

        [$status, $text] = $this->request('GET', $this->mirrorUrl('a') . '/listing.json');

        $this->assertSame(200, $status);
        $doc = Json::decodeObject($text);
        $this->assertSame(1, $doc['v']);
        $this->assertIsInt($doc['generated_at']);
        $this->assertTrue(array_is_list($doc['files']));
        $this->assertSame([self::MAP], array_column($doc['files'], 'filename'));
        foreach ($doc['files'] as $file) {
            $this->assertContains($file['kind'], ['map', 'game']);
            $this->assertTrue(Filename::isValid($file['filename']));
        }
        $this->assertSame([self::ENGINE], array_column($doc['engines'], 'filename'));
    }

    /** @return array<string, array{string, string, string}> kind, folder and filename */
    public static function replayKinds(): array
    {
        return [
            'replay' => ['replay', 'replays', self::GAME_ID . '.sdfz'],
            'track' => ['track', 'tracks', self::GAME_ID . '.cbtrack'],
        ];
    }

    private function uploadToken(string $upload, string $kind, string $filename, string $bytes): string
    {
        return Signer::token([
            'v' => 1,
            'mirror' => 'a',
            'upload' => $upload,
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
            'filename' => $filename,
            'kind' => $kind,
            'start_by' => time() + 3600,
        ], $this->hubKey['secret']);
    }

    #[DataProvider('replayKinds')]
    public function test_replay_kind_is_uploaded_listed_synced_served_and_removed(string $kind, string $folder, string $filename): void
    {
        $this->startBothMirrors();
        $bytes = random_bytes(300000);
        $half = intdiv(strlen($bytes), 2);
        $sha = hash('sha256', $bytes);
        $token = $this->uploadToken("up-$kind", $kind, $filename, $bytes);

        [$status] = $this->uploadChunk($token, 0, substr($bytes, 0, $half));
        $this->assertSame(200, $status);
        [$status, $body] = $this->uploadChunk($token, $half, substr($bytes, $half));

        $this->assertSame(201, $status);
        $this->assertSame("$folder/$filename", $body['path']);
        $report = $this->hubReports()[0];
        $this->assertSame(['map', 'game', 'engine', 'replay', 'track'], $report['kinds']);
        $this->assertSame([['filename' => $filename, 'kind' => $kind, 'size' => strlen($bytes), 'sha256' => $sha]], $report['unknown']);
        [, $text] = $this->request('GET', $this->mirrorUrl('a') . '/listing.json');
        $listing = Json::decodeObject($text);
        $this->assertSame([], $listing['files']);
        $this->assertSame([$filename], array_column($listing[$folder], 'filename'));

        $entry = ['filename' => $filename, 'sha256' => $sha, 'size' => strlen($bytes)];
        $this->publishToHub(1, [], [], [$folder => [$entry]]);
        $this->assertSame(0, $this->sync('b'), implode("\n", $this->log));

        foreach (['a', 'b'] as $id) {
            [$status, $served] = $this->request('GET', $this->mirrorUrl($id) . "/$folder/$filename");
            $this->assertSame(200, $status, $id);
            $this->assertSame($bytes, $served, $id);
        }
        $report = $this->hubReports()[1];
        $this->assertSame([$sha], $report['held']);
        $this->assertSame([], $report['unknown']);

        $this->publishToHub(2, [], [['sha256' => $sha, 'filename' => $filename, 'reason' => 'withdrawn', 'removed_at' => time()]]);
        $this->assertSame(0, $this->sync('a'), implode("\n", $this->log));
        $this->assertSame(0, $this->sync('b'), implode("\n", $this->log));

        foreach (['a', 'b'] as $id) {
            $this->assertFileDoesNotExist("{$this->base}/$id/$folder/$filename");
            [, $text] = $this->request('GET', $this->mirrorUrl($id) . '/listing.json');
            $this->assertArrayNotHasKey($folder, Json::decodeObject($text), $id);
        }
    }

    #[DataProvider('replayKinds')]
    public function test_upload_with_a_name_that_is_not_a_game_id_is_refused(string $kind, string $folder, string $filename): void
    {
        $this->startMirror('a');
        $token = $this->uploadToken("up-$kind", $kind, 'match-' . $filename, 'bytes');

        [$status, $body] = $this->uploadChunk($token, 0, 'bytes');

        $this->assertSame(403, $status);
        $this->assertSame(['error' => 'bad_token'], $body);
        $this->assertSame(['.', '..', '.htaccess'], scandir("{$this->base}/a/$folder"));
    }

    /**
     * The release before replays checks a peer's listing and catalogue with the rules repeated here, from its
     * Listing::isValidDoc and Catalogue::validShape. It reads files, engines and archives, and ignores other keys.
     */
    public function test_the_previous_release_still_gets_maps_and_games_from_an_upgraded_mirror(): void
    {
        $this->startBothMirrors();
        $this->putMap('a', 'map bytes');
        file_put_contents($this->paths('a')->replays() . '/' . self::GAME_ID . '.sdfz', 'replay bytes');
        file_put_contents($this->paths('a')->tracks() . '/' . self::GAME_ID . '.cbtrack', 'track bytes');
        $this->publishToHub(1, [$this->archive('map bytes')], [], [
            'replays' => [['filename' => self::GAME_ID . '.sdfz', 'sha256' => hash('sha256', 'replay bytes'), 'size' => 12]],
            'tracks' => [['filename' => self::GAME_ID . '.cbtrack', 'sha256' => hash('sha256', 'track bytes'), 'size' => 11]],
        ]);
        $this->assertSame(0, $this->sync('a'), implode("\n", $this->log));

        [, $text] = $this->request('GET', $this->mirrorUrl('a') . '/listing.json');
        $listing = Json::decodeObject($text);
        $this->assertArrayHasKey('replays', $listing);
        $this->assertSame(1, $listing['v']);
        $this->assertIsInt($listing['generated_at']);
        $this->assertSame([self::MAP], array_column($listing['files'], 'filename'));
        foreach ($listing['files'] as $file) {
            $this->assertContains($file['kind'], ['map', 'game']);
            $this->assertTrue(Filename::isValid($file['filename']));
        }
        $this->assertArrayNotHasKey('engines', $listing);
        // A previous release allows 2 x A + 1 entries of at most 1693 bytes, plus a 66 byte wrapper, A being 1 here.
        $this->assertLessThanOrEqual(3 * 1693 + 66, strlen($text));

        [, $text] = $this->request('GET', $this->mirrorUrl('a') . '/catalogue.json');
        $catalogue = Json::decodeObject($text);
        $this->assertSame([self::MAP], array_column($catalogue['archives'], 'filename'));
        foreach ($catalogue['archives'] as $archive) {
            $this->assertContains($archive['kind'], ['map', 'game']);
        }
    }

    public function test_resumed_download_appends_with_range(): void
    {
        $this->startBothMirrors();
        $bytes = random_bytes(300000);
        $half = intdiv(strlen($bytes), 2);
        $this->putMap('a', $bytes);
        $this->publishToHub(1, [$this->archive($bytes)]);
        file_put_contents($this->paths('b')->partial() . '/sync-' . hash('sha256', $bytes), substr($bytes, 0, $half));

        $this->assertSame(0, $this->sync('b'), implode("\n", $this->log));

        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $this->paths('b')->maps() . '/' . self::MAP));
        $this->assertStringContainsString(
            'range 206 bytes=' . $half . '- /maps/' . self::MAP,
            (string) file_get_contents("{$this->base}/a-server.log"),
        );
    }

    public function test_removal_deletes_from_both_mirrors(): void
    {
        $this->startBothMirrors();
        $bytes = random_bytes(1000);
        $this->putMap('a', $bytes);
        $this->putMap('b', $bytes);
        $this->publishToHub(1, [$this->archive($bytes)]);
        $this->assertSame(0, $this->sync('a'), implode("\n", $this->log));
        $this->assertSame(0, $this->sync('b'), implode("\n", $this->log));
        $this->assertFileExists($this->paths('a')->maps() . '/' . self::MAP);

        $this->publishToHub(2, [], [[
            'sha256' => hash('sha256', $bytes),
            'filename' => self::MAP,
            'reason' => 'withdrawn',
            'removed_at' => time(),
        ]]);
        $this->assertSame(0, $this->sync('a'), implode("\n", $this->log));
        $this->assertSame(0, $this->sync('b'), implode("\n", $this->log));

        $this->assertFileDoesNotExist($this->paths('a')->maps() . '/' . self::MAP);
        $this->assertFileDoesNotExist($this->paths('b')->maps() . '/' . self::MAP);
    }

    public function test_hub_down_b_takes_catalogue_from_a(): void
    {
        $this->startBothMirrors();
        $this->publishToHub(1, []);
        $this->assertSame(0, $this->sync('b'), implode("\n", $this->log));
        $this->assertSame(1, $this->store('b')->heldSeq());

        $this->servers['hub']->stop();
        [$json, $sig] = $this->signedCatalogue(2, []);
        file_put_contents("{$this->base}/a/catalogue.json", $json);
        file_put_contents("{$this->base}/a/catalogue.sig", $sig);

        $paths = $this->paths('b');
        $config = Config::load($paths);
        $sync = new Sync(
            $paths,
            $config,
            $this->store('b'),
            new StreamHttpClient(followRedirects: true),
            new StreamHttpClient(followRedirects: false),
            Deadline::fromIni($config->syncTimeLimit),
            static fn (): int => time(),
            function (string $line): void {
                $this->log[] = $line;
            },
        );
        $this->assertSame('peer:a', $sync->refreshCatalogue());
        $this->assertSame(2, $this->store('b')->heldSeq());
    }

    public function test_sync_php_refuses_web_requests(): void
    {
        $this->startMirror('a');
        [$status] = $this->request('GET', $this->mirrorUrl('a') . '/sync.php');
        $this->assertSame(403, $status);
    }

    public function test_sync_php_from_the_cli_is_silent_on_success_and_prints_failures(): void
    {
        $this->startBothMirrors();
        $bytes = random_bytes(1000);
        $this->putMap('a', $bytes);
        $this->publishToHub(1, [$this->archive($bytes)]);

        $run = fn (string ...$args): array => $this->runSyncPhp('b', ...$args);

        [$code, $output] = $run();
        $this->assertSame(0, $code, $output);
        $this->assertSame('', $output);
        $this->assertFileExists($this->paths('b')->maps() . '/' . self::MAP);

        [$code, $output] = $run('--verbose');
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('catalogue from hub rejected: not_newer', $output);

        $this->servers['hub']->stop();
        [$code, $output] = $run();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('catalogue from hub failed: status 0', $output);
        $this->assertStringContainsString('sync report failed', $output);
    }

    public function test_sync_php_stays_silent_when_nothing_changed(): void
    {
        $this->startBothMirrors();
        $bytes = random_bytes(1000);
        $this->putMap('a', $bytes);
        $this->publishToHub(1, [$this->archive($bytes)]);

        $this->assertSame([0, ''], $this->runSyncPhp('b'));
        $this->assertSame([0, ''], $this->runSyncPhp('b'));
    }

    public function test_oversize_peer_listing_is_skipped_and_nothing_is_downloaded_from_it(): void
    {
        $this->startBothMirrors();
        $bytes = random_bytes(1000);
        $this->putMap('a', $bytes);
        $this->publishToHub(1, [$this->archive($bytes)]);
        // A valid listing padded with spaces until it is over the size the sync allows for one archive.
        $cap = Listing::maxDocBytes(3);
        $listing = (string) file_get_contents("{$this->base}/a/listing.json");
        file_put_contents("{$this->base}/a/listing.json", $listing . str_repeat(' ', $cap + 1 - strlen($listing)));

        $this->sync('b');

        $this->assertContains("listing from peer:a skipped, missing, malformed or over $cap bytes", $this->log);
        $this->assertFileDoesNotExist($this->paths('b')->maps() . '/' . self::MAP);
        $this->assertStringNotContainsString('/maps/', (string) file_get_contents("{$this->base}/a-server.log"));
    }

    /** @return array{0: int, 1: string} exit code and combined output */
    private function runSyncPhp(string $id, string ...$args): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$this->base}/$id/sync.php");
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $lines, $code);
        return [$code, implode("\n", $lines)];
    }

    public function test_missing_config_gives_503_not_configured(): void
    {
        $this->startMirror('a', false);
        [$status, $body] = $this->request('GET', $this->mirrorUrl('a') . '/upload.php', ['X-Mirror-Token: x']);
        $this->assertSame(503, $status);
        $this->assertSame(['error' => 'not_configured'], json_decode($body, true));
    }
}
