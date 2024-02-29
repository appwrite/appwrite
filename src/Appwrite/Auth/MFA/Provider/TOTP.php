<?php

namespace Appwrite\Auth\MFA\Provider;

use Appwrite\Auth\MFA\Provider;
use OTPHP\TOTP as TOTPLibrary;
use Utopia\Database\Document;

class TOTP extends Provider
{
    public function __construct(?string $secret = null)
    {
        $this->instance = TOTPLibrary::create($secret);
    }

    public static function getAuthenticatorFromUser(Document $user): ?Document
    {
        foreach ($user->getAttribute('authenticators') as $authenticator) {
            /** @var Document $authenticator */
            if ($authenticator->getAttribute('type') === 'totp') {
                return $authenticator;
            }
        }

        return null;
    }
}
