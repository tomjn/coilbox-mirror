<?php

declare(strict_types=1);

namespace Mirror;

final class Limits
{
    /** RFC 2046 allows a multipart boundary of up to 70 characters. */
    private const MAX_BOUNDARY_LENGTH = 70;

    /**
     * Length of the only body a client may send, with empty data and the longest boundary.
     */
    public static function envelopeBytes(): int
    {
        $boundary = str_repeat('x', self::MAX_BOUNDARY_LENGTH);
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"chunk\"; filename=\"chunk\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . ''
            . "\r\n--{$boundary}--\r\n";

        return strlen($body);
    }

    /**
     * The largest chunk of file data one request can carry, or null when uploads cannot work.
     */
    public static function maxChunkBytes(string $uploadMax, string $postMax, bool $fileUploads): ?int
    {
        $upload = self::quantity($uploadMax);
        if (!$fileUploads || $upload <= 0) {
            return null;
        }

        $post = self::quantity($postMax);
        if ($post === 0) {
            return $upload;
        }

        $chunk = min($upload, $post - self::envelopeBytes());

        return $chunk < 1 ? null : $chunk;
    }

    public static function fromIni(): ?int
    {
        return self::maxChunkBytes(
            (string) ini_get('upload_max_filesize'),
            (string) ini_get('post_max_size'),
            (bool) ini_get('file_uploads'),
        );
    }

    private static function quantity(string $value): int
    {
        // ini_parse_quantity warns on a malformed value and returns 0. The warning is
        // suppressed because PHP itself reads such a value as 0, and the rules above treat 0 the same way.
        return @ini_parse_quantity($value);
    }
}
