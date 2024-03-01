<?php

namespace Appwrite\Auth\MFA\Type;

use Appwrite\Auth\MFA\Type;
use OTPHP\TOTP as TOTPLibrary;
use Utopia\Database\Document;

class TOTP extends Type
{
    public function __construct(?string $secret = null)
    {
        $this->instance = TOTPLibrary::create($secret);
    }

    public static function getAuthenticatorFromUser(Document $user): ?Document
    {
        foreach ($user->getAttribute('authenticators', []) as $authenticator) {
            /** @var Document $authenticator */
            if ($authenticator->getAttribute('type') === Type::TOTP) {
                return $authenticator;
            }
        }

        return null;
    }
}
