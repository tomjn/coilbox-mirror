<?php

declare(strict_types=1);

namespace Mirror;

final class Listing
{
    /** Kinds listed in files. Mirrors on earlier releases read only these, and reject a listing with any other. */
    private const FILE_KINDS = ['map', 'game'];

    /**
     * Scans each kind's folder and returns a listing document. A file whose size and mtime match
     * its entry in $previous keeps the SHA-256 from that entry instead of being hashed again.
     */
    public static function build(Paths $p, ?array $previous, int $now): array
    {
        $files = [];
        foreach (Paths::KINDS as $kind) {
            $dir = $p->dirFor($kind);
            $names = is_dir($dir) ? scandir($dir) : false;
            if ($names === false) {
                continue;
            }
            foreach ($names as $name) {
                if (!Filename::isValidFor($kind, $name) || !is_file("$dir/$name")) {
                    continue;
                }
                // An empty file is never a real archive, and a listing entry needs size >= 1.
                clearstatcache(true, "$dir/$name");
                if (filesize("$dir/$name") === 0) {
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

    /** True when the document has the listing shape, with every entry well formed. Own lists may be left out. */
    public static function isValidDoc(array $doc): bool
    {
        if (($doc['v'] ?? null) !== 1 || !is_int($doc['generated_at'] ?? null)) {
            return false;
        }
        foreach (Paths::OWN_LIST_KINDS as $kind) {
            $key = Paths::folderFor($kind);
            if (array_key_exists($key, $doc) && !self::validEntries($doc[$key], [$kind])) {
                return false;
            }
        }
        return self::validEntries($doc['files'] ?? null, self::FILE_KINDS);
    }

    /** @param list<string> $kinds */
    private static function validEntries(mixed $files, array $kinds): bool
    {
        if (!is_array($files) || !array_is_list($files)) {
            return false;
        }
        foreach ($files as $file) {
            if (
                !is_array($file)
                || !in_array($file['kind'] ?? null, $kinds, true)
                || !is_string($file['filename'] ?? null) || !Filename::isValidFor($file['kind'], $file['filename'])
                || !is_int($file['size'] ?? null) || $file['size'] < 1
                || !is_string($file['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}\z/', $file['sha256']) !== 1
                || !is_int($file['mtime'] ?? null)
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * The most bytes a listing document with up to $entries files can need. Callers use it to
     * refuse a larger download before it fills memory.
     */
    public static function maxDocBytes(int $entries): int
    {
        // Engine, replay and track names are far shorter than 255 bytes and no kind is longer than "engine", so
        // this bounds their entries too.
        $entry = Json::encode([
            'filename' => str_repeat('a', 255),
            'kind' => 'engine',
            'size' => PHP_INT_MAX,
            'sha256' => str_repeat('f', 64),
            'mtime' => PHP_INT_MAX,
        ]);
        // Allow every filename byte to be escaped to six bytes, and one comma between entries.
        $maxEntry = strlen($entry) + 255 * 6 - 255 + 1;
        $lists = array_fill_keys(['files', ...array_map(Paths::folderFor(...), Paths::OWN_LIST_KINDS)], []);
        $envelope = strlen(Json::encode(['v' => 1, 'generated_at' => PHP_INT_MAX] + $lists));
        return $entries * $maxEntry + $envelope;
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

    /**
     * Adds one file to listing.json, hashing it unless the caller passes the hash it already has.
     * The caller holds the disk lock.
     */
    public static function addFile(Paths $p, string $kind, string $filename, int $now, ?string $sha256 = null): void
    {
        $doc = self::load($p) ?? self::document([], $now);
        $entry = self::entry($p, $kind, $filename, null, $sha256);
        $files = array_values(array_filter(
            self::entries($doc),
            static fn (array $f) => !($f['kind'] === $kind && $f['filename'] === $filename),
        ));
        $files[] = $entry;
        self::write($p, self::document($files, $now));
    }

    /** Returns every entry, maps and games first, then each own list. */
    public static function entries(array $doc): array
    {
        $entries = $doc['files'] ?? [];
        foreach (Paths::OWN_LIST_KINDS as $kind) {
            $entries = [...$entries, ...$doc[Paths::folderFor($kind)] ?? []];
        }
        return $entries;
    }

    public static function find(array $doc, string $kind, string $filename): ?array
    {
        foreach (self::entries($doc) as $file) {
            if (($file['kind'] ?? null) === $kind && ($file['filename'] ?? null) === $filename) {
                return $file;
            }
        }
        return null;
    }

    private static function entry(Paths $p, string $kind, string $name, ?array $previous, ?string $sha256 = null): array
    {
        $path = $p->dirFor($kind) . '/' . $name;
        clearstatcache(true, $path);
        $size = filesize($path);
        $mtime = filemtime($path);
        if ($size === false || $mtime === false) {
            throw new \RuntimeException("Cannot stat $path");
        }
        if ($sha256 === null && $previous !== null && ($previous['size'] ?? null) === $size && ($previous['mtime'] ?? null) === $mtime && is_string($previous['sha256'] ?? null)) {
            $sha256 = $previous['sha256'];
        } elseif ($sha256 === null) {
            $sha256 = hash_file('sha256', $path);
            if ($sha256 === false) {
                throw new \RuntimeException("Cannot hash $path");
            }
        }
        return ['filename' => $name, 'kind' => $kind, 'size' => $size, 'sha256' => $sha256, 'mtime' => $mtime];
    }

    /**
     * Sorts by kind, then by filename in byte order. Each of Paths::OWN_LIST_KINDS goes in its own list, left out
     * when empty, so mirrors on earlier releases still accept the listing.
     */
    private static function document(array $files, int $now): array
    {
        usort($files, static fn (array $a, array $b) => [$a['kind'], $a['filename']] <=> [$b['kind'], $b['filename']]);
        $of = static fn (array $kinds): array => array_values(array_filter($files, static fn (array $f) => in_array($f['kind'], $kinds, true)));
        $doc = ['v' => 1, 'generated_at' => $now, 'files' => $of(self::FILE_KINDS)];
        foreach (Paths::OWN_LIST_KINDS as $kind) {
            $own = $of([$kind]);
            if ($own !== []) {
                $doc[Paths::folderFor($kind)] = $own;
            }
        }
        return $doc;
    }
}
