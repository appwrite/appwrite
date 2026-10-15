<?php

declare(strict_types=1);

namespace Appwrite\Auth\OIDC;

use Ahc\Jwt\JWT as Base;

/**
 * adhocore/jwt registers keys with array_merge, which renumbers integer-like
 * key IDs such as "1234", so a token signed under one fails with "Unknown key ID".
 */
final class JWT extends Base
{
    /**
     * @param array<array-key, mixed> $keys
     */
    #[\Override]
    public function registerKeys(array $keys): self
    {
        $this->keys = $keys + $this->keys;

        return $this;
    }
}
