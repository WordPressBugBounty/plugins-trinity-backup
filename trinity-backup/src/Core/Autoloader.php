<?php

declare(strict_types=1);

namespace TrinityBackup\Core;

if (!\defined('ABSPATH')) {
    exit;
}

final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register(function (string $class): void {
            $prefix = 'TrinityBackup\\';
            $baseDir = __DIR__ . '/../';

            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

            if (is_file($file)) {
                require $file;
            }
        });
    }
}
