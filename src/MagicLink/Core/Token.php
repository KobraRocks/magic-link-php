<?php

declare(strict_types=1);

namespace MagicLink\Core;

final class Token
{
    public const PARAM_DEFAULT = 'ml';

    public function __construct(
        public string $headerSegment,
        public string $payloadSegment,
        public string $signatureSegment
    ) {
    }

    public static function fromString(string $token): ?self
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        return new self($parts[0], $parts[1], $parts[2]);
    }

    public function signingInput(): string
    {
        return $this->headerSegment . '.' . $this->payloadSegment;
    }
}
