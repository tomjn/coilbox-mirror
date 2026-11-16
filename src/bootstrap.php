<?php

declare(strict_types=1);

// Loads classes in the Mirror namespace from this folder. The mirror has no
// runtime packages, so it does not use Composer's autoloader.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Mirror\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
