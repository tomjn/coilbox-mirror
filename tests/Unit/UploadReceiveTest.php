<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\B64;
use Mirror\CatalogueStore;
use Mirror\Config;
use Mirror\Json;
use Mirror\Paths;
use Mirror\Request;
use Mirror\Response;
use Mirror\Tests\Support\FakeHttpClient;
use Mirror\Tests\Support\Signer;
use Mirror\Tests\Support\TempMirror;
use Mirror\UploadHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UploadReceiveTest extends TestCase
{
    private const NOW = 1000;

    private Paths $paths;
    private array $hub;
    private int $now = self::NOW;
    private string $body = 'abcdefghij';

    protected function setUp(): void
    {
        $this->paths = TempMirror::create();
        $this->hub = Signer::keypair(1);
    }

    protected function tearDown(): void
    {
        TempMirror::destroy($this->paths);
    }

    private function handler(): UploadHandler
    {
        $mirror = Signer::keypair(8);
        $config = Config::fromArray([
            'mirror_id' => 'm1',
            'secret_key' => B64::encode($mirror['secret']),
            'hub_url' => 'https://hub.example',
            'hub_keys' => [B64::encode($this->hub['public'])],
            'partial_lifetime' => 3600,
        ]);
        return new UploadHandler(
            $this->paths,
            $config,
            new CatalogueStore($this->paths, $config),
            new FakeHttpClient(),
            fn(): int => $this->now,
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
            'filename' => 'Test Map (v1.2) é.sd7',
            'kind' => 'map',
            'start_by' => self::NOW + 100,
        ], $this->hub['secret']);
    }

    private function partial(): string
    {
        return $this->paths->partial() . '/upload-up-1';
    }

    /** Makes a temp file holding the chunk, like PHP does for an upload. */
    private function chunkFile(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($tmp, $bytes);
        return ['name' => 'blob', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
    }

    private function post(string $bytes, string $offset, ?string $token = null, ?array $file = null): Response
    {
        $headers = ['x-mirror-token' => $token ?? $this->token(), 'x-mirror-offset' => $offset];
        $file ??= $this->chunkFile($bytes);
        $response = $this->handler()->handle(new Request('POST', $headers, $file));
        if (is_file($file['tmp_name'])) {
            unlink($file['tmp_name']);
        }
        return $response;
    }

    private function get(?string $token = null): Response
    {
        return $this->handler()->handle(new Request('GET', ['x-mirror-token' => $token ?? $this->token()], null));
    }

    public function test_first_chunk_creates_partial_and_reports_received(): void
    {
        $r = $this->post('abcd', '0');
        $this->assertSame(200, $r->status);
        $this->assertSame(['received' => 4, 'complete' => false], $r->body);
        $this->assertSame('abcd', file_get_contents($this->partial()));
    }

    public function test_status_returns_bytes_received(): void
    {
        $this->assertSame(['received' => 0, 'complete' => false], $this->get()->body);
        $this->post('abcd', '0');
        $r = $this->get();
        $this->assertSame(200, $r->status);
        $this->assertSame(['received' => 4, 'complete' => false], $r->body);
    }

    public function test_status_of_a_finished_upload_is_complete_and_ignores_start_by(): void
    {
        touch($this->paths->state() . '/used/up-1');
        $this->now = self::NOW + 5000;
        $r = $this->get();
        $this->assertSame(200, $r->status);
        $this->assertSame(['received' => 10, 'complete' => true], $r->body);
    }

    public function test_post_to_a_finished_upload_is_used(): void
    {
        touch($this->paths->state() . '/used/up-1');
        $r = $this->post('abcd', '0');
        $this->assertSame(409, $r->status);
        $this->assertSame(['error' => 'used'], $r->body);
    }

    public function test_resent_chunk_gets_bad_offset_with_real_count(): void
    {
        $this->assertSame(200, $this->post('abcd', '0')->status);
        $this->assertSame(200, $this->post('efg', '4')->status);
        $r = $this->post('abcd', '0');
        $this->assertSame(409, $r->status);
        $this->assertSame(['error' => 'bad_offset', 'received' => 7], $r->body);
        $this->assertSame('abcdefg', file_get_contents($this->partial()));
    }

    public function test_first_chunk_at_a_nonzero_offset_leaves_no_partial(): void
    {
        $r = $this->post('abcd', '3');
        $this->assertSame(409, $r->status);
        $this->assertSame(['error' => 'bad_offset', 'received' => 0], $r->body);
        $this->assertFileDoesNotExist($this->partial());
    }

    public function test_chunk_past_declared_size_is_too_large_and_file_unchanged(): void
    {
        $this->post('abcdefgh', '0');
        $r = $this->post('ijk', '8');
        $this->assertSame(413, $r->status);
        $this->assertSame(['error' => 'too_large'], $r->body);
        $this->assertSame('abcdefgh', file_get_contents($this->partial()));
    }

    public function test_first_chunk_larger_than_size_leaves_no_partial(): void
    {
        $r = $this->post('abcdefghijk', '0');
        $this->assertSame(413, $r->status);
        $this->assertFileDoesNotExist($this->partial());
    }

    public function test_first_chunk_after_start_by_is_expired_and_leaves_no_partial(): void
    {
        $this->now = self::NOW + 101;
        $r = $this->post('abcd', '0');
        $this->assertSame(410, $r->status);
        $this->assertSame(['error' => 'expired'], $r->body);
        $this->assertFileDoesNotExist($this->partial());
    }

    public function test_later_chunk_after_start_by_is_accepted(): void
    {
        $this->post('abcd', '0');
        $this->now = self::NOW + 5000;
        $r = $this->post('efg', '4');
        $this->assertSame(200, $r->status);
        $this->assertSame(['received' => 7, 'complete' => false], $r->body);
    }

    public function test_upload_on_removal_list_is_refused(): void
    {
        file_put_contents($this->paths->catalogueJson(), Json::encode([
            'removed' => [['sha256' => str_repeat('0', 64), 'filename' => 'TEST MAP (V1.2) É.SD7']],
        ]));
        $r = $this->post('abcd', '0');
        $this->assertSame(410, $r->status);
        $this->assertSame(['error' => 'removed'], $r->body);
        $this->assertFileDoesNotExist($this->partial());
    }

    public function test_unreadable_chunk_leaves_partial_length_unchanged(): void
    {
        $this->post('abcd', '0');
        $file = ['name' => 'blob', 'tmp_name' => $this->paths->root() . '/missing', 'error' => UPLOAD_ERR_OK, 'size' => 3];
        $r = $this->post('', '4', null, $file);
        $this->assertSame(400, $r->status);
        $this->assertSame(['error' => 'bad_request'], $r->body);
        $this->assertSame('abcd', file_get_contents($this->partial()));
    }

    public function test_missing_empty_and_failed_chunks_are_bad_request(): void
    {
        $noFile = $this->handler()->handle(new Request('POST', ['x-mirror-token' => $this->token(), 'x-mirror-offset' => '0'], null));
        $this->assertSame(['error' => 'bad_request'], $noFile->body);

        $empty = $this->post('', '0');
        $this->assertSame(400, $empty->status);

        $failed = $this->chunkFile('abcd');
        $failed['error'] = UPLOAD_ERR_PARTIAL;
        $this->assertSame(400, $this->post('', '0', null, $failed)->status);
        $this->assertFileDoesNotExist($this->partial());
    }

    #[DataProvider('badOffsets')]
    public function test_malformed_offset_is_bad_request(?string $offset): void
    {
        $headers = ['x-mirror-token' => $this->token()];
        if ($offset !== null) {
            $headers['x-mirror-offset'] = $offset;
        }
        $r = $this->handler()->handle(new Request('POST', $headers, $this->chunkFile('abcd')));
        $this->assertSame(400, $r->status);
        $this->assertSame(['error' => 'bad_request'], $r->body);
        $this->assertFileDoesNotExist($this->partial());
    }

    public static function badOffsets(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'negative' => ['-1'],
            'leading zero' => ['01'],
            'text' => ['abc'],
            'trailing newline' => ["0\n"],
            'too long' => ['12345678901234567890'],
            'decimal' => ['1.5'],
        ];
    }

    public function test_offset_beyond_integer_range_is_bad_offset(): void
    {
        $r = $this->post('abcd', '9999999999999999999');
        $this->assertSame(409, $r->status);
        $this->assertSame(['error' => 'bad_offset', 'received' => 0], $r->body);
    }

    public function test_reaching_size_returns_the_normal_response_for_now(): void
    {
        $r = $this->post($this->body, '0');
        $this->assertSame(200, $r->status);
        $this->assertSame(['received' => 10, 'complete' => false], $r->body);
    }

    public function test_wrong_mirror_and_bad_token_return_403(): void
    {
        $other = $this->post('abcd', '0', $this->token(['mirror' => 'm2']));
        $this->assertSame(403, $other->status);
        $this->assertSame(['error' => 'wrong_mirror'], $other->body);

        $bad = $this->post('abcd', '0', 'nonsense');
        $this->assertSame(403, $bad->status);
        $this->assertSame(['error' => 'bad_token'], $bad->body);

        $this->assertSame(403, $this->get('nonsense')->status);
        $this->assertFileDoesNotExist($this->partial());
    }

    public function test_missing_token_header_is_bad_request(): void
    {
        $r = $this->handler()->handle(new Request('GET', [], null));
        $this->assertSame(400, $r->status);
        $this->assertSame(['error' => 'bad_request'], $r->body);
    }

    public function test_put_returns_405(): void
    {
        $r = $this->handler()->handle(new Request('PUT', ['x-mirror-token' => $this->token()], null));
        $this->assertSame(405, $r->status);
        $this->assertSame(['error' => 'method_not_allowed'], $r->body);
    }
}
