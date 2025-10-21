<?php

declare(strict_types=1);

namespace MagicLink\Core;

interface Clock
{
    public function now(): int;
}
