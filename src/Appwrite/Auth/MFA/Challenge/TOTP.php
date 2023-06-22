<?php

namespace Appwrite\Auth\MFA\Challenge;

use Appwrite\Auth\MFA\Challenge;
use OTPHP\TOTP as TOTPLibrary;
use Utopia\Database\Document;

class TOTP extends Challenge
{
    public static function verify(Document $user, string $otp): bool
    {
        $instance = TOTPLibrary::create($user->getAttribute('totpSecret'));

        return $instance->now() === $otp;
    }

    public static function challenge(Document $challenge, Document $user, string $otp): bool
    {
        if (
            $challenge->isSet('provider') &&
            $challenge->getAttribute('provider') === 'totp'
        ) {
            return self::verify($user, $otp);
        }

        return false;
    }
}
