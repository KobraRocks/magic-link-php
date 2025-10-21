<?php

declare(strict_types=1);

namespace MagicLink\Link;

final class CreateOptions
{
    public ?string $aud = null;
    public int $ttlSeconds = 900;
    public bool $oneTime = true;
    public bool $encryptPayload = false;
    public ?string $pathBind = null;
    public ?string $returnTo = null;
    /** @var array<string, mixed> */
    public array $app = [];
}
