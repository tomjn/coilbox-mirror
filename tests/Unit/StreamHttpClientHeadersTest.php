<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

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
            $response = (new StreamHttpClient())->get($server->url() . '/start');
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
}
