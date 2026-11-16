<?php

declare(strict_types=1);

namespace Mirror\Tests\Support;

use Mirror\Paths;

final class TempMirror
{
    public static function create(): Paths
    {
        $root = sys_get_temp_dir() . '/coilbox-mirror-' . bin2hex(random_bytes(8));
        foreach (['maps', 'games', '.partial', 'private/state/used'] as $dir) {
            mkdir("$root/$dir", 0777, true);
        }
        return new Paths($root);
    }

    public static function destroy(Paths $paths): void
    {
        self::remove($paths->root());
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove("$path/$name");
            }
        }
        rmdir($path);
    }
}
