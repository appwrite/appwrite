<?php

namespace Appwrite\Auth\MFA\Challenge;

use Appwrite\Auth\MFA\Challenge;
use Appwrite\Auth\MFA\Type;
use OTPHP\TOTP as TOTPLibrary;
use Utopia\Database\Database;
use Utopia\Database\Document;

class TOTP extends Challenge
{
    public static function verify(Document $user, string $otp): bool
    {
        return self::stepAt($user, $otp, \time()) !== null;
    }

    /**
     * The time step a code belongs to, allowing one step of drift either way, or null when it matches none.
     */
    public static function stepAt(Document $user, string $otp, int $timestamp): ?int
    {
        $authenticator = Type\TOTP::getAuthenticatorFromUser($user);
        $data = $authenticator->getAttribute('data');
        $instance = TOTPLibrary::create($data['secret']);
        $period = $instance->getPeriod();
        $leeway = $period - 1;

        foreach ([$timestamp, $timestamp - $leeway, $timestamp + $leeway] as $moment) {
            if (\hash_equals($instance->at($moment), $otp)) {
                return \intdiv($moment - $instance->getEpoch(), $period);
            }
        }

        return null;
    }

    /**
     * Accepts a code only from a step later than the last one the authenticator accepted, so a used code cannot
     * complete another challenge while it is still inside the window.
     */
    public static function redeem(Database $dbForProject, Document $user, string $otp, int $timestamp): bool
    {
        $step = self::stepAt($user, $otp, $timestamp);

        if ($step === null) {
            return false;
        }

        $authenticatorId = Type\TOTP::getAuthenticatorFromUser($user)->getId();

        return $dbForProject->withTransaction(function () use ($dbForProject, $authenticatorId, $step): bool {
            $authenticator = $dbForProject->getDocument('authenticators', $authenticatorId, forUpdate: true);
            $lastStep = $authenticator->getAttribute('lastStep');

            if ($lastStep !== null && $step <= $lastStep) {
                return false;
            }

            $dbForProject->updateDocument('authenticators', $authenticatorId, new Document(['lastStep' => $step]));

            return true;
        });
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
