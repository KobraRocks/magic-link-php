<?php

declare(strict_types=1);

namespace MagicLink\Tests\Crypto;

use MagicLink\Crypto\Key;
use MagicLink\Crypto\MacSigner;
use MagicLink\Tests\TestCase;

final class MacSignerTest extends TestCase
{
    private MacSigner $signer;
    private Key $key;

    protected function setUp(): void
    {
        $this->signer = new MacSigner();
        $this->key = new Key('test', str_repeat('k', 32), time());
    }

    public function testSignProducesDeterministicOutputForSameInput(): void
    {
        $signature1 = $this->signer->sign($this->key, 'input');
        $signature2 = $this->signer->sign($this->key, 'input');

        self::assertSame($signature1, $signature2);
    }

    public function testVerifyReturnsTrueForMatchingSignature(): void
    {
        $signature = $this->signer->sign($this->key, 'payload');

        self::assertTrue($this->signer->verify($this->key, 'payload', $signature));
    }

    public function testVerifyReturnsFalseForMismatchedSignature(): void
    {
        $signature = $this->signer->sign($this->key, 'payload');

        self::assertFalse($this->signer->verify($this->key, 'different', $signature));
    }
}
