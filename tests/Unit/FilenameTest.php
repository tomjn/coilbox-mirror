<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\Filename;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilenameTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function realNames(): array
    {
        return [
            'spaces, brackets, accent' => ['Test Map (v1.2) é.sd7'],
            'upper case extension' => ['game-1.0.SDZ'],
            'plus and underscore' => ['a_b+c.sd7'],
            'script word inside' => ['x.php.sd7'],
            'longest name' => [str_repeat('a', 251) . '.sd7'],
        ];
    }

    #[DataProvider('realNames')]
    public function test_accepts_real_map_names(string $name): void
    {
        $this->assertTrue(Filename::isValid($name));
    }

    /** @return array<string, array{string}> */
    public static function unsafeNames(): array
    {
        return [
            'parent folder' => ['../x.sd7'],
            'slash' => ['a/b.sd7'],
            'backslash' => ['a\\b.sd7'],
            'leading dot' => ['.hidden.sd7'],
            'NUL' => ["a\0.sd7"],
            'newline' => ["a\n.sd7"],
            'trailing newline' => ["a.sd7\n"],
            'tab' => ["a\t.sd7"],
            'DEL' => ["a\x7f.sd7"],
            'C1 control' => ["a\u{85}.sd7"],
            'wrong extension' => ['x.zip'],
            'script after extension' => ['x.sd7.php'],
            'extension only' => ['.sd7'],
            'empty' => [''],
            'too long' => [str_repeat('a', 252) . '.sd7'],
            'invalid UTF-8' => ["a\xff.sd7"],
        ];
    }

    #[DataProvider('unsafeNames')]
    public function test_rejects_unsafe_names(string $name): void
    {
        $this->assertFalse(Filename::isValid($name));
    }
}
