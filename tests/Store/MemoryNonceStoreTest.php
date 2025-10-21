<?php

declare(strict_types=1);

namespace MagicLink\Tests\Store;

use MagicLink\Store\MemoryNonceStore;
use MagicLink\Tests\TestCase;

final class MemoryNonceStoreTest extends TestCase
{
    public function testConsumeIsIdempotentUntilExpiry(): void
    {
        $store = new MemoryNonceStore();
        $nonce = 'abc123';
        $futureExpiry = time() + 60;

        self::assertTrue($store->consume($nonce, $futureExpiry));
        self::assertFalse($store->consume($nonce, $futureExpiry));
    }

    public function testExpiredNonceCanBeConsumedAgain(): void
    {
        $store = new MemoryNonceStore();
        $nonce = 'expired';

        self::assertTrue($store->consume($nonce, time() - 10));
        self::assertTrue($store->consume($nonce, time() + 10));
    }
}
