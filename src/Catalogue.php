<?php

declare(strict_types=1);

namespace Mirror;

/** Checks a signed hub catalogue before the mirror acts on it. */
final class Catalogue
{
    /**
     * @param list<string> $trustedKeys raw 32-byte public keys
     * @param int|null $heldSeq the seq of the catalogue already held, if any
     * @return array<string, mixed> the decoded catalogue
     * @throws CatalogueRejected
     */
    public static function verify(string $json, string $sigText, array $trustedKeys, ?int $heldSeq): array
    {
        $doc = Json::decodeObject($json);
        if ($doc === null) {
            throw new CatalogueRejected('malformed');
        }

        // A new key counts only when a key we already trust has signed it.
        $candidates = $trustedKeys;
        if (array_key_exists('next_key', $doc)) {
            $candidates[] = self::endorsedKey($doc['next_key'], $trustedKeys);
        }

        $sig = B64::decode($sigText);
        if ($sig === null || !self::signedByAny($json, $sig, $candidates)) {
            throw new CatalogueRejected('bad_signature');
        }

        // A seq that is not an integer fails the shape check below.
        if ($heldSeq !== null && is_int($doc['seq'] ?? null) && $doc['seq'] <= $heldSeq) {
            throw new CatalogueRejected('not_newer');
        }

        if (!self::validShape($doc)) {
            throw new CatalogueRejected('malformed');
        }

        return $doc;
    }

    /**
     * Returns the engines the mirror should hold, each with kind "engine", and one log line for each entry
     * dropped. Each entry is checked on its own, so one bad engine does not stop maps and games syncing.
     * Only the fields the mirror uses are checked.
     *
     * @param array<string, mixed> $doc a catalogue that verify() accepted
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    public static function engines(array $doc): array
    {
        if (!array_key_exists('engines', $doc)) {
            return [[], []];
        }
        if (!is_array($doc['engines']) || !array_is_list($doc['engines'])) {
            return [[], ['catalogue engines list dropped, malformed']];
        }
        $engines = [];
        $dropped = [];
        foreach ($doc['engines'] as $i => $e) {
            if (
                is_array($e)
                && is_string($e['filename'] ?? null) && Filename::isValidFor('engine', $e['filename'])
                && self::validHash($e['sha256'] ?? null)
                && is_int($e['size'] ?? null) && $e['size'] >= 1
            ) {
                // The kind is set here, so an entry cannot name another folder.
                $engines[] = array_merge($e, ['kind' => 'engine']);
            } else {
                $dropped[] = "catalogue engine entry $i dropped, malformed";
            }
        }
        return [$engines, $dropped];
    }

    /**
     * Returns every archive and engine the mirror should hold.
     *
     * @param array<string, mixed> $doc a catalogue that verify() accepted
     * @return list<array<string, mixed>>
     */
    public static function wanted(array $doc): array
    {
        return [...$doc['archives'], ...self::engines($doc)[0]];
    }

    /**
     * @param list<string> $trustedKeys
     * @return string the raw endorsed public key
     * @throws CatalogueRejected bad_key_endorsement
     */
    private static function endorsedKey(mixed $next, array $trustedKeys): string
    {
        if (!is_array($next) || !is_string($next['public_key'] ?? null) || !is_string($next['sig'] ?? null)) {
            throw new CatalogueRejected('bad_key_endorsement');
        }
        $key = B64::decode($next['public_key']);
        $sig = B64::decode($next['sig']);
        if (
            $key === null || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
        ) {
            throw new CatalogueRejected('bad_key_endorsement');
        }
        foreach ($trustedKeys as $trusted) {
            if (Crypto::verify(Crypto::CTX_HUB_KEY, $key, $sig, $trusted)) {
                return $key;
            }
        }
        throw new CatalogueRejected('bad_key_endorsement');
    }

    /** @param list<string> $keys */
    private static function signedByAny(string $json, string $sig, array $keys): bool
    {
        foreach ($keys as $key) {
            if (Crypto::verify(Crypto::CTX_CATALOGUE, $json, $sig, $key)) {
                return true;
            }
        }
        return false;
    }

    /** Unknown keys, at the top level and inside entries, are allowed so the hub can add fields. */
    private static function validShape(array $doc): bool
    {
        if (($doc['v'] ?? null) !== 1 || !is_int($doc['seq'] ?? null) || $doc['seq'] < 1 || !is_int($doc['generated_at'] ?? null)) {
            return false;
        }
        return self::allEntries($doc['mirrors'] ?? null, self::validMirror(...))
            && self::allEntries($doc['archives'] ?? null, self::validArchive(...))
            && self::allEntries($doc['removed'] ?? null, self::validRemoved(...));
    }

    /** @param callable(array): bool $valid */
    private static function allEntries(mixed $list, callable $valid): bool
    {
        if (!is_array($list) || !array_is_list($list)) {
            return false;
        }
        foreach ($list as $entry) {
            if (!is_array($entry) || !$valid($entry)) {
                return false;
            }
        }
        return true;
    }

    private static function validMirror(array $m): bool
    {
        $id = $m['id'] ?? null;
        $url = $m['base_url'] ?? null;
        $key = is_string($m['public_key'] ?? null) ? B64::decode($m['public_key']) : null;
        return is_string($id) && preg_match('/^[a-z0-9][a-z0-9-]{0,63}\z/', $id) === 1
            && is_string($url) && self::validBaseUrl($url)
            && $key !== null && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
    }

    private static function validBaseUrl(string $url): bool
    {
        if (preg_match('#\Ahttps?://[^\s/?\#]+(?:/[^\s?\#]*)?\z#', $url) !== 1 || str_ends_with($url, '/')) {
            return false;
        }
        $parts = parse_url($url);
        return $parts !== false && ($parts['host'] ?? '') !== '';
    }

    private static function validArchive(array $a): bool
    {
        foreach (['shortname', 'author', 'description'] as $optional) {
            if (!array_key_exists($optional, $a) || ($a[$optional] !== null && !is_string($a[$optional]))) {
                return false;
            }
        }
        return is_string($a['filename'] ?? null) && Filename::isValid($a['filename'])
            && in_array($a['kind'] ?? null, ['map', 'game'], true)
            && self::validHash($a['sha256'] ?? null)
            && is_int($a['size'] ?? null) && $a['size'] >= 1
            && is_string($a['internal_name'] ?? null)
            && is_string($a['version'] ?? null);
    }

    private static function validRemoved(array $r): bool
    {
        return self::validHash($r['sha256'] ?? null)
            && is_string($r['filename'] ?? null) && $r['filename'] !== ''
            && is_string($r['reason'] ?? null)
            && is_int($r['removed_at'] ?? null);
    }

    private static function validHash(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[0-9a-f]{64}\z/', $v) === 1;
    }
}
