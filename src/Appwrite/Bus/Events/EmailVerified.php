<?php

namespace Appwrite\Bus\Events;

use Utopia\Bus\Event;

/**
 * A user's email address became verified: through a verification link or code,
 * a sign-in that proves it (magic URL, email OTP, password recovery), a provider
 * that attests it (OAuth2, ID token, LDAP), including accounts created verified,
 * or an admin. Dispatched before the response, so listeners finish first.
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
