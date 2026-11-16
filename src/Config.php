<?php

declare(strict_types=1);

namespace Mirror;

final class Config
{
    /**
     * @param list<string> $hubKeys raw 32-byte public keys
     */
    private function __construct(
        public readonly string $mirrorId,
        public readonly string $secretKey,
        public readonly string $publicKey,
        public readonly string $hubUrl,
        public readonly array $hubKeys,
        public readonly int $partialLifetime,
    ) {
    }

    public static function load(Paths $p): self
    {
        $file = $p->config();
        if (!is_file($file)) {
            throw new ConfigError("Config file private/config.php is missing ($file). Copy private/config.sample.php to private/config.php and fill it in.");
        }
        $a = require $file;
        if (!is_array($a)) {
            throw new ConfigError("Config file private/config.php must return an array ($file).");
        }
        return self::fromArray($a);
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        $mirrorId = $a['mirror_id'] ?? null;
        if (!is_string($mirrorId) || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $mirrorId) !== 1) {
            throw new ConfigError('mirror_id must be 1 to 64 characters: lowercase letters, digits and hyphens, not starting with a hyphen.');
        }

        $secret = is_string($a['secret_key'] ?? null) ? B64::decode($a['secret_key']) : null;
        if ($secret === null || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new ConfigError('secret_key must be the base64url of a 64-byte Ed25519 secret key. Run bin/keygen.php to make one.');
        }

        $hubUrl = $a['hub_url'] ?? null;
        if (!is_string($hubUrl) || !self::validHubUrl($hubUrl)) {
            throw new ConfigError('hub_url must be an https:// URL (http:// is only allowed for 127.0.0.1 and localhost).');
        }

        $keys = $a['hub_keys'] ?? null;
        if (!is_array($keys) || $keys === [] || !array_is_list($keys)) {
            throw new ConfigError('hub_keys must be a non-empty list of base64url 32-byte public keys.');
        }
        $hubKeys = [];
        foreach ($keys as $i => $key) {
            $raw = is_string($key) ? B64::decode($key) : null;
            if ($raw === null || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new ConfigError("hub_keys entry $i must be a base64url 32-byte public key.");
            }
            $hubKeys[] = $raw;
        }

        $lifetime = $a['partial_lifetime'] ?? null;
        if (!is_int($lifetime) || $lifetime <= 0) {
            throw new ConfigError('partial_lifetime must be an integer greater than 0, in seconds.');
        }

        return new self(
            $mirrorId,
            $secret,
            sodium_crypto_sign_publickey_from_secretkey($secret),
            rtrim($hubUrl, '/'),
            $hubKeys,
            $lifetime,
        );
    }

    private static function validHubUrl(string $url): bool
    {
        if (preg_match('#^https://[^\s/?\#]+#', $url) === 1) {
            $parts = parse_url($url);
            return $parts !== false && ($parts['host'] ?? '') !== '' && !isset($parts['query']) && !isset($parts['fragment']);
        }
        return preg_match('#^http://(127\.0\.0\.1|localhost)(:\d{1,5})?(/.*)?$#', $url) === 1
            && !str_contains($url, '?') && !str_contains($url, '#');
    }
}
