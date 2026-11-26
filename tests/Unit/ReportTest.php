<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\B64;
use Mirror\Config;
use Mirror\Crypto;
use Mirror\HttpResponse;
use Mirror\Json;
use Mirror\Report;
use Mirror\Tests\Support\FakeHttpClient;
use Mirror\Tests\Support\Signer;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    private const KNOWN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const NEW = '0000000000000000000000000000000000000000000000000000000000000001';

    private function config(): Config
    {
        $mirror = Signer::keypair(8);
        return Config::fromArray([
            'mirror_id' => 'm1',
            'secret_key' => B64::encode($mirror['secret']),
            'hub_url' => 'https://hub.example',
            'hub_keys' => [B64::encode(Signer::keypair(1)['public'])],
            'partial_lifetime' => 3600,
        ]);
    }

    /** @return array<string, mixed> */
    private function catalogue(): array
    {
        return [
            'v' => 1,
            'seq' => 42,
            'generated_at' => 1795000000,
            'mirrors' => [],
            'archives' => [
                ['filename' => 'x.sd7', 'kind' => 'map', 'sha256' => self::KNOWN, 'size' => 123],
                ['filename' => 'y.sd7', 'kind' => 'map', 'sha256' => self::OTHER, 'size' => 5],
            ],
            'removed' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function listing(): array
    {
        return [
            'v' => 1,
            'generated_at' => 1795000001,
            'files' => [
                ['filename' => 'x.sd7', 'kind' => 'map', 'size' => 123, 'sha256' => self::KNOWN, 'mtime' => 1],
                ['filename' => 'new.sd7', 'kind' => 'map', 'size' => 123, 'sha256' => self::NEW, 'mtime' => 2],
                ['filename' => 'dup.sd7', 'kind' => 'map', 'size' => 123, 'sha256' => self::KNOWN, 'mtime' => 3],
            ],
        ];
    }

    public function test_payload_lists_held_and_unknown(): void
    {
        $json = Report::payload($this->config(), $this->catalogue(), $this->listing(), 'sync', null, 1795000002);
        $this->assertSame([
            'v' => 1,
            'mirror' => 'm1',
            'event' => 'sync',
            'upload' => null,
            'sent_at' => 1795000002,
            'catalogue_seq' => 42,
            'catalogue_generated_at' => 1795000000,
            'held' => [self::NEW, self::KNOWN],
            'unknown' => [['filename' => 'new.sd7', 'kind' => 'map', 'size' => 123, 'sha256' => self::NEW]],
            'kinds' => ['map', 'game', 'engine', 'replay', 'track'],
        ], Json::decodeObject($json));
    }

    public function test_payload_carries_kinds_after_unknown(): void
    {
        $doc = Json::decodeObject(Report::payload($this->config(), null, $this->listing(), 'sync', null, 1795000002));
        $this->assertSame(['unknown', 'kinds'], array_slice(array_keys($doc), -2));
        $this->assertSame(['map', 'game', 'engine', 'replay', 'track'], $doc['kinds']);
    }

    private const ENGINE = 'recoil_2026.09.03_arm64-macos.7z';
    private const ENGINE_SHA = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    /** @return array<string, mixed> the listing with one engine */
    private function listingWithEngine(): array
    {
        return $this->listing() + ['engines' => [
            ['filename' => self::ENGINE, 'kind' => 'engine', 'size' => 9, 'sha256' => self::ENGINE_SHA, 'mtime' => 4],
        ]];
    }

    public function test_a_held_engine_in_the_catalogue_is_not_unknown(): void
    {
        $catalogue = $this->catalogue() + ['engines' => [
            ['filename' => self::ENGINE, 'sha256' => self::ENGINE_SHA, 'size' => 9, 'version' => '2026.09.03', 'platform' => 'arm64-macos', 'note' => 'n'],
        ]];
        $doc = Json::decodeObject(Report::payload($this->config(), $catalogue, $this->listingWithEngine(), 'sync', null, 1795000002));
        $this->assertContains(self::ENGINE_SHA, $doc['held']);
        $this->assertSame(['new.sd7'], array_column($doc['unknown'], 'filename'));
    }

    public function test_a_held_engine_not_in_the_catalogue_is_unknown_with_kind_engine(): void
    {
        $doc = Json::decodeObject(Report::payload($this->config(), $this->catalogue(), $this->listingWithEngine(), 'upload', 'u-1', 1795000002));
        $this->assertContains(
            ['filename' => self::ENGINE, 'kind' => 'engine', 'size' => 9, 'sha256' => self::ENGINE_SHA],
            $doc['unknown'],
        );
    }

    public function test_payload_carries_the_upload_id(): void
    {
        $json = Report::payload($this->config(), $this->catalogue(), $this->listing(), 'upload', 'u-1', 1795000002);
        $doc = Json::decodeObject($json);
        $this->assertSame('upload', $doc['event']);
        $this->assertSame('u-1', $doc['upload']);
    }

    public function test_payload_has_null_seq_with_no_catalogue(): void
    {
        $json = Report::payload($this->config(), null, $this->listing(), 'sync', null, 1795000002);
        $doc = Json::decodeObject($json);
        $this->assertNull($doc['catalogue_seq']);
        $this->assertNull($doc['catalogue_generated_at']);
        $this->assertCount(2, $doc['held']);
        $this->assertCount(3, $doc['unknown']);
        $this->assertSame('x.sd7', $doc['unknown'][0]['filename']);
    }

    public function test_body_signature_verifies_with_mirror_public_key(): void
    {
        $mirror = Signer::keypair(8);
        $payload = Report::payload($this->config(), $this->catalogue(), $this->listing(), 'sync', null, 1795000002);
        $body = Report::body($payload, $mirror['secret']);
        $doc = Json::decodeObject($body);
        $this->assertSame(['payload', 'sig'], array_keys($doc));
        $raw = B64::decode($doc['payload']);
        $this->assertSame($payload, $raw);
        $this->assertTrue(Crypto::verify(Crypto::CTX_REPORT, $raw, B64::decode($doc['sig']), $mirror['public']));
        $this->assertFalse(Crypto::verify(Crypto::CTX_REPORT, $raw . ' ', B64::decode($doc['sig']), $mirror['public']));
    }

    public function test_send_posts_to_hub_report_url_and_reads_status(): void
    {
        $url = 'https://hub.example/api/v1/mirrors/report';
        $http = new FakeHttpClient();

        $http->on($url, new HttpResponse(202, ''));
        $this->assertTrue(Report::send($http, $this->config(), '{"a":1}'));
        $this->assertSame([[$url, '{"a":1}']], $http->posts);

        $http->on($url, new HttpResponse(500, ''));
        $this->assertFalse(Report::send($http, $this->config(), '{"a":1}'));

        $http->on($url, new HttpResponse(0, ''));
        $this->assertFalse(Report::send($http, $this->config(), '{"a":1}'));
    }

    public function test_unknown_mirror_is_the_hub_403_for_a_mirror_it_does_not_know(): void
    {
        $this->assertTrue(Report::unknownMirror(new HttpResponse(403, '{"error":"unknown_mirror"}')));
        $this->assertFalse(Report::unknownMirror(new HttpResponse(403, '{"error":"bad_signature"}')));
        $this->assertFalse(Report::unknownMirror(new HttpResponse(403, 'not json')));
        $this->assertFalse(Report::unknownMirror(new HttpResponse(200, '{"error":"unknown_mirror"}')));
    }
}
