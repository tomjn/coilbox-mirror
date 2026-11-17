<?php

// Copy this file to private/config.php and fill it in. Never commit private/config.php.

return [
    // A short name for this mirror: lowercase letters, digits and hyphens, up to 64 characters.
    // The hub operator assigns it.
    'mirror_id' => 'my-mirror',

    // Your mirror's secret signing key, base64url. Make one with `php bin/keygen.php`.
    // Keep it private, and send the hub operator the public key that the script prints.
    'secret_key' => 'PASTE_THE_LINE_FROM_KEYGEN_HERE',

    // Where the hub lives. Must be https://. No trailing slash needed.
    'hub_url' => 'https://hub.coilbox.org',

    // The hub's public signing keys, base64url, one per entry. The hub operator gives you these.
    'hub_keys' => [
        'PASTE_THE_HUB_PUBLIC_KEY_HERE',
    ],

    // How long an unfinished upload is kept before it is deleted, in seconds. 86400 is 24 hours.
    'partial_lifetime' => 86400,

    // The longest a cron run of sync.php may take, in seconds, chosen by you. 0 means no limit.
    // The command-line PHP usually has no time limit of its own, so a peer that sends data very slowly can hold
    // up a run for good. With a limit set, a run stops fetching before the limit, and a later run that finds an
    // older run still going past the limit prints a failure line, so cron emails you.
    'sync_time_limit' => 0,
];
