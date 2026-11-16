<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\Lock;
use Mirror\Tests\Support\TempMirror;
use PHPUnit\Framework\TestCase;

final class LockTest extends TestCase
{
    public function test_second_non_blocking_acquire_returns_null(): void
    {
        $paths = TempMirror::create();
        try {
            $file = $paths->state() . '/test.lock';
            $first = Lock::acquire($file, false);
            $this->assertNotNull($first);
            $this->assertNull(Lock::acquire($file, false));
            $first->release();
            $again = Lock::acquire($file, false);
            $this->assertNotNull($again);
            $again->release();
        } finally {
            TempMirror::destroy($paths);
        }
    }
}
