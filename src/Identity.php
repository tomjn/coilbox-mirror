<?php

declare(strict_types=1);

namespace Mirror;

/**
 * The answer identity.php gives. The hub sends a random challenge, and the mirror
 * signs it together with its own base URL, which proves the server at that
 * address holds the secret key. Signing the address too means another server
 * cannot pass by relaying this mirror's answer under its own address.
 */
final class Identity
{
    /** @param mixed $challenge the challenge query value as PHP received it */
    public static function respond(?Config $config, mixed $challenge): Response
    {
        if ($config === null || $config->baseUrl === null) {
            return new Response(404, ['error' => 'not_set_up']);
        }

        $body = [
            'mirror_id' => $config->mirrorId,
            'base_url' => $config->baseUrl,
            'public_key' => B64::encode($config->publicKey),
        ];
        if ($challenge === null) {
            return new Response(200, $body);
        }

        $raw = is_string($challenge) ? B64::decode($challenge) : null;
        if ($raw === null || strlen($raw) !== 32) {
            return new Response(400, ['error' => 'bad_challenge']);
        }
        $body['sig'] = B64::encode(Crypto::sign(Crypto::CTX_IDENTITY, $raw . $config->baseUrl, $config->secretKey));

        return new Response(200, $body);
    }
}
