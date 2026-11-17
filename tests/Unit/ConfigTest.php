<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\B64;
use Mirror\Config;
use Mirror\ConfigError;
use Mirror\Tests\Support\TempMirror;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(string $secret = ''): array
    {
        $keypair = sodium_crypto_sign_keypair();
        return [
            'mirror_id' => 'jj-darkstars',
            'secret_key' => B64::encode($secret !== '' ? $secret : sodium_crypto_sign_secretkey($keypair)),
            'hub_url' => 'https://hub.coilbox.org',
            'hub_keys' => [B64::encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))],
            'partial_lifetime' => 86400,
        ];
    }

    public function test_valid_config_loads_and_derives_public_key(): void
    {
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $a = self::valid($secret);
        $config = Config::fromArray($a);

        $this->assertSame('jj-darkstars', $config->mirrorId);
        $this->assertSame($secret, $config->secretKey);
        $this->assertSame(sodium_crypto_sign_publickey_from_secretkey($secret), $config->publicKey);
        $this->assertSame('https://hub.coilbox.org', $config->hubUrl);
        $this->assertSame([B64::decode($a['hub_keys'][0])], $config->hubKeys);
        $this->assertSame(86400, $config->partialLifetime);
    }

    public function test_sync_time_limit_is_optional_and_defaults_to_no_limit(): void
    {
        $this->assertSame(0, Config::fromArray(self::valid())->syncTimeLimit);
        $a = self::valid();
        $a['sync_time_limit'] = 3000;
        $this->assertSame(3000, Config::fromArray($a)->syncTimeLimit);
    }

    public function test_trailing_slash_is_removed_from_hub_url(): void
    {
        $a = self::valid();
        $a['hub_url'] = 'https://hub.coilbox.org/';
        $this->assertSame('https://hub.coilbox.org', Config::fromArray($a)->hubUrl);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidValues(): array
    {
        $key32 = B64::encode(str_repeat('a', 32));
        return [
            'mirror_id uppercase' => ['mirror_id', 'Bad'],
            'mirror_id empty' => ['mirror_id', ''],
            'mirror_id leading hyphen' => ['mirror_id', '-x'],
            'mirror_id not a string' => ['mirror_id', 5],
            'mirror_id trailing newline' => ['mirror_id', "abc\n"],
            'mirror_id embedded space' => ['mirror_id', 'a b'],
            'secret_key not base64url' => ['secret_key', '***'],
            'secret_key wrong length' => ['secret_key', B64::encode(str_repeat('a', 32))],
            'secret_key not a string' => ['secret_key', null],
            'hub_url not a url' => ['hub_url', 'hub.coilbox.org'],
            'hub_url ftp' => ['hub_url', 'ftp://hub.coilbox.org'],
            'hub_url not a string' => ['hub_url', 5],
            'hub_url https trailing newline' => ['hub_url', "https://h.org\n"],
            'hub_url localhost trailing newline' => ['hub_url', "http://localhost\n"],
            'hub_url loopback trailing newline' => ['hub_url', "http://127.0.0.1:8001\n"],
            'hub_url https embedded space' => ['hub_url', 'https://h.org/a b'],
            'hub_url https embedded tab' => ['hub_url', "https://h.org\t/a"],
            'hub_url localhost embedded space' => ['hub_url', 'http://localhost/a b'],
            'hub_keys empty' => ['hub_keys', []],
            'hub_keys not a list' => ['hub_keys', 'abc'],
            'hub_keys wrong length' => ['hub_keys', [B64::encode(str_repeat('a', 31))]],
            'hub_keys bad base64url' => ['hub_keys', ['***']],
            'hub_keys one bad of two' => ['hub_keys', [$key32, '***']],
            'partial_lifetime zero' => ['partial_lifetime', 0],
            'partial_lifetime negative' => ['partial_lifetime', -5],
            'partial_lifetime string' => ['partial_lifetime', '86400'],
            'sync_time_limit negative' => ['sync_time_limit', -1],
            'sync_time_limit string' => ['sync_time_limit', '3000'],
            'sync_time_limit float' => ['sync_time_limit', 1.5],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_each_invalid_key_names_itself_in_the_error(string $key, mixed $value): void
    {
        $a = self::valid();
        $a[$key] = $value;
        try {
            Config::fromArray($a);
            $this->fail("Expected ConfigError for $key");
        } catch (ConfigError $e) {
            $this->assertStringContainsString($key, $e->getMessage());
        }
    }

    public function test_missing_key_names_itself_in_the_error(): void
    {
        $a = self::valid();
        unset($a['hub_url']);
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('hub_url');
        Config::fromArray($a);
    }

    public function test_plain_http_is_refused_except_for_localhost(): void
    {
        $a = self::valid();
        $a['hub_url'] = 'http://hub.example';
        try {
            Config::fromArray($a);
            $this->fail('Expected ConfigError');
        } catch (ConfigError $e) {
            $this->assertStringContainsString('hub_url', $e->getMessage());
        }

        foreach (['http://127.0.0.1:8001', 'http://localhost', 'http://localhost:9000/'] as $url) {
            $a['hub_url'] = $url;
            $this->assertSame(rtrim($url, '/'), Config::fromArray($a)->hubUrl);
        }

        $a['hub_url'] = 'http://127.0.0.1.evil.example';
        $this->expectException(ConfigError::class);
        Config::fromArray($a);
    }

    public function test_missing_file_names_the_file(): void
    {
        $paths = TempMirror::create();
        try {
            $this->expectException(ConfigError::class);
            $this->expectExceptionMessage('private/config.php');
            Config::load($paths);
        } finally {
            TempMirror::destroy($paths);
        }
    }

    public function test_file_that_does_not_return_an_array_names_the_file(): void
    {
        $paths = TempMirror::create();
        try {
            file_put_contents($paths->config(), "<?php\nreturn 'nope';\n");
            $this->expectException(ConfigError::class);
            $this->expectExceptionMessage('private/config.php');
            Config::load($paths);
        } finally {
            TempMirror::destroy($paths);
        }
    }

    public function test_load_reads_the_file(): void
    {
        $paths = TempMirror::create();
        try {
            file_put_contents($paths->config(), "<?php\nreturn " . var_export(self::valid(), true) . ";\n");
            $this->assertSame('jj-darkstars', Config::load($paths)->mirrorId);
        } finally {
            TempMirror::destroy($paths);
        }
    }
}
