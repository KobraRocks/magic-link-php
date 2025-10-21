<?php

declare(strict_types=1);

namespace MagicLink\Link;

final class VerifyOptions
{
    public ?string $expectedAud = null;
    public ?string $expectedPath = null;
    public ?string $expectedHost = null;
    public bool $requireOneTime = false;
    public int $maxClockSkew = 120;
    public bool $enforceUaHash = false;
    /** @var callable|null */
    public $returnToAllowlist = null;
}
