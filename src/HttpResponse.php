<?php

declare(strict_types=1);

namespace Mirror;

final readonly class HttpResponse
{
    /**
     * @param int $status the HTTP status, or 0 when the request failed before any response
     * @param array<string, string> $headers the final response's headers, with lowercase names
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {
    }
}
