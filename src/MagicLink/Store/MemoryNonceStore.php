<?php

declare(strict_types=1);

namespace MagicLink\Store;

final class MemoryNonceStore implements NonceStoreInterface
{
    /** @var array<string, int> */
    private array $consumed = [];

    public function consume(string $jti, int $expiresAt): bool
    {
        $now = time();
        foreach ($this->consumed as $key => $expiry) {
            if ($expiry <= $now) {
                unset($this->consumed[$key]);
            }
        }

        if (isset($this->consumed[$jti])) {
            return false;
        }

        $this->consumed[$jti] = $expiresAt;

        return true;
    }
}
