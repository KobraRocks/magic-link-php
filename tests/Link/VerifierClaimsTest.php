<?php

declare(strict_types=1);

namespace MagicLink\Tests\Link;

use MagicLink\Core\Encoder;
use MagicLink\Core\Token;
use MagicLink\Crypto\Key;
use MagicLink\Crypto\KeySet;
use MagicLink\Crypto\MacSigner;
use MagicLink\Link\Verifier;
use MagicLink\Store\MemoryNonceStore;
use MagicLink\Tests\Util\FakeClock;
use MagicLink\Tests\TestCase;

final class VerifierClaimsTest extends TestCase
{
    public function testFailsWhenIssuedInFutureBeyondClockSkew(): void
    {
        $key = $this->createKey();
        $token = $this->buildSignedToken([
            'sub' => 'user-123',
            'iat' => 1_000,
            'exp' => 1_200,
        ], $key);

        $clock = new FakeClock(800);
        $verifier = new Verifier(new KeySet([$key]), new MemoryNonceStore(), $clock);

        $result = $verifier->verifyToken($token);

        self::assertFalse($result->ok);
        self::assertSame('clock_skew', $result->reason);
    }

    public function testFailsWhenExpired(): void
    {
        $key = $this->createKey();
        $token = $this->buildSignedToken([
            'sub' => 'user-123',
            'iat' => 1_000,
            'exp' => 1_100,
        ], $key);

        $clock = new FakeClock(2_000);
        $verifier = new Verifier(new KeySet([$key]), new MemoryNonceStore(), $clock);

        $result = $verifier->verifyToken($token);

        self::assertFalse($result->ok);
        self::assertSame('token_expired', $result->reason);
    }

    public function testFailsWhenTokenNotYetValid(): void
    {
        $key = $this->createKey();
        $token = $this->buildSignedToken([
            'sub' => 'user-123',
            'iat' => 1_000,
            'exp' => 2_000,
            'nbf' => 1_400,
        ], $key);

        $clock = new FakeClock(1_000);
        $verifier = new Verifier(new KeySet([$key]), new MemoryNonceStore(), $clock);

        $result = $verifier->verifyToken($token);

        self::assertFalse($result->ok);
        self::assertSame('token_early', $result->reason);
    }

    private function createKey(): Key
    {
        return new Key('kid-test', str_repeat('K', 32), 1_000);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function buildSignedToken(array $claims, Key $key): string
    {
        $header = [
            'alg' => 'HS256',
            'kid' => $key->kid,
        ];

        $headerJson = Encoder::canonicalJsonEncode($header);
        $payloadJson = Encoder::canonicalJsonEncode($claims);
        $headerSegment = Encoder::base64UrlEncode($headerJson);
        $payloadSegment = Encoder::base64UrlEncode($payloadJson);

        $token = new Token($headerSegment, $payloadSegment, '');
        $signature = (new MacSigner())->sign($key, $token->signingInput());
        $signatureSegment = Encoder::base64UrlEncode($signature);

        return $headerSegment . '.' . $payloadSegment . '.' . $signatureSegment;
    }
}
