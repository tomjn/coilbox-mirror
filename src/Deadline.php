<?php

declare(strict_types=1);

namespace Mirror;

use Closure;

/** Tracks the run time limit so a long job stops before PHP kills it. */
final class Deadline
{
    private float $start;
    private float $longestStep = 0.0;

    /** @param Closure(): float $clock returns the time in seconds */
    public function __construct(
        private readonly int $limitSeconds,
        private readonly Closure $clock,
    ) {
        $this->start = ($this->clock)();
    }

    public static function fromIni(): self
    {
        return new self((int) ini_get('max_execution_time'), static fn (): float => microtime(true));
    }

    /** Runs $fn and records how long it took. */
    public function step(callable $fn): mixed
    {
        $before = ($this->clock)();
        try {
            return $fn();
        } finally {
            $this->longestStep = max($this->longestStep, ($this->clock)() - $before);
        }
    }

    /** True once another step as long as the longest so far could pass the limit. A limit of 0 means no limit. */
    public function expired(): bool
    {
        if ($this->limitSeconds <= 0) {
            return false;
        }
        return (($this->clock)() - $this->start) + $this->longestStep >= $this->limitSeconds;
    }
}
