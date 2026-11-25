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
            'right-to-left override' => ["a\u{202E}7ds.exe.sd7"],
            'zero width space' => ["a\u{200B}.sd7"],
            'byte order mark' => ["\u{FEFF}a.sd7"],
            'soft hyphen' => ["a\u{AD}b.sd7"],
            'line separator' => ["a\u{2028}.sd7"],
            'paragraph separator' => ["a\u{2029}.sd7"],
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

    /** @return array<string, array{string, string, bool}> */
    public static function namesByKind(): array
    {
        return [
            'engine release' => ['engine', 'recoil_2026.09.03_arm64-macos.7z', true],
            'engine test build version' => ['engine', 'recoil_2026.09.01-137-g15f18d5+mac_amd64-linux.7z', true],
            'engine windows' => ['engine', 'recoil_2026.09.03_amd64-windows.7z', true],
            'engine longest version' => ['engine', 'recoil_' . str_repeat('1', 64) . '_arm64-macos.7z', true],
            'engine upper case prefix' => ['engine', 'RECOIL_2026.09.03_arm64-macos.7z', false],
            'engine upper case extension' => ['engine', 'recoil_2026.09.03_arm64-macos.7Z', false],
            'engine unknown platform' => ['engine', 'recoil_2026.09.03_riscv-linux.7z', false],
            'engine version too long' => ['engine', 'recoil_' . str_repeat('1', 65) . '_arm64-macos.7z', false],
            'engine empty version' => ['engine', 'recoil__arm64-macos.7z', false],
            'engine version with slash' => ['engine', 'recoil_a/b_arm64-macos.7z', false],
            'engine trailing newline' => ['engine', "recoil_2026.09.03_arm64-macos.7z\n", false],
            'engine with a map name' => ['engine', 'map.sd7', false],
            'map with an engine name' => ['map', 'recoil_2026.09.03_arm64-macos.7z', false],
            'map' => ['map', 'Test Map.sd7', true],
            'game' => ['game', 'game-1.0.SDZ', true],
            'unknown kind' => ['mod', 'x.sd7', false],
        ];
    }

    #[DataProvider('namesByKind')]
    public function test_names_are_checked_by_kind(string $kind, string $name, bool $valid): void
    {
        $this->assertSame($valid, Filename::isValidFor($kind, $name));
    }
}
