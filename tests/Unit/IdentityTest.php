<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\B64;
use Mirror\Config;
use Mirror\Crypto;
use Mirror\Identity;
use Mirror\Tests\Support\Signer;
use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase
{
    private static function config(?string $baseUrl = 'https://mirror.example.org'): Config
    {
        return Config::fromArray([
            'mirror_id' => 'my-mirror',
            'secret_key' => B64::encode(Signer::keypair(7)['secret']),
            'hub_url' => 'https://hub.coilbox.org',
            'hub_keys' => [B64::encode(Signer::keypair(1)['public'])],
            'partial_lifetime' => 86400,
        ] + ($baseUrl === null ? [] : ['base_url' => $baseUrl]));
    }

    public function test_signs_the_challenge_with_the_base_url(): void
    {
        $challenge = random_bytes(32);

        $response = Identity::respond(self::config(), B64::encode($challenge));

        $this->assertSame(200, $response->status);
        $this->assertSame('my-mirror', $response->body['mirror_id']);
        $this->assertSame('https://mirror.example.org', $response->body['base_url']);
        $this->assertSame(B64::encode(Signer::keypair(7)['public']), $response->body['public_key']);
        $sig = B64::decode($response->body['sig']);
        $this->assertTrue(Crypto::verify(Crypto::CTX_IDENTITY, $challenge . 'https://mirror.example.org', $sig, Signer::keypair(7)['public']));
        $this->assertFalse(Crypto::verify(Crypto::CTX_IDENTITY, $challenge . 'https://other.example.org', $sig, Signer::keypair(7)['public']));
    }

    public function test_without_a_challenge_it_gives_the_key_and_no_signature(): void
    {
        $response = Identity::respond(self::config(), null);

        $this->assertSame(200, $response->status);
        $this->assertArrayNotHasKey('sig', $response->body);
    }

    public function test_a_bad_challenge_is_refused(): void
    {
        foreach (['', 'short', B64::encode(random_bytes(31)), B64::encode(random_bytes(33)), '***', ['x']] as $challenge) {
            $this->assertSame(400, Identity::respond(self::config(), $challenge)->status, json_encode($challenge));
        }
    }

    public function test_404_before_setup_or_without_a_base_url(): void
    {
        $this->assertSame(404, Identity::respond(null, B64::encode(random_bytes(32)))->status);
        $this->assertSame(404, Identity::respond(self::config(null), B64::encode(random_bytes(32)))->status);
    }
}
