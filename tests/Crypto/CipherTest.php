<?php

declare(strict_types=1);

namespace MagicLink\Tests\Crypto;

use MagicLink\Crypto\Cipher;
use MagicLink\Crypto\Key;
use MagicLink\Exception\CryptoException;
use MagicLink\Tests\TestCase;

final class CipherTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        if (!Cipher::isAvailable()) {
            $this->markTestSkipped('OpenSSL AES-GCM is unavailable.');
        }

        $cipher = new Cipher();
        $key = new Key('a', str_repeat('A', 32), time());
        $aad = 'header-segment';
        $plaintext = 'secret message';

        $encrypted = $cipher->encrypt($key, $plaintext, $aad);

        self::assertArrayHasKey('ciphertext', $encrypted);
        self::assertArrayHasKey('iv', $encrypted);
        self::assertArrayHasKey('tag', $encrypted);

        $decrypted = $cipher->decrypt($key, $encrypted['ciphertext'], $encrypted['iv'], $encrypted['tag'], $aad);

        self::assertSame($plaintext, $decrypted);
    }

    public function testEncryptRejectsShortSecrets(): void
    {
        if (!Cipher::isAvailable()) {
            $this->markTestSkipped('OpenSSL AES-GCM is unavailable.');
        }

        $cipher = new Cipher();
        $key = new Key('short', str_repeat('B', 16), time());

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('AES-256-GCM requires a 256-bit secret.');

        $cipher->encrypt($key, 'plaintext', 'aad');
    }
}
