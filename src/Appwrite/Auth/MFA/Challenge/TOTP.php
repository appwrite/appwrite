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
        return self::step($user, $otp) !== null;
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

    /**
     * The time step $otp is the current code for, or null when it is not, or when that step was already spent.
     */
    public static function step(Document $user, string $otp): ?int
    {
        $authenticator = Type\TOTP::getAuthenticatorFromUser($user);

        if ($authenticator === null) {
            return null;
        }

        $data = $authenticator->getAttribute('data', []);
        $instance = TOTPLibrary::create($data['secret']);
        $timestamp = \time();

        if (!\hash_equals($instance->at($timestamp), $otp)) {
            return null;
        }

        $step = \intdiv($timestamp - $instance->getEpoch(), $instance->getPeriod());

        if ($step <= ($data['step'] ?? -1)) {
            return null;
        }

        return $step;
    }

    /**
     * Record $step as spent, so its code cannot be redeemed again (RFC 6238 §5.2).
     *
     * Call inside a transaction: the authenticator row lock serializes concurrent redemptions of the same code.
     * Returns false when another request spent this step first.
     */
    public static function spend(Database $dbForProject, Document $user, int $step): bool
    {
        $authenticator = Type\TOTP::getAuthenticatorFromUser($user);

        if ($authenticator === null) {
            return false;
        }

        $authenticator = $dbForProject->getDocument('authenticators', $authenticator->getId(), forUpdate: true);

        if ($authenticator->isEmpty()) {
            return false;
        }

        $data = $authenticator->getAttribute('data', []);

        if ($step <= ($data['step'] ?? -1)) {
            return false;
        }

        $data['step'] = $step;
        $dbForProject->updateDocument('authenticators', $authenticator->getId(), new Document(['data' => $data]));

        return true;
    }
}
