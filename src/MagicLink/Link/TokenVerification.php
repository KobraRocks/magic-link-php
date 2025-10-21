<?php

declare(strict_types=1);

namespace MagicLink\Link;

use MagicLink\Core\Claims;

final class TokenVerification
{
    public bool $ok = false;
    public ?string $reason = null;
    public ?Claims $claims = null;

    public static function success(Claims $claims): self
    {
        $result = new self();
        $result->ok = true;
        $result->claims = $claims;

        return $result;
    }

    public static function failure(string $reason): self
    {
        $result = new self();
        $result->ok = false;
        $result->reason = $reason;

        return $result;
    }
}
