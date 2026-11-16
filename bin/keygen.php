<?php

declare(strict_types=1);

// Prints a new secret_key line for private/config.php and the public key to send
// to the hub operator. It writes no files.

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
$public = sodium_crypto_sign_publickey_from_secretkey($secret);

echo "Add this line to private/config.php:\n\n";
echo "    'secret_key' => '" . Mirror\B64::encode($secret) . "',\n\n";
echo "Send this public key to the hub operator:\n\n";
echo Mirror\B64::encode($public) . "\n";
