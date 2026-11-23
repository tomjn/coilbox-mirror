<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\B64;
use Mirror\Config;
use Mirror\HubDefaults;
use Mirror\Paths;
use Mirror\Setup;
use Mirror\Tests\Support\TempMirror;
use PHPUnit\Framework\TestCase;

final class SetupTest extends TestCase
{
    private Paths $paths;

    protected function setUp(): void
    {
        $this->paths = TempMirror::create();
    }

    protected function tearDown(): void
    {
        TempMirror::destroy($this->paths);
    }

    private static function input(array $overrides = []): array
    {
        return $overrides + ['mirror_id' => 'my-mirror', 'mirror_name' => 'My Mirror', 'base_url' => 'https://mirror.example.org/'];
    }

    public function test_the_production_hub_key_is_a_public_key(): void
    {
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen((string) B64::decode(HubDefaults::PUBLIC_KEY)));
        $this->assertSame('https://hub.coilbox.org', HubDefaults::URL);
    }

    public function test_valid_input_is_trimmed_and_has_no_errors(): void
    {
        $checked = Setup::validate(self::input(['mirror_id' => ' my-mirror ']));

        $this->assertSame([], $checked['errors']);
        $this->assertSame(['mirror_id' => 'my-mirror', 'mirror_name' => 'My Mirror', 'base_url' => 'https://mirror.example.org'], $checked['values']);
    }

    public function test_each_rule_names_its_field(): void
    {
        foreach ([
            ['mirror_id', 'Bad_ID'], ['mirror_id', '-x'], ['mirror_id', str_repeat('a', 65)], ['mirror_id', ['x']],
            ['mirror_name', ''], ['mirror_name', str_repeat('n', 129)], ['mirror_name', "a\nb"], ['mirror_name', "\xff"],
            ['base_url', 'http://mirror.example.org'], ['base_url', 'https://u:p@mirror.example.org'], ['base_url', 'https://mirror.example.org?x=1'], ['base_url', ''],
        ] as [$field, $value]) {
            $errors = Setup::validate(self::input([$field => $value]))['errors'];
            $this->assertSame([$field], array_keys($errors), $field . ' ' . var_export($value, true));
        }
    }

    public function test_hostile_name_round_trips(): void
    {
        $name = "'];system('id');//\" ?> <?php echo 1; \\' \\\\";
        $checked = Setup::validate(self::input(['mirror_name' => $name]));
        $this->assertSame([], $checked['errors']);
        $secret = Setup::newSecretKey();

        $this->assertTrue(Setup::write($this->paths, Setup::configSource($checked['values'], $secret)));
        $config = Config::load($this->paths);

        $this->assertSame($name, $config->mirrorName);
        $this->assertSame('my-mirror', $config->mirrorId);
        $this->assertSame('https://mirror.example.org', $config->baseUrl);
        $this->assertSame(B64::decode($secret), $config->secretKey);
        $this->assertSame([B64::decode(HubDefaults::PUBLIC_KEY)], $config->hubKeys);
        $this->assertSame(HubDefaults::URL, $config->hubUrl);
    }

    public function test_write_refuses_existing_file(): void
    {
        $this->assertTrue(Setup::write($this->paths, "<?php return ['first'];\n"));
        $this->assertFalse(Setup::write($this->paths, "<?php return ['second'];\n"));

        $this->assertSame(['first'], require $this->paths->config());
    }

    public function test_base_url_comes_from_the_request(): void
    {
        $this->assertSame('https://mirror.example.org', Setup::baseUrlFromServer(['HTTPS' => 'on', 'HTTP_HOST' => 'mirror.example.org', 'SCRIPT_NAME' => '/setup.php']));
        $this->assertSame('https://example.org/mirror', Setup::baseUrlFromServer(['HTTPS' => '1', 'HTTP_HOST' => 'example.org', 'SCRIPT_NAME' => '/mirror/setup.php']));
        $this->assertSame('http://example.org', Setup::baseUrlFromServer(['HTTPS' => 'off', 'HTTP_HOST' => 'example.org', 'SCRIPT_NAME' => '/setup.php']));
    }

    public function test_registration_link_carries_the_details_encoded(): void
    {
        $values = Setup::validate(self::input(['mirror_name' => 'A & B #1']))['values'];
        Setup::write($this->paths, Setup::configSource($values, Setup::newSecretKey()));
        $config = Config::load($this->paths);

        $link = Setup::registrationLink($config);

        $this->assertStringStartsWith('https://hub.coilbox.org/mirrors/new?', $link);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $this->assertSame(['id' => 'my-mirror', 'name' => 'A & B #1', 'url' => 'https://mirror.example.org', 'key' => B64::encode($config->publicKey)], $query);
    }

    public function test_no_link_without_base_url_or_name(): void
    {
        $config = Config::fromArray([
            'mirror_id' => 'my-mirror', 'secret_key' => Setup::newSecretKey(), 'hub_url' => 'https://hub.coilbox.org',
            'hub_keys' => [B64::encode(random_bytes(32))], 'partial_lifetime' => 86400,
        ]);

        $this->assertNull(Setup::registrationLink($config));
    }
}
