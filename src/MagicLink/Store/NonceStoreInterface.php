<?php

declare(strict_types=1);

namespace MagicLink\Store;

interface NonceStoreInterface
{
    public function consume(string $jti, int $expiresAt): bool;
}
