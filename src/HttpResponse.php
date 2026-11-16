<?php

declare(strict_types=1);

namespace Mirror;

final readonly class HttpResponse
{
    /** @param int $status the HTTP status, or 0 when the request failed before any response */
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }
}
