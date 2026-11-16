<?php

declare(strict_types=1);

namespace Mirror;

/** Holds the verified hub catalogue and the hub keys the mirror trusts. */
final class CatalogueStore
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Config $config,
    ) {
    }

    /** Returns the held catalogue without verifying it again, or null when none is held. */
    public function held(): ?array
    {
        $raw = @file_get_contents($this->paths->catalogueJson());
        return $raw === false ? null : Json::decodeObject($raw);
    }

    public function heldSeq(): ?int
    {
        $seq = $this->held()['seq'] ?? null;
        return is_int($seq) ? $seq : null;
    }

    /** @return list<string> raw public keys from the config, then from hub_keys.json */
    public function trustedKeys(): array
    {
        $keys = $this->config->hubKeys;
        foreach ($this->savedKeys() as $key) {
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Verifies the catalogue, stores it exactly as received, and trusts any key it endorses.
     *
     * @return array<string, mixed> the catalogue
     * @throws CatalogueRejected
     */
    public function accept(string $json, string $sigText): array
    {
        $catalogue = Catalogue::verify($json, $sigText, $this->trustedKeys(), $this->heldSeq());
        self::writeAtomic($this->paths->catalogueJson(), $json);
        self::writeAtomic($this->paths->catalogueSig(), $sigText);
        if (isset($catalogue['next_key'])) {
            // verify() has already checked this key, so it decodes.
            $this->saveKey(B64::decode($catalogue['next_key']['public_key']));
        }
        return $catalogue;
    }

    /** True when the held catalogue lists this hash, or this filename in any letter case. */
    public function isRemoved(string $sha256, string $filename): bool
    {
        $name = mb_strtolower($filename);
        foreach ($this->held()['removed'] ?? [] as $entry) {
            if (
                (is_string($entry['sha256'] ?? null) && hash_equals($entry['sha256'], $sha256))
                || (is_string($entry['filename'] ?? null) && mb_strtolower($entry['filename']) === $name)
            ) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function savedKeys(): array
    {
        $raw = @file_get_contents($this->keysFile());
        $list = $raw === false ? null : json_decode($raw, true);
        if (!is_array($list)) {
            return [];
        }
        $keys = [];
        foreach ($list as $text) {
            $key = is_string($text) ? B64::decode($text) : null;
            if ($key !== null && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    private function saveKey(string $key): void
    {
        if (in_array($key, $this->trustedKeys(), true)) {
            return;
        }
        $texts = array_map(B64::encode(...), [...$this->savedKeys(), $key]);
        self::writeAtomic($this->keysFile(), Json::encode($texts));
    }

    private function keysFile(): string
    {
        return $this->paths->state() . '/hub_keys.json';
    }

    private static function writeAtomic(string $path, string $bytes): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($tmp, $bytes) === false) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write $tmp");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot move $tmp to $path");
        }
    }
}
