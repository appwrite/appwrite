<?php

namespace Appwrite\Messaging;

/**
 * A push service the platform alerts console users' devices through, each configured by its
 * own `_APP_NOTIFICATIONS_PUSH_*` variable. A device's token says which service issued it:
 * APNs device tokens are hexadecimal, while FCM registration tokens are not, so a push target
 * needs no field naming its platform.
 */
enum PushService: string
{
    case APNS = 'apns';
    case FCM = 'fcm';

    public static function forToken(string $token): self
    {
        return \ctype_xdigit($token) ? self::APNS : self::FCM;
    }

    /**
     * The variable holding the service's DSN; its key is in the same name suffixed `_KEY`.
     */
    public function variable(): string
    {
        return '_APP_NOTIFICATIONS_PUSH_' . \strtoupper($this->value);
    }
}
