<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    /** @return array{string, string} secret key, public key */
    private function keys(): array
    {
        $pair = sodium_crypto_sign_keypair();
        return [sodium_crypto_sign_secretkey($pair), sodium_crypto_sign_publickey($pair)];
    }

    public function test_signature_verifies_with_same_context(): void
    {
        [$sk, $pk] = $this->keys();
        $sig = Crypto::sign(Crypto::CTX_TOKEN, 'm', $sk);
        $this->assertSame(64, strlen($sig));
        $this->assertTrue(Crypto::verify(Crypto::CTX_TOKEN, 'm', $sig, $pk));
    }

    public function test_signature_fails_under_other_context(): void
    {
        [$sk, $pk] = $this->keys();
        $sig = Crypto::sign(Crypto::CTX_TOKEN, 'm', $sk);
        $this->assertFalse(Crypto::verify(Crypto::CTX_CATALOGUE, 'm', $sig, $pk));
    }

    public function test_tampered_message_fails(): void
    {
        [$sk, $pk] = $this->keys();
        $sig = Crypto::sign(Crypto::CTX_TOKEN, 'm', $sk);
        $this->assertFalse(Crypto::verify(Crypto::CTX_TOKEN, 'n', $sig, $pk));
    }

    public function test_wrong_length_key_returns_false(): void
    {
        [$sk, $pk] = $this->keys();
        $sig = Crypto::sign(Crypto::CTX_TOKEN, 'm', $sk);
        $this->assertFalse(Crypto::verify(Crypto::CTX_TOKEN, 'm', $sig, 'short'));
        $this->assertFalse(Crypto::verify(Crypto::CTX_TOKEN, 'm', 'short', $pk));
    }
}
