<?php

namespace Appwrite\Auth\MFA\Challenge;

use Appwrite\Auth\MFA\Challenge;
use Appwrite\Auth\MFA\Type;
use Utopia\Database\Document;

class Phone extends Challenge
{
    public static function verify(Document $challenge, string $otp): bool
    {
        return $challenge->getAttribute('code') === $otp;
    }

    public static function challenge(Document $challenge, Document $user, string $otp): bool
    {
        if (
            $challenge->isSet('type') &&
            $challenge->getAttribute('type') === Type::PHONE
        ) {
            return self::verify($challenge, $otp);
        }

        return false;
    }
}
