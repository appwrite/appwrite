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

    /**
     * Whether the authenticator is the user's last verified second factor while MFA is on.
     * Removing it would leave the account reporting MFA as enabled with nothing to enforce.
     */
    public static function isLastFactor(Document $user, Document $authenticator): bool
    {
        if (!$user->getAttribute('mfa', false) || !$authenticator->getAttribute('verified', false)) {
            return false;
        }

        return !$user->getAttribute('emailVerification', false)
            && !$user->getAttribute('phoneVerification', false);
    }
}
