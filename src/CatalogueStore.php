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

    /**
     * Returns the seq of the held catalogue, or null when the mirror has never held one.
     *
     * The seq lives in private/state/catalogue_seq, because catalogue.json sits in the web root where anyone
     * with write access there could swap it for an older one. A catalogue.json with no state file comes from an
     * install made before the state file existed, so its seq is used once.
     *
     * This fails closed. A damaged state file, or a catalogue.json that cannot be read with no state file to
     * fall back on, throws instead of returning null, because null would let any older signed catalogue in.
     *
     * @throws \RuntimeException when the seq cannot be known
     */
    public function heldSeq(): ?int
    {
        $raw = @file_get_contents($this->seqFile());
        if ($raw !== false) {
            $seq = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($seq === false || $raw !== (string) $seq) {
                throw new \RuntimeException('private/state/catalogue_seq is damaged, so no catalogue can be checked as newer. Put the seq of the held catalogue in it, or delete it together with catalogue.json and catalogue.sig to accept the next signed catalogue.');
            }
            return $seq;
        }
        if (!file_exists($this->paths->catalogueJson())) {
            return null;
        }
        $seq = $this->held()['seq'] ?? null;
        if (!is_int($seq)) {
            throw new \RuntimeException('catalogue.json cannot be read and private/state/catalogue_seq is missing, so no catalogue can be checked as newer. Delete catalogue.json and catalogue.sig to accept the next signed catalogue.');
        }
        return $seq;
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
        // Save the key first. A mirror that showed the new seq while refusing tokens signed by the new key
        // would tell the hub it had moved over when it had not.
        if (isset($catalogue['next_key'])) {
            // verify() has already checked this key, so it decodes.
            $this->saveKey(B64::decode($catalogue['next_key']['public_key']));
        }
        // Record the seq before the catalogue. If the catalogue write then fails, the mirror refuses this seq
        // again and keeps its older catalogue until a newer one comes, which is safer than going back a seq.
        self::writeAtomic($this->seqFile(), (string) $catalogue['seq']);
        self::writeAtomic($this->paths->catalogueJson(), $json);
        self::writeAtomic($this->paths->catalogueSig(), $sigText);
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

    private function seqFile(): string
    {
        return $this->paths->state() . '/catalogue_seq';
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
