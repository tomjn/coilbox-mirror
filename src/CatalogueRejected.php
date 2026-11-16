<?php

declare(strict_types=1);

namespace Mirror;

/** A catalogue that failed verification. The reason is malformed, bad_signature, bad_key_endorsement or not_newer. */
final class CatalogueRejected extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
