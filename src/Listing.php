<?php

declare(strict_types=1);

namespace Mirror;

final class Listing
{
    private const KINDS = ['map', 'game'];

    /**
     * Scans maps/ and games/ and returns a listing document. A file whose size and mtime match
     * its entry in $previous keeps the SHA-256 from that entry instead of being hashed again.
     */
    public static function build(Paths $p, ?array $previous, int $now): array
    {
        $files = [];
        foreach (self::KINDS as $kind) {
            $dir = $p->dirFor($kind);
            $names = is_dir($dir) ? scandir($dir) : false;
            if ($names === false) {
                continue;
            }
            foreach ($names as $name) {
                if (!Filename::isValid($name) || !is_file("$dir/$name")) {
                    continue;
                }
                $files[] = self::entry($p, $kind, $name, $previous === null ? null : self::find($previous, $kind, $name));
            }
        }
        return self::document($files, $now);
    }

    /** Returns null when the file is missing or is not a valid listing. */
    public static function load(Paths $p): ?array
    {
        $raw = @file_get_contents($p->listing());
        if ($raw === false) {
            return null;
        }
        $doc = Json::decodeObject($raw);
        return $doc !== null && self::isValidDoc($doc) ? $doc : null;
    }

    /** True when the document has the listing shape, with every entry well formed. */
    public static function isValidDoc(array $doc): bool
    {
        if (($doc['v'] ?? null) !== 1 || !is_int($doc['generated_at'] ?? null)) {
            return false;
        }
        $files = $doc['files'] ?? null;
        if (!is_array($files) || !array_is_list($files)) {
            return false;
        }
        foreach ($files as $file) {
            if (
                !is_array($file)
                || !is_string($file['filename'] ?? null) || !Filename::isValid($file['filename'])
                || !in_array($file['kind'] ?? null, self::KINDS, true)
                || !is_int($file['size'] ?? null) || $file['size'] < 1
                || !is_string($file['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}\z/', $file['sha256']) !== 1
                || !is_int($file['mtime'] ?? null)
            ) {
                return false;
            }
        }
        return true;
    }

    /** Writes to a uniquely named temp file in the same folder, then renames it into place. */
    public static function write(Paths $p, array $doc): void
    {
        $path = $p->listing();
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($tmp, Json::encode($doc)) === false) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write $tmp");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot move $tmp to $path");
        }
    }

    /** Hashes one file and updates listing.json. The caller holds the disk lock. */
    public static function addFile(Paths $p, string $kind, string $filename, int $now): void
    {
        $doc = self::load($p) ?? self::document([], $now);
        $entry = self::entry($p, $kind, $filename, null);
        $files = array_values(array_filter(
            $doc['files'],
            static fn (array $f) => !($f['kind'] === $kind && $f['filename'] === $filename),
        ));
        $files[] = $entry;
        self::write($p, self::document($files, $now));
    }

    public static function find(array $doc, string $kind, string $filename): ?array
    {
        foreach ($doc['files'] ?? [] as $file) {
            if (($file['kind'] ?? null) === $kind && ($file['filename'] ?? null) === $filename) {
                return $file;
            }
        }
        return null;
    }

    private static function entry(Paths $p, string $kind, string $name, ?array $previous): array
    {
        $path = $p->dirFor($kind) . '/' . $name;
        clearstatcache(true, $path);
        $size = filesize($path);
        $mtime = filemtime($path);
        if ($size === false || $mtime === false) {
            throw new \RuntimeException("Cannot stat $path");
        }
        if ($previous !== null && ($previous['size'] ?? null) === $size && ($previous['mtime'] ?? null) === $mtime && is_string($previous['sha256'] ?? null)) {
            $sha256 = $previous['sha256'];
        } else {
            $sha256 = hash_file('sha256', $path);
            if ($sha256 === false) {
                throw new \RuntimeException("Cannot hash $path");
            }
        }
        return ['filename' => $name, 'kind' => $kind, 'size' => $size, 'sha256' => $sha256, 'mtime' => $mtime];
    }

    /** Sorts by kind, then by filename in byte order. */
    private static function document(array $files, int $now): array
    {
        usort($files, static fn (array $a, array $b) => [$a['kind'], $a['filename']] <=> [$b['kind'], $b['filename']]);
        return ['v' => 1, 'generated_at' => $now, 'files' => $files];
    }
}
