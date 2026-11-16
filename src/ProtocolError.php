<?php

declare(strict_types=1);

namespace Mirror;

final class ProtocolError extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly array $extra = [],
    ) {
        parent::__construct($error);
    }
}
