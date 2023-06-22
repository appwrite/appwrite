<?php

namespace Appwrite\Auth\MFA\Provider;

use Appwrite\Auth\MFA\Provider;
use OTPHP\HOTP as HOTPLibrary;

class HOTP extends Provider
{
    public function __construct(?string $secret = null)
    {
        $this->instance = HOTPLibrary::create($secret);
    }
}
