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
        return self::verifyAt($user, $otp, \time());
    }

    public static function verifyAt(Document $user, string $otp, int $timestamp): bool
    {
        $authenticator = Type\TOTP::getAuthenticatorFromUser($user);
        $data = $authenticator->getAttribute('data');
        $instance = TOTPLibrary::create($data['secret']);

        return $instance->verify($otp, $timestamp, $instance->getPeriod() - 1);
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
