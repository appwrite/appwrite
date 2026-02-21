<?php

namespace Appwrite\Auth\MFA\Challenge;

use Appwrite\Auth\MFA\Challenge;
use Appwrite\Auth\MFA\Type;
use OTPHP\TOTP as TOTPLibrary;
use Utopia\Database\Document;

class TOTP extends Challenge
{
    public static function verify(Document $user, string $otp): bool
    {
        $authenticator = Type\TOTP::getAuthenticatorFromUser($user);
        $data = $authenticator->getAttribute('data');
        $instance = TOTPLibrary::create($data['secret']);

        return $instance->now() === $otp;
    }

    public static function challenge(Document $challenge, Document $user, string $otp): bool
    {
        if (
            $challenge->isSet('type') &&
            $challenge->getAttribute('type') === Type::TOTP
        ) {
            return self::verify($user, $otp);
        }

        return false;
    }
}
