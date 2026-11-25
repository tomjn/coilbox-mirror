<?php

declare(strict_types=1);

namespace Mirror;

final class Filename
{
    /** Engine builds use the name of an official Recoil release, so the hub can read the version and platform from it. */
    private const ENGINE = '/\\Arecoil_[0-9A-Za-z][0-9A-Za-z.+-]{0,63}_(?:amd64-linux|amd64-windows|arm64-macos)\\.7z\\z/';

    /** Checks a name against the rules for its kind. Maps and games use isValid. An unknown kind is never valid. */
    public static function isValidFor(string $kind, string $name): bool
    {
        return match ($kind) {
            'map', 'game' => self::isValid($name),
            'engine' => preg_match(self::ENGINE, $name) === 1,
            default => false,
        };
    }

    /**
     * True for a valid UTF-8 name of 1 to 255 bytes that holds no slash, backslash, control, format, line
     * separator or paragraph separator character, does not start with a dot, and ends in .sd7 or .sdz in any case.
     */
    public static function isValid(string $name): bool
    {
        $length = strlen($name);
        if ($length < 1 || $length > 255) {
            return false;
        }
        // The u modifier fails on invalid UTF-8. \p{Cc} covers NUL, C0, DEL and C1 controls. \p{Cf} covers
        // invisible format characters such as U+202E, which flips text so a listing can show a fake extension,
        // and U+FEFF. \p{Zl} and \p{Zp} are the line and paragraph separators U+2028 and U+2029.
        return preg_match('/\A[^\/\\\\\p{Cc}\p{Cf}\p{Zl}\p{Zp}.][^\/\\\\\p{Cc}\p{Cf}\p{Zl}\p{Zp}]*\.(?:sd7|sdz)\z/iu', $name) === 1;
    }
}
