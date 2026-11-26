<?php

declare(strict_types=1);

namespace Mirror;

final class Paths
{
    public const KINDS = ['map', 'game', 'engine', 'replay', 'track'];

    /**
     * Kinds with their own top-level list, named as their folder is, in the listing and the catalogue.
     * Mirrors on earlier releases reject any kind they do not know inside the shared lists.
     */
    public const OWN_LIST_KINDS = ['engine', 'replay', 'track'];

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

    public function engines(): string
    {
        return $this->root . '/engines';
    }

    public function replays(): string
    {
        return $this->root . '/replays';
    }

    public function tracks(): string
    {
        return $this->root . '/tracks';
    }

    /** Returns the folder name for a kind, relative to the root. */
    public static function folderFor(string $kind): string
    {
        return match ($kind) {
            'map' => 'maps',
            'game' => 'games',
            'engine' => 'engines',
            'replay' => 'replays',
            'track' => 'tracks',
            default => throw new \InvalidArgumentException("Unknown kind: $kind"),
        };
    }

    /** Returns the folder for a kind. */
    public function dirFor(string $kind): string
    {
        return $this->root . '/' . self::folderFor($kind);
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
