<?php

declare(strict_types=1);

namespace Mirror;

final class Json
{
    public static function encode(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Returns the decoded object as an array, or null if the input is not a JSON object. */
    public static function decodeObject(string $s): ?array
    {
        try {
            $v = json_decode($s, false, 512, JSON_THROW_ON_ERROR);
            if (!$v instanceof \stdClass) {
                return null;
            }
            return json_decode($s, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
