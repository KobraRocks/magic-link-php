<?php

declare(strict_types=1);

namespace MagicLink\Crypto;

use MagicLink\Exception\CryptoException;

final class Key
{
    public string $kid;
    public string $secret;
    public int $createdAt;
    public ?int $expiresAt;

    public function __construct(string $kid, string $secret, int $createdAt, ?int $expiresAt = null)
    {
        if ($kid === '') {
            throw new CryptoException('Key ID (kid) must not be empty.');
        }

        if ($createdAt <= 0) {
            throw new CryptoException('Key createdAt must be positive.');
        }

        $secretLength = strlen($secret);
        if ($secretLength < 16) {
            throw new CryptoException('Key secret must be at least 128 bits.');
        }

        $this->kid = $kid;
        $this->secret = $secret;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    public function isExpiredAt(int $timestamp): bool
    {
        return $this->expiresAt !== null && $timestamp > $this->expiresAt;
    }
}
