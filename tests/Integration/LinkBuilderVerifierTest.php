<?php

declare(strict_types=1);

namespace MagicLink\Tests\Integration;

use MagicLink\Core\Encoder;
use MagicLink\Crypto\Cipher;
use MagicLink\Crypto\Key;
use MagicLink\Crypto\KeySet;
use MagicLink\Link\CreateOptions;
use MagicLink\Link\LinkBuilder;
use MagicLink\Link\Verifier;
use MagicLink\Link\VerifyOptions;
use MagicLink\Store\MemoryNonceStore;
use MagicLink\Tests\Util\FakeClock;
use MagicLink\Tests\TestCase;

final class LinkBuilderVerifierTest extends TestCase
{
    public function testCreateUrlAndVerifySuccess(): void
    {
        $key = new Key('int', str_repeat('I', 32), 1_000);
        $keySet = new KeySet([$key]);
        $nonceStore = new MemoryNonceStore();
        $clock = new FakeClock(time());
        $builder = new LinkBuilder($keySet, $nonceStore, $clock);

        $options = new CreateOptions();
        $options->aud = 'signin';
        $options->ttlSeconds = 600;
        $options->returnTo = 'https://app.test/dashboard';
        $options->app = ['role' => 'admin'];
        $userAgent = 'Integration-UA/1.0';
        $options->app['uah'] = Encoder::base64UrlEncode(hash('sha256', $userAgent, true));
        if (Cipher::isAvailable()) {
            $options->encryptPayload = true;
        }

        $url = $builder->createUrl('https://example.test/login', 'user-42', $options);

        $verifyOptions = new VerifyOptions();
        $verifyOptions->expectedAud = 'signin';
        $verifyOptions->expectedPath = '/login';
        $verifyOptions->expectedHost = 'example.test';
        $verifyOptions->enforceUaHash = true;
        $verifyOptions->returnToAllowlist = static fn (string $returnTo): bool => str_starts_with($returnTo, 'https://app.test');

        $verifier = new Verifier($keySet, $nonceStore, $clock);
        $result = $verifier->verifyFromRequest($url, $verifyOptions, $userAgent);

        self::assertTrue($result->ok);
        self::assertNotNull($result->claims);
        self::assertSame('user-42', $result->claims->sub);
        self::assertSame('signin', $result->claims->aud);
        self::assertSame('admin', $result->claims->app['role']);
        self::assertSame('https://app.test/dashboard', $result->claims->app['return_to']);
    }

    public function testReplayDetectionPreventsReuse(): void
    {
        $key = new Key('int', str_repeat('J', 32), 1_000);
        $keySet = new KeySet([$key]);
        $nonceStore = new MemoryNonceStore();
        $clock = new FakeClock(time());
        $builder = new LinkBuilder($keySet, $nonceStore, $clock);
        $verifier = new Verifier($keySet, $nonceStore, $clock);

        $token = $builder->createToken('user-99');

        $first = $verifier->verifyToken($token);
        $second = $verifier->verifyToken($token);

        self::assertTrue($first->ok);
        self::assertFalse($second->ok);
        self::assertSame('replayed', $second->reason);
    }

    public function testTamperingProducesSignatureMismatchReason(): void
    {
        $key = new Key('int', str_repeat('K', 32), 1_000);
        $keySet = new KeySet([$key]);
        $nonceStore = new MemoryNonceStore();
        $clock = new FakeClock(time());
        $builder = new LinkBuilder($keySet, $nonceStore, $clock);
        $verifier = new Verifier($keySet, $nonceStore, $clock);

        $token = $builder->createToken('user-100');
        $parts = explode('.', $token);
        $parts[1] = str_repeat('A', strlen($parts[1]));
        $tampered = implode('.', $parts);

        $result = $verifier->verifyToken($tampered);

        self::assertFalse($result->ok);
        self::assertSame('signature_mismatch', $result->reason);
    }
}
