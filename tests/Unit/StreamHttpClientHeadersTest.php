<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\Deadline;
use Mirror\DownloadResult;
use Mirror\StreamHttpClient;
use Mirror\Tests\Support\Server;
use PHPUnit\Framework\TestCase;

final class StreamHttpClientHeadersTest extends TestCase
{
    public function test_headers_come_from_the_final_response_after_a_redirect(): void
    {
        $docroot = sys_get_temp_dir() . '/coilbox-headers-' . bin2hex(random_bytes(8));
        mkdir($docroot);
        $server = Server::start($docroot, dirname(__DIR__) . '/Support/redirect-router.php');
        try {
            $response = (new StreamHttpClient(followRedirects: true))->get($server->url() . '/start');
        } finally {
            $server->stop();
            @unlink($docroot . '-server.log');
            rmdir($docroot);
        }

        $this->assertSame(200, $response->status);
        $this->assertSame('done', $response->body);
        $this->assertSame('final', $response->headers['x-stage'] ?? null);
        $this->assertSame('application/octet-stream', $response->headers['content-type'] ?? null);
    }

    public function test_a_client_for_peers_does_not_follow_redirects(): void
    {
        $docroot = sys_get_temp_dir() . '/coilbox-headers-' . bin2hex(random_bytes(8));
        mkdir($docroot);
        $dest = $docroot . '/download';
        $server = Server::start($docroot, dirname(__DIR__) . '/Support/redirect-router.php');
        try {
            $client = new StreamHttpClient(followRedirects: false);
            $response = $client->get($server->url() . '/start');
            $download = $client->download($server->url() . '/start', $dest, 4, new Deadline(0, static fn (): float => 0.0));
        } finally {
            $server->stop();
            @unlink($docroot . '-server.log');
            @unlink($dest);
            rmdir($docroot);
        }

        $this->assertSame(302, $response->status);
        $this->assertSame('redirect', $response->headers['x-stage'] ?? null);
        $this->assertSame(DownloadResult::Failed, $download);
    }
}
