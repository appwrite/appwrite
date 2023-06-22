<?php

namespace Appwrite\Auth\MFA\Challenge;

use Appwrite\Auth\MFA\Challenge;
use OTPHP\HOTP as HOTPLibrary;
use Utopia\Database\Document;

class HOTP extends Challenge
{
    public static function verify(Document $user, string $otp): bool
    {
        $instance = HOTPLibrary::create($user->getAttribute('totpSecret'));

        return false;
    }

    public static function challenge(Document $challenge, Document $user, string $otp): bool
    {
        if (
            $challenge->isSet('provider') &&
            $challenge->getAttribute('provider') === 'hotp'
        ) {
            return self::verify($user, $otp);
        }

        return false;
    }
}
