<?php

declare(strict_types=1);

namespace Utopia\SMTP\Auth;

/**
 * One RFC 4954 mechanism. The client tries its authenticators in order against
 * what the server advertises, so the order is the security policy.
 */
interface Authenticator
{
    /**
     * The name as it appears in the AUTH capability, e.g. PLAIN.
     */
    public function mechanism(): string;

    /**
     * The initial response sent with the AUTH command, saving a round trip.
     * Null when the mechanism has to wait for the server to speak first.
     *
     * Returned decoded; the client applies base64.
     */
    public function initial(): ?string;

    /**
     * Answer a server challenge, decoded in and decoded out.
     *
     * An authenticator outlives the connection it was built for and is reused
     * after a reconnect, so a mechanism with more than one step reads $step
     * rather than counting for itself. Nothing here may hold state.
     *
     * @param  int  $step  Which challenge this is, counting from zero.
     */
    public function respond(string $challenge, int $step): string;
}
