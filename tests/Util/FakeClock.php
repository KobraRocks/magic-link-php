<?php

declare(strict_types=1);

namespace MagicLink\Tests\Util;

use MagicLink\Core\Clock;

final class FakeClock implements Clock
{
    public function __construct(private int $now)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function setNow(int $now): void
    {
        $this->now = $now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
