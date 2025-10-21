<?php

declare(strict_types=1);

namespace MagicLink\Tests;

use AssertionError;

class TestCase
{
    private ?string $expectedException = null;
    private ?string $expectedExceptionMessage = null;

    public function runTestMethod(string $method): void
    {
        $this->resetExpectations();

        $this->setUp();

        try {
            $this->$method();
        } catch (SkippedTest $skip) {
            $this->tearDown();
            $this->resetExpectations();

            throw $skip;
        } catch (\Throwable $throwable) {
            $this->tearDown();
            $handled = $this->handleExpectedException($throwable);
            $this->resetExpectations();

            if ($handled) {
                return;
            }

            throw $throwable;
        }

        $this->tearDown();

        if ($this->expectedException !== null) {
            throw new AssertionError(sprintf(
                'Failed asserting that exception of type %s was thrown.',
                $this->expectedException
            ));
        }

        $this->resetExpectations();
    }

    protected function setUp(): void
    {
        // Intended for override in child classes.
    }

    protected function tearDown(): void
    {
        // Intended for override in child classes.
    }

    protected function expectException(string $class): void
    {
        $this->expectedException = $class;
    }

    protected function expectExceptionMessage(string $message): void
    {
        $this->expectedExceptionMessage = $message;
    }

    protected function markTestSkipped(string $message): void
    {
        throw new SkippedTest($message);
    }

    protected function fail(string $message): void
    {
        throw new AssertionError($message);
    }

    protected static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionError(self::buildMessage($message, sprintf(
                'Failed asserting that %s is identical to %s.',
                self::export($actual),
                self::export($expected)
            )));
        }
    }

    protected static function assertTrue(mixed $value, string $message = ''): void
    {
        if ($value !== true) {
            throw new AssertionError(self::buildMessage($message, 'Failed asserting that value is true.'));
        }
    }

    protected static function assertFalse(mixed $value, string $message = ''): void
    {
        if ($value !== false) {
            throw new AssertionError(self::buildMessage($message, 'Failed asserting that value is false.'));
        }
    }

    protected static function assertNotNull(mixed $value, string $message = ''): void
    {
        if ($value === null) {
            throw new AssertionError(self::buildMessage($message, 'Failed asserting that value is not null.'));
        }
    }

    protected static function assertArrayHasKey(string|int $key, mixed $array, string $message = ''): void
    {
        if (!is_array($array) || !array_key_exists($key, $array)) {
            throw new AssertionError(self::buildMessage($message, sprintf(
                'Failed asserting that array has the key %s.',
                self::export($key)
            )));
        }
    }

    protected static function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new AssertionError(self::buildMessage($message, sprintf(
                'Failed asserting that string does not contain "%s".',
                $needle
            )));
        }
    }

    private function handleExpectedException(\Throwable $throwable): bool
    {
        if ($throwable instanceof AssertionError) {
            throw $throwable;
        }

        if ($this->expectedException === null) {
            return false;
        }

        if (!is_a($throwable, $this->expectedException)) {
            throw new AssertionError(sprintf(
                'Failed asserting that thrown exception %s is an instance of %s.',
                $throwable::class,
                $this->expectedException
            ));
        }

        if ($this->expectedExceptionMessage !== null && $throwable->getMessage() !== $this->expectedExceptionMessage) {
            throw new AssertionError(sprintf(
                'Failed asserting that exception message "%s" matches expected "%s".',
                $throwable->getMessage(),
                $this->expectedExceptionMessage
            ));
        }

        return true;
    }

    private function resetExpectations(): void
    {
        $this->expectedException = null;
        $this->expectedExceptionMessage = null;
    }

    private static function buildMessage(string $userMessage, string $default): string
    {
        return $userMessage !== '' ? $userMessage : $default;
    }

    private static function export(mixed $value): string
    {
        return var_export($value, true);
    }
}

class SkippedTest extends \Exception
{
}
