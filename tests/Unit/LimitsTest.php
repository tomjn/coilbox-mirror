<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\Limits;
use PHPUnit\Framework\TestCase;

final class LimitsTest extends TestCase
{
    public function test_envelope_matches_template_length(): void
    {
        $boundary = str_repeat('x', 70);
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"chunk\"; filename=\"chunk\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . ''
            . "\r\n--{$boundary}--\r\n";

        $this->assertSame(strlen($body), Limits::envelopeBytes());
    }

    public function test_upload_max_is_the_limit_when_smaller(): void
    {
        $this->assertSame(2097152, Limits::maxChunkBytes('2M', '8M', true));
    }

    public function test_post_max_minus_envelope_when_smaller(): void
    {
        $this->assertSame(2097152 - Limits::envelopeBytes(), Limits::maxChunkBytes('8M', '2M', true));
    }

    public function test_post_max_zero_means_unlimited_body(): void
    {
        $this->assertSame(2097152, Limits::maxChunkBytes('2M', '0', true));
    }

    public function test_disabled_uploads_return_null(): void
    {
        $this->assertNull(Limits::maxChunkBytes('0', '8M', true));
        $this->assertNull(Limits::maxChunkBytes('2M', '8M', false));
        $this->assertNull(Limits::maxChunkBytes('2M', '100', true));
    }
}
