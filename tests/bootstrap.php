<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'MagicLink\\' => __DIR__ . '/../src/MagicLink/',
        'MagicLink\\Tests\\' => __DIR__ . '/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $path = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    }
});
