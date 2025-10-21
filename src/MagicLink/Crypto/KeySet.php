<?php

declare(strict_types=1);

namespace MagicLink\Crypto;

use MagicLink\Exception\CryptoException;

final class KeySet
{
    /** @var array<string, Key> */
    private array $keysById = [];

    /**
     * @param Key[] $keys
     */
    public function __construct(array $keys = [])
    {
        foreach ($keys as $key) {
            $this->add($key);
        }
    }

    public function add(Key $key): void
    {
        $this->keysById[$key->kid] = $key;
    }

    public function getForSign(): Key
    {
        if ($this->keysById === []) {
            throw new CryptoException('No keys available for signing.');
        }

        $latest = null;
        foreach ($this->keysById as $key) {
            if ($key->expiresAt !== null && $key->expiresAt < time()) {
                continue;
            }

            if ($latest === null || $key->createdAt > $latest->createdAt) {
                $latest = $key;
            }
        }

        if ($latest === null) {
            throw new CryptoException('No active keys available for signing.');
        }

        return $latest;
    }

    public function find(string $kid): ?Key
    {
        return $this->keysById[$kid] ?? null;
    }

    /**
     * @return Key[]
     */
    public function all(): array
    {
        return array_values($this->keysById);
    }
}
