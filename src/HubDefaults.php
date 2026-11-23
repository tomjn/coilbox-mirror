<?php

declare(strict_types=1);

namespace Mirror;

/**
 * The hub setup.php points new mirrors at. The key is the hub's published
 * public key, from https://hub.coilbox.org/api/v1/mirrors/hub-key. When the hub
 * rotates its key, update it here so new mirrors trust the new one. Mirrors
 * already running learn new keys from the signed catalogue.
 */
final class HubDefaults
{
    public const URL = 'https://hub.coilbox.org';

    public const PUBLIC_KEY = 'ltMy8SANz6wC0iNoWxTYtZPm-sTYJyKDofJuhI70gXQ';
}
