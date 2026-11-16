<?php

declare(strict_types=1);

namespace Mirror;

final readonly class Response
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public int $status,
        public array $body,
    ) {
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo Json::encode($this->body);
    }
}
