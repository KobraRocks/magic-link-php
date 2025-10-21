<?php

declare(strict_types=1);

namespace MagicLink\Core;

use MagicLink\Exception\TokenFormatException;

final class Claims
{
    public string $sub;
    public ?string $aud = null;
    public int $iat;
    public ?int $nbf = null;
    public int $exp;
    public ?string $jti = null;
    /** @var array<string, mixed> */
    public array $app = [];

    /**
     * @param array<string, mixed> $app
     */
    public function __construct(string $sub, int $iat, int $exp, ?string $aud = null, ?string $jti = null, ?int $nbf = null, array $app = [])
    {
        $this->sub = $sub;
        $this->iat = $iat;
        $this->exp = $exp;
        $this->aud = $aud;
        $this->jti = $jti;
        $this->nbf = $nbf;
        $this->app = $app;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['sub']) || !is_string($data['sub'])) {
            throw new TokenFormatException('Missing or invalid "sub" claim.');
        }

        if (!isset($data['iat']) || !is_int($data['iat'])) {
            throw new TokenFormatException('Missing or invalid "iat" claim.');
        }

        if (!isset($data['exp']) || !is_int($data['exp'])) {
            throw new TokenFormatException('Missing or invalid "exp" claim.');
        }

        $aud = null;
        if (array_key_exists('aud', $data)) {
            if (!is_string($data['aud']) && $data['aud'] !== null) {
                throw new TokenFormatException('Invalid "aud" claim.');
            }

            $aud = is_string($data['aud']) ? $data['aud'] : null;
        }

        $nbf = null;
        if (array_key_exists('nbf', $data)) {
            if (!is_int($data['nbf']) && $data['nbf'] !== null) {
                throw new TokenFormatException('Invalid "nbf" claim.');
            }

            $nbf = is_int($data['nbf']) ? $data['nbf'] : null;
        }

        $jti = null;
        if (array_key_exists('jti', $data)) {
            if (!is_string($data['jti']) && $data['jti'] !== null) {
                throw new TokenFormatException('Invalid "jti" claim.');
            }

            $jti = is_string($data['jti']) ? $data['jti'] : null;
        }

        $app = [];
        if (isset($data['app'])) {
            if (!is_array($data['app'])) {
                throw new TokenFormatException('Invalid "app" claim.');
            }

            /** @var array<string, mixed> $appData */
            $appData = $data['app'];
            $app = $appData;
        }

        return new self($data['sub'], $data['iat'], $data['exp'], $aud, $jti, $nbf, $app);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'sub' => $this->sub,
            'iat' => $this->iat,
            'exp' => $this->exp,
        ];

        if ($this->aud !== null) {
            $data['aud'] = $this->aud;
        }

        if ($this->nbf !== null) {
            $data['nbf'] = $this->nbf;
        }

        if ($this->jti !== null) {
            $data['jti'] = $this->jti;
        }

        if ($this->app !== []) {
            $data['app'] = $this->app;
        }

        return $data;
    }
}
