<?php

namespace Appwrite\Auth\MFA\Provider;

use Appwrite\Auth\MFA\Provider;
use OTPHP\TOTP as TOTPLibrary;

class TOTP extends Provider
{
    public function __construct(?string $secret = null)
    {
        $this->instance = TOTPLibrary::create($secret);
    }
}
