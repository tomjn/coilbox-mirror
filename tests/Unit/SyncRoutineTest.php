<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\Sync;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** sync.php stays quiet for routine lines only, so these texts must match what Sync logs. */
final class SyncRoutineTest extends TestCase
{
    #[DataProvider('routineLines')]
    public function test_routine_lines_are_quiet(string $line): void
    {
        $this->assertTrue(Sync::isRoutine($line));
    }

    #[DataProvider('failureLines')]
    public function test_other_lines_count_as_failures(string $line): void
    {
        $this->assertFalse(Sync::isRoutine($line));
    }

    public static function routineLines(): array
    {
        return [
            ['another sync is running'],
            ['catalogue accepted from hub'],
            ['catalogue accepted from peer:a'],
            ['catalogue from hub rejected: not_newer'],
            ['catalogue from peer:a rejected: not_newer'],
            ['fetched maps/Test Map.sd7'],
            ['removed maps/Test Map.sd7'],
            ['removed .partial/sync-abc'],
            ['maps/Test Map.sd7 is already present'],
        ];
    }

    public static function failureLines(): array
    {
        return [
            ['catalogue from hub failed: status 0'],
            ['catalogue from hub failed: signature status 500'],
            ['catalogue from hub rejected: bad_signature'],
            ['catalogue from peer:a rejected: malformed'],
            ['could not remove maps/x.sd7'],
            ['conflict: x.sd7 is already here with different bytes, not downloaded'],
            ['listing from peer:a skipped, missing, malformed or over 100 bytes'],
            ['fetch stopped: out of time'],
            ['download of maps/x.sd7 from http://a failed'],
            ['hash mismatch for maps/x.sd7 from http://a'],
            ['sync report failed: the hub did not answer with a 2xx status'],
            ['fetch failed: boom'],
            ['sync has been running for over 3000 seconds'],
        ];
    }
}
