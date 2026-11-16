<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\B64;
use PHPUnit\Framework\TestCase;

final class B64Test extends TestCase
{
    public function test_round_trip_of_all_byte_values(): void
    {
        $input = implode('', array_map('chr', range(0, 255)));
        $this->assertSame($input, B64::decode(B64::encode($input)));
    }

    public function test_rejects_padding_and_standard_alphabet(): void
    {
        $this->assertNull(B64::decode('AA=='));
        $this->assertNull(B64::decode('a+b/'));
    }
}
