<?php

declare(strict_types=1);

namespace Mirror;

/** A hub upload token that has passed signature, shape and mirror checks. */
final class Token
{
    private const FIELDS = ['v', 'mirror', 'upload', 'sha256', 'size', 'filename', 'kind', 'start_by'];

    private function __construct(
        public readonly string $mirror,
        public readonly string $uploadId,
        public readonly string $sha256,
        public readonly int $size,
        public readonly string $filename,
        public readonly string $kind,
        public readonly int $startBy,
    ) {
    }

    /**
     * @param list<string> $hubKeys raw 32-byte public keys
     * @throws ProtocolError bad_token for any signature or shape problem, wrong_mirror for another mirror's token
     */
    public static function verify(string $raw, array $hubKeys, string $mirrorId): self
    {
        $parts = explode('.', $raw);
        if (count($parts) !== 2) {
            throw new ProtocolError('bad_token');
        }
        $payload = B64::decode($parts[0]);
        $sig = B64::decode($parts[1]);
        if ($payload === null || $sig === null) {
            throw new ProtocolError('bad_token');
        }

        $signed = false;
        foreach ($hubKeys as $key) {
            if (Crypto::verify(Crypto::CTX_TOKEN, $payload, $sig, $key)) {
                $signed = true;
                break;
            }
        }
        if (!$signed) {
            throw new ProtocolError('bad_token');
        }

        $p = Json::decodeObject($payload);
        if ($p === null || count($p) !== count(self::FIELDS) || array_diff(self::FIELDS, array_keys($p)) !== []) {
            throw new ProtocolError('bad_token');
        }
        if (
            $p['v'] !== 1
            || !is_string($p['mirror'])
            || !is_string($p['upload']) || preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $p['upload']) !== 1
            || !is_string($p['sha256']) || preg_match('/\A[0-9a-f]{64}\z/', $p['sha256']) !== 1
            || !is_int($p['size']) || $p['size'] < 1
            || !is_string($p['filename']) || !Filename::isValid($p['filename'])
            || !in_array($p['kind'], ['map', 'game'], true)
            || !is_int($p['start_by'])
        ) {
            throw new ProtocolError('bad_token');
        }

        // Compared only after the signature is known good, so an attacker learns nothing from this error.
        if (!hash_equals($mirrorId, $p['mirror'])) {
            throw new ProtocolError('wrong_mirror');
        }

        return new self($p['mirror'], $p['upload'], $p['sha256'], $p['size'], $p['filename'], $p['kind'], $p['start_by']);
    }

    /** @throws ProtocolError expired once the start-by time has passed */
    public function checkStartBy(int $now): void
    {
        if ($now > $this->startBy) {
            throw new ProtocolError('expired');
        }
    }
}
