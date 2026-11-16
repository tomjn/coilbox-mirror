<?php

declare(strict_types=1);

namespace Mirror;

final readonly class Request
{
    /**
     * @param array<string, string> $headers lower case names
     * @param array<string, mixed>|null $file the uploaded part named "chunk", as PHP puts it in $_FILES
     */
    public function __construct(
        public string $method,
        public array $headers,
        public ?array $file,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        $file = $_FILES['chunk'] ?? null;
        return new self(
            is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '',
            $headers,
            is_array($file) ? $file : null,
        );
    }
}
