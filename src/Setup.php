<?php

declare(strict_types=1);

namespace Mirror;

/**
 * The rules behind setup.php, which writes private/config.php from a form the
 * first time someone opens it. Anyone can open it before the operator does, so
 * it never shows the secret key, never lets the visitor choose which hub key to
 * trust, and refuses to run once a config exists.
 */
final class Setup
{
    /**
     * Checks the form. The values are trimmed, with any trailing slash removed from the address.
     *
     * @param array<mixed> $input
     * @return array{values: array{mirror_id: string, mirror_name: string, base_url: string}, errors: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $text = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $values = [
            'mirror_id' => $text('mirror_id'),
            'mirror_name' => $text('mirror_name'),
            'base_url' => rtrim($text('base_url'), '/'),
        ];

        $errors = [];
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}\z/', $values['mirror_id']) !== 1) {
            $errors['mirror_id'] = 'Use 1 to 64 lowercase letters, digits and hyphens, starting with a letter or digit.';
        }
        if (!Config::validName($values['mirror_name'])) {
            $errors['mirror_name'] = 'Use 1 to 128 characters, with no line breaks or control characters.';
        }
        if (!Config::validBaseUrl($values['base_url'])) {
            $errors['base_url'] = 'Use the https:// address visitors reach this mirror at, with no user name, password, query or # part.';
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * The address this page was opened at, without setup.php, as the starting value for the form.
     *
     * @param array<mixed> $server PHP's $_SERVER
     */
    public static function baseUrlFromServer(array $server): string
    {
        $https = is_string($server['HTTPS'] ?? null) && $server['HTTPS'] !== '' && strtolower($server['HTTPS']) !== 'off';
        $host = is_string($server['HTTP_HOST'] ?? null) ? $server['HTTP_HOST'] : '';
        $script = is_string($server['SCRIPT_NAME'] ?? null) ? $server['SCRIPT_NAME'] : '/setup.php';
        $folder = rtrim(str_replace('\\', '/', dirname($script)), '/');

        return ($https ? 'https' : 'http') . '://' . $host . $folder;
    }

    /**
     * The PHP source of private/config.php. Every value goes through var_export, so
     * nothing typed into the form can end the string and run as code.
     *
     * @param array{mirror_id: string, mirror_name: string, base_url: string} $values
     */
    public static function configSource(array $values, string $secretKeyB64): string
    {
        $config = [
            'mirror_id' => $values['mirror_id'],
            'mirror_name' => $values['mirror_name'],
            'base_url' => $values['base_url'],
            'secret_key' => $secretKeyB64,
            'hub_url' => HubDefaults::URL,
            'hub_keys' => [HubDefaults::PUBLIC_KEY],
            'partial_lifetime' => 86400,
            'sync_time_limit' => 0,
        ];

        return "<?php\n\n"
            . "// Written by setup.php. Never commit this file or share secret_key.\n"
            . "// See private/config.sample.php for what each setting does.\n\n"
            . 'return ' . var_export($config, true) . ";\n";
    }

    /**
     * Creates private/config.php. Returns false, writing nothing, when it already
     * exists, so two visitors submitting at once cannot both set up the mirror.
     */
    public static function write(Paths $paths, string $source): bool
    {
        $handle = @fopen($paths->config(), 'x');
        if ($handle === false) {
            return false;
        }
        try {
            if (fwrite($handle, $source) !== strlen($source)) {
                throw new \RuntimeException('Could not write ' . $paths->config());
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    /** Makes a new key pair and returns the secret key as base64url. */
    public static function newSecretKey(): string
    {
        return B64::encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    }

    /** The hub page where a signed in user asks for this mirror to be added, or null without base_url and mirror_name. */
    public static function registrationLink(Config $config): ?string
    {
        if ($config->baseUrl === null || $config->mirrorName === null) {
            return null;
        }

        return $config->hubUrl . '/mirrors/new?' . http_build_query([
            'id' => $config->mirrorId,
            'name' => $config->mirrorName,
            'url' => $config->baseUrl,
            'key' => B64::encode($config->publicKey),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
