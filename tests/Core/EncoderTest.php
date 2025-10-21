<?php

declare(strict_types=1);

namespace MagicLink\Tests\Core;

use MagicLink\Core\Encoder;
use MagicLink\Exception\TokenFormatException;
use MagicLink\Tests\TestCase;

final class EncoderTest extends TestCase
{
    public function testBase64UrlEncodeRemovesPadding(): void
    {
        $encoded = Encoder::base64UrlEncode("\xf0\x9f\x92\xa9");

        self::assertSame('8J-SqQ', $encoded);
        self::assertStringNotContainsString('=', $encoded);
    }

    public function testBase64UrlDecodeRoundTrip(): void
    {
        $original = random_bytes(32);
        $encoded = Encoder::base64UrlEncode($original);
        $decoded = Encoder::base64UrlDecode($encoded);

        self::assertSame($original, $decoded);
    }

    public function testBase64UrlDecodeRejectsInvalidCharacters(): void
    {
        $this->expectException(TokenFormatException::class);
        $this->expectExceptionMessage('Invalid characters in base64url string.');

        Encoder::base64UrlDecode('***');
    }

    public function testCanonicalJsonEncodeSortsObjectKeys(): void
    {
        $data = [
            'z' => 1,
            'a' => 2,
            'nested' => [
                'b' => 1,
                'a' => 2,
            ],
        ];

        $json = Encoder::canonicalJsonEncode($data);

        self::assertSame('{"a":2,"nested":{"a":2,"b":1},"z":1}', $json);
    }

    public function testCanonicalJsonEncodePreservesSequentialArrays(): void
    {
        $data = ['b', 'a', ['two' => 2, 'one' => 1]];

        $json = Encoder::canonicalJsonEncode($data);

        self::assertSame('["b","a",{"one":1,"two":2}]', $json);
    }
}
