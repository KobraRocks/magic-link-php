<?php

declare(strict_types=1);

namespace MagicLink\Crypto;

use MagicLink\Exception\CryptoException;

final class Cipher
{
    public const ALG_A256GCM = 'A256GCM';
    private const CIPHER_NAME = 'aes-256-gcm';
    private const IV_LENGTH = 12;

    public static function isAvailable(): bool
    {
        return function_exists('openssl_encrypt') && function_exists('openssl_decrypt');
    }

    /**
     * @return array{ciphertext:string, iv:string, tag:string}
     */
    public function encrypt(Key $key, string $plaintext, string $aad): array
    {
        if (!self::isAvailable()) {
            throw new CryptoException('OpenSSL AES-GCM is unavailable.');
        }

        if (strlen($key->secret) < 32) {
            throw new CryptoException('AES-256-GCM requires a 256-bit secret.');
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER_NAME,
            substr($key->secret, 0, 32),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            16
        );

        if ($ciphertext === false) {
            throw new CryptoException('Unable to encrypt payload using AES-GCM.');
        }

        return [
            'ciphertext' => $ciphertext,
            'iv' => $iv,
            'tag' => $tag,
        ];
    }

    public function decrypt(Key $key, string $ciphertext, string $iv, string $tag, string $aad): string
    {
        if (!self::isAvailable()) {
            throw new CryptoException('OpenSSL AES-GCM is unavailable.');
        }

        if (strlen($key->secret) < 32) {
            throw new CryptoException('AES-256-GCM requires a 256-bit secret.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER_NAME,
            substr($key->secret, 0, 32),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );

        if ($plaintext === false) {
            throw new CryptoException('Unable to decrypt payload using AES-GCM.');
        }

        return $plaintext;
    }
}
