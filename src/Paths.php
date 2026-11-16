<?php

declare(strict_types=1);

namespace Mirror;

final class Paths
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function maps(): string
    {
        return $this->root . '/maps';
    }

    public function games(): string
    {
        return $this->root . '/games';
    }

    /** Returns the folder for a kind, which is "map" or "game". */
    public function dirFor(string $kind): string
    {
        return match ($kind) {
            'map' => $this->maps(),
            'game' => $this->games(),
            default => throw new \InvalidArgumentException("Unknown kind: $kind"),
        };
    }

    public function partial(): string
    {
        return $this->root . '/.partial';
    }

    public function private(): string
    {
        return $this->root . '/private';
    }

    public function state(): string
    {
        return $this->root . '/private/state';
    }

    /** Creates the state and used-marker folders when they are missing. */
    public function ensureState(): void
    {
        $used = $this->state() . '/used';
        if (!is_dir($used) && !@mkdir($used, 0700, true) && !is_dir($used)) {
            throw new \RuntimeException("Cannot create $used");
        }
    }

    public function config(): string
    {
        return $this->root . '/private/config.php';
    }

    public function listing(): string
    {
        return $this->root . '/listing.json';
    }

    public function catalogueJson(): string
    {
        return $this->root . '/catalogue.json';
    }

    public function catalogueSig(): string
    {
        return $this->root . '/catalogue.sig';
    }
}
