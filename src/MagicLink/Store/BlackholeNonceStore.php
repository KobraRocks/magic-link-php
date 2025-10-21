<?php

declare(strict_types=1);

namespace MagicLink\Store;

final class BlackholeNonceStore implements NonceStoreInterface
{
    public function consume(string $jti, int $expiresAt): bool
    {
        return true;
    }
}
