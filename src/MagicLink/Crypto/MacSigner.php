<?php

declare(strict_types=1);

namespace MagicLink\Crypto;

use MagicLink\Exception\CryptoException;

final class MacSigner
{
    private const ALGORITHM = 'sha256';

    public function sign(Key $key, string $input): string
    {
        $signature = hash_hmac(self::ALGORITHM, $input, $key->secret, true);
        if ($signature === false) {
            throw new CryptoException('Unable to compute HMAC signature.');
        }

        return $signature;
    }

    public function verify(Key $key, string $input, string $expectedSignature): bool
    {
        $calculated = $this->sign($key, $input);

        return hash_equals($expectedSignature, $calculated);
    }
}
