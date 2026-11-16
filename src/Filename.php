<?php

declare(strict_types=1);

namespace Mirror;

final class Filename
{
    /**
     * True for a valid UTF-8 name of 1 to 255 bytes that holds no slash, backslash, NUL or control
     * character, does not start with a dot, and ends in .sd7 or .sdz in any case.
     */
    public static function isValid(string $name): bool
    {
        $length = strlen($name);
        if ($length < 1 || $length > 255) {
            return false;
        }
        // The u modifier fails on invalid UTF-8. \p{Cc} covers NUL, C0, DEL and C1 controls.
        return preg_match('/\A[^\/\\\\\p{Cc}.][^\/\\\\\p{Cc}]*\.(?:sd7|sdz)\z/iu', $name) === 1;
    }
}
