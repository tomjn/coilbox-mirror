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
        public readonly int $syncTimeLimit,
        public readonly ?string $baseUrl = null,
        public readonly ?string $mirrorName = null,
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
        if (!is_string($mirrorId) || preg_match('/^[a-z0-9][a-z0-9-]{0,63}\z/', $mirrorId) !== 1) {
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

        // Optional, because 0 means no limit beyond PHP's own max_execution_time.
        $syncTimeLimit = $a['sync_time_limit'] ?? 0;
        if (!is_int($syncTimeLimit) || $syncTimeLimit < 0) {
            throw new ConfigError('sync_time_limit must be an integer of 0 or more, in seconds. 0 means no limit.');
        }

        // Optional, so configs written before setup.php keep loading. identity.php and bin/register.php need them.
        $baseUrl = $a['base_url'] ?? null;
        if ($baseUrl !== null && (!is_string($baseUrl) || !self::validBaseUrl($baseUrl))) {
            throw new ConfigError('base_url must be the https:// address of this mirror, with no user name, password, query or # part.');
        }
        $mirrorName = $a['mirror_name'] ?? null;
        if ($mirrorName !== null && (!is_string($mirrorName) || !self::validName($mirrorName))) {
            throw new ConfigError('mirror_name must be 1 to 128 characters with no control characters.');
        }

        return new self(
            $mirrorId,
            $secret,
            sodium_crypto_sign_publickey_from_secretkey($secret),
            rtrim($hubUrl, '/'),
            $hubKeys,
            $lifetime,
            $syncTimeLimit,
            $baseUrl === null ? null : rtrim($baseUrl, '/'),
            $mirrorName,
        );
    }

    /** An https address with a host and no user, password, query, fragment or whitespace. */
    public static function validBaseUrl(string $url): bool
    {
        if (preg_match('/\s/', $url) === 1 || str_contains($url, '?') || str_contains($url, '#')) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    /** 1 to 128 characters of valid UTF-8 with no control, format or line separator characters. */
    public static function validName(string $name): bool
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            return false;
        }
        $length = mb_strlen($name, 'UTF-8');
        return $length >= 1 && $length <= 128 && preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $name) !== 1;
    }

    private static function validHubUrl(string $url): bool
    {
        // No whitespace anywhere, and no query or fragment.
        if (preg_match('/\s/', $url) === 1 || str_contains($url, '?') || str_contains($url, '#')) {
            return false;
        }
        if (preg_match('#^https://[^/]+#', $url) === 1) {
            $parts = parse_url($url);
            return $parts !== false && ($parts['host'] ?? '') !== '';
        }
        return preg_match('#^http://(127\.0\.0\.1|localhost)(:\d{1,5})?(/.*)?\z#', $url) === 1;
    }
}
