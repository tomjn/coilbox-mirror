<?php

declare(strict_types=1);

namespace Mirror\Tests\Support;

use Mirror\B64;
use Mirror\Crypto;
use Mirror\Json;

/** Makes deterministic test keys and signs tokens and other hub documents. */
final class Signer
{
    /** @return array{secret: string, public: string} raw keys from a 32 byte seed of one repeated byte */
    public static function keypair(int $seedByte): array
    {
        $pair = sodium_crypto_sign_seed_keypair(str_repeat(chr($seedByte), SODIUM_CRYPTO_SIGN_SEEDBYTES));
        return [
            'secret' => sodium_crypto_sign_secretkey($pair),
            'public' => sodium_crypto_sign_publickey($pair),
        ];
    }

    /** Returns the full token string for a payload array. */
    public static function token(array $payload, string $secret): string
    {
        $json = Json::encode($payload);
        return B64::encode($json) . '.' . B64::encode(Crypto::sign(Crypto::CTX_TOKEN, $json, $secret));
    }

    /** Returns the base64url signature text for the context and bytes. */
    public static function sign(string $ctx, string $bytes, string $secret): string
    {
        return B64::encode(Crypto::sign($ctx, $bytes, $secret));
    }
}
