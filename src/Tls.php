<?php

declare(strict_types=1);

namespace Utopia\SMTP;

/**
 * How the handshake is checked. Shared by every transport so that verification
 * cannot drift between them.
 */
final readonly class Tls
{
    /**
     * @param  string|null  $peerName  The name the certificate must match. Defaults to the host being dialled.
     * @param  string|null  $caFile  A certificate authority bundle, when the system store is not the right answer.
     */
    public function __construct(
        public Verification $verify = Verification::Full,
        public ?string $peerName = null,
        public ?string $caFile = null,
        public ?string $ciphers = null,
    ) {}
}
