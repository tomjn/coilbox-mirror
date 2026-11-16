<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\B64;
use Mirror\CatalogueStore;
use Mirror\Config;
use Mirror\Crypto;
use Mirror\Deadline;
use Mirror\DownloadResult;
use Mirror\HttpClient;
use Mirror\HttpResponse;
use Mirror\Json;
use Mirror\Listing;
use Mirror\Paths;
use Mirror\Request;
use Mirror\Response;
use Mirror\Tests\Support\FakeHttpClient;
use Mirror\Tests\Support\Signer;
use Mirror\Tests\Support\TempMirror;
use Mirror\UploadHandler;
use PHPUnit\Framework\TestCase;

/** A stream wrapper that creates a file the moment the handler opens the chunk, which is before it takes the lock. */
final class MarkerOnOpenStream
{
    public static string $marker = '';
    public static string $bytes = '';
    public $context;
    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        touch(self::$marker);
        $this->pos = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $out = substr(self::$bytes, $this->pos, $count);
        $this->pos += strlen($out);
        return $out;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen(self::$bytes);
    }

    public function stream_stat(): array
    {
        return ['size' => strlen(self::$bytes)];
    }

    public function stream_close(): void
    {
    }
}

final class UploadFinishTest extends TestCase
{
    private const NOW = 1000;

    private Paths $paths;
    private array $hub;
    private array $mirror;
    private FakeHttpClient $http;
    private string $body = 'abcdefghij';
    private string $errorLog = '';
    private string|false $oldErrorLog = false;

    protected function setUp(): void
    {
        $this->errorLog = tempnam(sys_get_temp_dir(), 'errlog');
        $this->oldErrorLog = ini_set('error_log', $this->errorLog);
        $this->paths = TempMirror::create();
        $this->hub = Signer::keypair(1);
        $this->mirror = Signer::keypair(8);
        $this->http = new FakeHttpClient();
        $this->http->on('https://hub.example/api/v1/mirrors/report', new HttpResponse(200, '{}'));
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->oldErrorLog === false ? '' : $this->oldErrorLog);
        @unlink($this->errorLog);
        foreach ([$this->paths->maps(), $this->paths->state() . '/used'] as $dir) {
            @chmod($dir, 0755);
        }
        TempMirror::destroy($this->paths);
    }

    private function handler(): UploadHandler
    {
        $config = Config::fromArray([
            'mirror_id' => 'm1',
            'secret_key' => B64::encode($this->mirror['secret']),
            'hub_url' => 'https://hub.example',
            'hub_keys' => [B64::encode($this->hub['public'])],
            'partial_lifetime' => 3600,
        ]);
        return new UploadHandler(
            $this->paths,
            $config,
            new CatalogueStore($this->paths, $config),
            $this->http,
            fn(): int => self::NOW,
        );
    }

    private function token(array $override = []): string
    {
        return Signer::token($override + [
            'v' => 1,
            'mirror' => 'm1',
            'upload' => 'up-1',
            'sha256' => hash('sha256', $this->body),
            'size' => strlen($this->body),
            'filename' => 'test.sd7',
            'kind' => 'map',
            'start_by' => self::NOW + 100,
        ], $this->hub['secret']);
    }

    private function handlerWith(HttpClient $http): UploadHandler
    {
        $config = Config::fromArray([
            'mirror_id' => 'm1',
            'secret_key' => B64::encode($this->mirror['secret']),
            'hub_url' => 'https://hub.example',
            'hub_keys' => [B64::encode($this->hub['public'])],
            'partial_lifetime' => 3600,
        ]);
        return new UploadHandler($this->paths, $config, new CatalogueStore($this->paths, $config), $http, fn(): int => self::NOW);
    }

    private function post(string $bytes, string $offset, ?array $file = null, ?string $token = null): Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($tmp, $bytes);
        $file ??= ['name' => 'blob', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
        $headers = ['x-mirror-token' => $token ?? $this->token(), 'x-mirror-offset' => $offset];
        $response = $this->handler()->handle(new Request('POST', $headers, $file));
        @unlink($tmp);
        return $response;
    }

    /** Sends the whole body in two chunks and returns the response to the last one. */
    private function upload(?string $token = null): Response
    {
        $this->assertSame(200, $this->post(substr($this->body, 0, 4), '0', null, $token)->status);
        return $this->post(substr($this->body, 4), '4', null, $token);
    }

    public function test_matching_upload_moves_file_and_returns_201(): void
    {
        $r = $this->upload();
        $this->assertSame(201, $r->status);
        $this->assertSame(['complete' => true, 'received' => 10, 'path' => 'maps/test.sd7', 'reported' => true], $r->body);
        $this->assertSame($this->body, file_get_contents($this->paths->maps() . '/test.sd7'));
        $this->assertFileDoesNotExist($this->paths->partial() . '/upload-up-1');
        $this->assertFileExists($this->paths->state() . '/used/up-1');
    }

    public function test_game_upload_goes_to_games_folder(): void
    {
        $r = $this->upload($this->token(['filename' => 'game.sdz', 'kind' => 'game']));
        $this->assertSame(201, $r->status);
        $this->assertSame('games/game.sdz', $r->body['path']);
        $this->assertFileExists($this->paths->games() . '/game.sdz');
    }

    public function test_hash_mismatch_deletes_partial_and_returns_422(): void
    {
        $token = $this->token(['sha256' => hash('sha256', 'something else')]);
        $r = $this->upload($token);
        $this->assertSame(422, $r->status);
        $this->assertSame(['error' => 'hash_mismatch'], $r->body);
        $this->assertFileDoesNotExist($this->paths->partial() . '/upload-up-1');
        $this->assertFileDoesNotExist($this->paths->maps() . '/test.sd7');
        $this->assertFileDoesNotExist($this->paths->state() . '/used/up-1');
        $this->assertSame([], $this->http->posts);
    }

    public function test_existing_name_in_other_case_is_refused(): void
    {
        file_put_contents($this->paths->games() . '/Game.sdz', 'original');
        $r = $this->upload($this->token(['filename' => 'game.sdz', 'kind' => 'game']));
        $this->assertSame(409, $r->status);
        $this->assertSame(['error' => 'exists'], $r->body);
        $this->assertSame('original', file_get_contents($this->paths->games() . '/Game.sdz'));
        $this->assertSame(['.', '..', 'Game.sdz'], scandir($this->paths->games()));
        $this->assertFileDoesNotExist($this->paths->partial() . '/upload-up-1');
        $this->assertFileDoesNotExist($this->paths->state() . '/used/up-1');
    }

    public function test_finished_upload_id_cannot_be_replayed(): void
    {
        $this->upload();
        $replay = $this->post('abcd', '0');
        $this->assertSame(409, $replay->status);
        $this->assertSame(['error' => 'used'], $replay->body);

        $status = $this->handler()->handle(new Request('GET', ['x-mirror-token' => $this->token()], null));
        $this->assertSame(['received' => 10, 'complete' => true], $status->body);
    }

    public function test_request_that_waited_on_the_lock_does_not_start_a_second_copy(): void
    {
        // The marker appears while the handler opens the chunk, after the first used check and before the lock.
        MarkerOnOpenStream::$marker = $this->paths->state() . '/used/up-1';
        MarkerOnOpenStream::$bytes = 'abcd';
        stream_wrapper_register('markeronopen', MarkerOnOpenStream::class);
        try {
            $file = ['name' => 'blob', 'tmp_name' => 'markeronopen://chunk', 'error' => UPLOAD_ERR_OK, 'size' => 4];
            $r = $this->post('', '0', $file);
        } finally {
            stream_wrapper_unregister('markeronopen');
        }
        $this->assertSame(409, $r->status);
        $this->assertSame(['error' => 'used'], $r->body);
        $this->assertFileDoesNotExist($this->paths->partial() . '/upload-up-1');
        $this->assertFileDoesNotExist($this->paths->maps() . '/test.sd7');
    }

    public function test_finished_file_appears_in_listing(): void
    {
        $this->upload();
        $entry = Listing::find(Listing::load($this->paths), 'map', 'test.sd7');
        $this->assertNotNull($entry);
        $this->assertSame(10, $entry['size']);
        $this->assertSame(hash('sha256', $this->body), $entry['sha256']);
    }

    public function test_report_is_sent_with_upload_event(): void
    {
        $this->upload();
        $this->assertCount(1, $this->http->posts);
        [$url, $raw] = $this->http->posts[0];
        $this->assertSame('https://hub.example/api/v1/mirrors/report', $url);

        $body = Json::decodeObject($raw);
        $payload = B64::decode($body['payload']);
        $this->assertTrue(Crypto::verify(Crypto::CTX_REPORT, $payload, B64::decode($body['sig']), $this->mirror['public']));

        $data = Json::decodeObject($payload);
        $this->assertSame('upload', $data['event']);
        $this->assertSame('up-1', $data['upload']);
        $this->assertSame('m1', $data['mirror']);
        $this->assertSame(self::NOW, $data['sent_at']);
        $this->assertContains(hash('sha256', $this->body), $data['held']);
    }

    public function test_failed_report_still_returns_201(): void
    {
        $this->http->on('https://hub.example/api/v1/mirrors/report', new HttpResponse(500, ''));
        $r = $this->upload();
        $this->assertSame(201, $r->status);
        $this->assertFalse($r->body['reported']);
        $this->assertFileExists($this->paths->maps() . '/test.sd7');
    }

    public function test_report_that_throws_gives_reported_false(): void
    {
        $http = new class implements HttpClient {
            public function get(string $url, ?int $maxBytes = null): HttpResponse
            {
                throw new \RuntimeException('hub unreachable');
            }

            public function postJson(string $url, string $body): HttpResponse
            {
                throw new \RuntimeException('hub unreachable');
            }

            public function download(string $url, string $dest, int $expectedSize, Deadline $deadline): DownloadResult
            {
                throw new \RuntimeException('hub unreachable');
            }
        };
        $this->post('abcd', '0');
        $tmp = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($tmp, 'efghij');
        $file = ['name' => 'blob', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 6];
        $r = $this->handlerWith($http)->handle(new Request('POST', ['x-mirror-token' => $this->token(), 'x-mirror-offset' => '4'], $file));
        @unlink($tmp);
        $this->assertSame(201, $r->status);
        $this->assertFalse($r->body['reported']);
    }

    public function test_report_that_throws_an_error_still_returns_201(): void
    {
        $http = new class implements HttpClient {
            public function get(string $url, ?int $maxBytes = null): HttpResponse
            {
                throw new \Error('broken client');
            }

            public function postJson(string $url, string $body): HttpResponse
            {
                throw new \Error('broken client');
            }

            public function download(string $url, string $dest, int $expectedSize, Deadline $deadline): DownloadResult
            {
                throw new \Error('broken client');
            }
        };
        $this->post('abcd', '0');
        $tmp = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($tmp, 'efghij');
        $file = ['name' => 'blob', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 6];
        $r = $this->handlerWith($http)->handle(new Request('POST', ['x-mirror-token' => $this->token(), 'x-mirror-offset' => '4'], $file));
        @unlink($tmp);
        $this->assertSame(201, $r->status);
        $this->assertFalse($r->body['reported']);
    }

    public function test_resend_to_a_full_size_partial_finishes_the_upload(): void
    {
        // A finish that died after the last byte, for example when PHP was killed while hashing, leaves this behind.
        file_put_contents($this->paths->partial() . '/upload-up-1', $this->body);
        $status = $this->handler()->handle(new Request('GET', ['x-mirror-token' => $this->token()], null));
        $this->assertSame(['received' => 10, 'complete' => false], $status->body);

        $r = $this->post('efghij', '4');

        $this->assertSame(201, $r->status);
        $this->assertTrue($r->body['complete']);
        $this->assertSame($this->body, file_get_contents($this->paths->maps() . '/test.sd7'));
        $this->assertFileDoesNotExist($this->paths->partial() . '/upload-up-1');
        $this->assertFileExists($this->paths->state() . '/used/up-1');
    }

    public function test_throwable_inside_finish_deletes_the_partial(): void
    {
        $this->assertSame(200, $this->post('abcd', '0')->status);
        // A folder where disk.lock should be makes Lock::acquire throw.
        mkdir($this->paths->state() . '/disk.lock');
        set_error_handler(static fn(): bool => true);
        try {
            $this->post('efghij', '4');
            $this->fail('Expected the finish to throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('disk.lock', $e->getMessage());
        } finally {
            restore_error_handler();
        }
        $this->assertFileDoesNotExist($this->paths->partial() . '/upload-up-1');
        $this->assertFileDoesNotExist($this->paths->maps() . '/test.sd7');
    }

    public function test_failed_listing_write_keeps_the_201_and_skips_the_report(): void
    {
        // A folder where listing.json should be makes the listing write fail.
        mkdir($this->paths->listing());
        set_error_handler(static fn(): bool => true);
        try {
            $r = $this->upload();
        } finally {
            restore_error_handler();
        }
        $this->assertSame(201, $r->status);
        $this->assertFalse($r->body['reported']);
        $this->assertSame([], $this->http->posts);
        $this->assertFileExists($this->paths->maps() . '/test.sd7');
        $this->assertStringContainsString('cannot list test.sd7', (string) file_get_contents($this->errorLog));
    }

    public function test_handle_creates_missing_state_folders(): void
    {
        TempMirror::destroy($this->paths);
        $this->paths = TempMirror::create();
        rmdir($this->paths->state() . '/used');
        rmdir($this->paths->state());
        $r = $this->upload();
        $this->assertSame(201, $r->status);
        $this->assertFileExists($this->paths->state() . '/used/up-1');
        $this->assertSame(0700, fileperms($this->paths->state() . '/used') & 0777);
    }

    public function test_failed_used_marker_write_is_logged_and_still_returns_201(): void
    {
        $this->assertSame(200, $this->post('abcd', '0')->status);
        chmod($this->paths->state() . '/used', 0500);
        $r = $this->post('efghij', '4');
        $this->assertSame(201, $r->status);
        $this->assertStringContainsString('cannot write used marker', (string) file_get_contents($this->errorLog));
    }

    public function test_failed_rename_deletes_the_partial_and_returns_500(): void
    {
        $this->assertSame(200, $this->post('abcd', '0')->status);
        chmod($this->paths->maps(), 0555);
        $r = $this->post('efghij', '4');
        $this->assertSame(500, $r->status);
        $this->assertSame(['error' => 'write_failed'], $r->body);
        $this->assertFileDoesNotExist($this->paths->partial() . '/upload-up-1');
    }

    public function test_unreadable_folder_fails_closed_instead_of_skipping_the_exists_check(): void
    {
        $this->assertSame(200, $this->post('abcd', '0')->status);
        chmod($this->paths->maps(), 0300);
        $r = $this->post('efghij', '4');
        chmod($this->paths->maps(), 0755);
        $this->assertSame(500, $r->status);
        $this->assertSame(['error' => 'write_failed'], $r->body);
        $this->assertFileDoesNotExist($this->paths->partial() . '/upload-up-1');
        $this->assertFileDoesNotExist($this->paths->maps() . '/test.sd7');
    }
}
