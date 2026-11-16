<?php

declare(strict_types=1);

namespace Mirror;

/** base64url, RFC 4648 URL-safe alphabet, no padding. */
final class B64
{
    public static function encode(string $bin): string
    {
        return sodium_bin2base64($bin, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /** Returns null on any invalid input, including padding and the standard alphabet. */
    public static function decode(string $s): ?string
    {
        try {
            return sodium_base642bin($s, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            return null;
        }
    }
}
