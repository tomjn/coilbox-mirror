<?php

declare(strict_types=1);

namespace Mirror;

final class Lock
{
    /** @param resource $handle */
    private function __construct(private $handle)
    {
    }

    /** Returns null when $wait is false and another handle holds the lock. */
    public static function acquire(string $path, bool $wait): ?Lock
    {
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open lock file $path");
        }
        if (!flock($handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return new self($handle);
    }

    /** Replaces the lock file's contents. Returns false when the write fails. */
    public function write(string $text): bool
    {
        return is_resource($this->handle)
            && ftruncate($this->handle, 0)
            && rewind($this->handle)
            && fwrite($this->handle, $text) === strlen($text)
            && fflush($this->handle);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
