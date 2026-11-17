<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\Deadline;
use PHPUnit\Framework\TestCase;

final class DeadlineTest extends TestCase
{
    private string|false $oldLimit = false;

    protected function setUp(): void
    {
        $this->oldLimit = ini_get('max_execution_time');
    }

    protected function tearDown(): void
    {
        ini_set('max_execution_time', $this->oldLimit === false ? '0' : $this->oldLimit);
    }

    public function test_from_ini_uses_the_config_limit_when_php_has_none(): void
    {
        ini_set('max_execution_time', '0');
        $this->assertSame(3000, Deadline::fromIni(3000)->limitSeconds);
    }

    public function test_from_ini_has_no_limit_when_neither_sets_one(): void
    {
        ini_set('max_execution_time', '0');
        $this->assertSame(0, Deadline::fromIni(0)->limitSeconds);
    }

    public function test_from_ini_prefers_the_php_limit_when_there_is_one(): void
    {
        ini_set('max_execution_time', '50');
        $this->assertSame(50, Deadline::fromIni(3000)->limitSeconds);
    }

    public function test_zero_limit_never_expires(): void
    {
        $now = 0.0;
        $d = new Deadline(0, function () use (&$now): float {
            return $now;
        });
        $d->step(function () use (&$now): void {
            $now += 1000.0;
        });
        $now = 1000000.0;
        $this->assertFalse($d->expired());
    }

    public function test_expires_when_elapsed_plus_longest_step_reaches_limit(): void
    {
        $now = 0.0;
        $d = new Deadline(10, function () use (&$now): float {
            return $now;
        });
        $result = $d->step(function () use (&$now): string {
            $now += 3.0;
            return 'done';
        });
        $this->assertSame('done', $result);

        $now = 6.9;
        $this->assertFalse($d->expired());
        $now = 7.0;
        $this->assertTrue($d->expired());
    }

    public function test_longest_step_is_kept_when_a_shorter_one_follows(): void
    {
        $now = 0.0;
        $d = new Deadline(10, function () use (&$now): float {
            return $now;
        });
        $d->step(function () use (&$now): void {
            $now += 3.0;
        });
        $d->step(function () use (&$now): void {
            $now += 1.0;
        });
        $now = 6.9;
        $this->assertFalse($d->expired());
        $now = 7.0;
        $this->assertTrue($d->expired());
    }
}
