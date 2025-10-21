<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MagicLink\Tests\SkippedTest;
use MagicLink\Tests\TestCase;

$testFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    if (!str_ends_with($file->getFilename(), 'Test.php')) {
        continue;
    }

    $testFiles[] = $file->getPathname();
}

sort($testFiles);

$declaredBefore = get_declared_classes();

foreach ($testFiles as $file) {
    require_once $file;
}

$declaredAfter = get_declared_classes();
$newClasses = array_diff($declaredAfter, $declaredBefore);

$testClasses = array_values(array_filter($newClasses, static function (string $class): bool {
    return is_subclass_of($class, TestCase::class);
}));

sort($testClasses);

$total = 0;
$passed = 0;
$failed = 0;
$skipped = 0;
$failures = [];

foreach ($testClasses as $class) {
    $reflection = new ReflectionClass($class);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }

        $total++;
        /** @var TestCase $instance */
        $instance = $reflection->newInstance();

        try {
            $instance->runTestMethod($method->getName());
            $passed++;
            echo '.';
        } catch (SkippedTest $skip) {
            $skipped++;
            echo 'S';
        } catch (AssertionError $assertionError) {
            $failed++;
            $failures[] = [
                'class' => $class,
                'method' => $method->getName(),
                'message' => $assertionError->getMessage(),
            ];
            echo 'F';
        } catch (Throwable $throwable) {
            $failed++;
            $failures[] = [
                'class' => $class,
                'method' => $method->getName(),
                'message' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ];
            echo 'E';
        }
    }
}

echo PHP_EOL;
printf(
    "Total: %d, Passed: %d, Failed: %d, Skipped: %d" . PHP_EOL,
    $total,
    $passed,
    $failed,
    $skipped
);

if ($failures !== []) {
    echo PHP_EOL . "Failures:" . PHP_EOL;
    foreach ($failures as $index => $failure) {
        $number = $index + 1;
        printf(
            "%d) %s::%s" . PHP_EOL . "   %s" . PHP_EOL,
            $number,
            $failure['class'],
            $failure['method'],
            $failure['message']
        );

        if (isset($failure['trace'])) {
            echo "   Stack trace:" . PHP_EOL;
            foreach (explode(PHP_EOL, $failure['trace']) as $line) {
                echo "     " . $line . PHP_EOL;
            }
        }

        echo PHP_EOL;
    }
}

exit($failed > 0 ? 1 : 0);
