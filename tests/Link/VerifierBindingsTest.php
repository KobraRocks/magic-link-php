<?php

declare(strict_types=1);

namespace MagicLink\Tests\Link;

use MagicLink\Core\Encoder;
use MagicLink\Crypto\Key;
use MagicLink\Crypto\KeySet;
use MagicLink\Link\CreateOptions;
use MagicLink\Link\LinkBuilder;
use MagicLink\Link\Verifier;
use MagicLink\Link\VerifyOptions;
use MagicLink\Store\MemoryNonceStore;
use MagicLink\Tests\Util\FakeClock;
use MagicLink\Tests\TestCase;

final class VerifierBindingsTest extends TestCase
{
    private Key $key;
    private FakeClock $clock;
    private MemoryNonceStore $nonceStore;
    private KeySet $keySet;

    protected function setUp(): void
    {
        $this->key = new Key('bind', str_repeat('X', 32), 1_000);
        $this->clock = new FakeClock(1_000);
        $this->nonceStore = new MemoryNonceStore();
        $this->keySet = new KeySet([$this->key]);
    }

    public function testExpectedPathMismatchReturnsReason(): void
    {
        $builder = new LinkBuilder($this->keySet, $this->nonceStore, $this->clock);
        $token = $builder->createToken('subject');

        $options = new VerifyOptions();
        $options->expectedPath = '/expected';

        $verifier = new Verifier($this->keySet, $this->nonceStore, $this->clock);
        $result = $verifier->verifyToken($token, $options, null, '/actual', 'example.com');

        self::assertFalse($result->ok);
        self::assertSame('path_mismatch', $result->reason);
    }

    public function testPathBindingClaimIsEnforced(): void
    {
        $builder = new LinkBuilder($this->keySet, $this->nonceStore, $this->clock);
        $options = new CreateOptions();
        $options->pathBind = '/allowed';
        $token = $builder->createToken('subject', $options);

        $verifier = new Verifier($this->keySet, $this->nonceStore, $this->clock);
        $result = $verifier->verifyToken($token, null, null, '/forbidden', 'example.com');

        self::assertFalse($result->ok);
        self::assertSame('path_mismatch', $result->reason);
    }

    public function testExpectedHostMismatchReturnsReason(): void
    {
        $builder = new LinkBuilder($this->keySet, $this->nonceStore, $this->clock);
        $token = $builder->createToken('subject');

        $options = new VerifyOptions();
        $options->expectedHost = 'expected.test';

        $verifier = new Verifier($this->keySet, $this->nonceStore, $this->clock);
        $result = $verifier->verifyToken($token, $options, null, '/path', 'actual.test');

        self::assertFalse($result->ok);
        self::assertSame('host_mismatch', $result->reason);
    }

    public function testHostBindingClaimIsEnforced(): void
    {
        $builder = new LinkBuilder($this->keySet, $this->nonceStore, $this->clock);
        $options = new CreateOptions();
        $options->app['bind.host'] = 'bound.test';
        $token = $builder->createToken('subject', $options);

        $verifier = new Verifier($this->keySet, $this->nonceStore, $this->clock);
        $result = $verifier->verifyToken($token, null, null, '/path', 'other.test');

        self::assertFalse($result->ok);
        self::assertSame('host_mismatch', $result->reason);
    }

    public function testUserAgentBindingMismatchReturnsReason(): void
    {
        $builder = new LinkBuilder($this->keySet, $this->nonceStore, $this->clock);
        $options = new CreateOptions();
        $expectedUa = 'Expected-UA/1.0';
        $options->app['uah'] = Encoder::base64UrlEncode(hash('sha256', $expectedUa, true));
        $token = $builder->createToken('subject', $options);

        $verifyOptions = new VerifyOptions();
        $verifyOptions->enforceUaHash = true;

        $verifier = new Verifier($this->keySet, $this->nonceStore, $this->clock);
        $result = $verifier->verifyToken($token, $verifyOptions, 'Wrong-UA/2.0', '/path', 'example.com');

        self::assertFalse($result->ok);
        self::assertSame('ua_mismatch', $result->reason);
    }

    public function testReturnToAllowlistCanDeny(): void
    {
        $builder = new LinkBuilder($this->keySet, $this->nonceStore, $this->clock);
        $options = new CreateOptions();
        $options->returnTo = 'https://evil.test';
        $token = $builder->createToken('subject', $options);

        $verifyOptions = new VerifyOptions();
        $verifyOptions->returnToAllowlist = static fn (string $returnTo): bool => str_contains($returnTo, 'trusted.test');

        $verifier = new Verifier($this->keySet, $this->nonceStore, $this->clock);
        $result = $verifier->verifyToken($token, $verifyOptions, null, '/path', 'example.com');

        self::assertFalse($result->ok);
        self::assertSame('return_to_denied', $result->reason);
    }
}
