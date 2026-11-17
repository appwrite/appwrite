<?php

namespace Appwrite\Bus\Events;

use Utopia\Bus\Event;

/**
 * A user's email address became verified: through a verification link or code,
 * a sign-in that proves it (magic URL, email OTP, password recovery), an accepted
 * team invite, a provider that attests it (OAuth2, ID token, LDAP), including
 * accounts created verified, or an admin. Dispatched before the response, so
 * listeners finish first.
 *
 * Concurrent requests for the same user may each dispatch it, so listeners must
 * tolerate seeing it more than once.
 */
class EmailVerified implements Event
{
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $project
     */
    public function __construct(
        public readonly array $user,
        public readonly array $project,
    ) {
    }
}
