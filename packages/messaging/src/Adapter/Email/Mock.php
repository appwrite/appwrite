<?php

declare(strict_types=1);

namespace Utopia\Messaging\Adapter\Email;

/**
 * The SMTP adapter pointed at a catcher: no credentials, no encryption, and a
 * default host that matches the compose service.
 *
 * It was a second copy of the same conversation before, which is one more copy
 * than the protocol deserves.
 */
class Mock extends SMTP
{
    protected const NAME = 'Mock';

    public function __construct(string $host = 'maildev', int $port = 1025)
    {
        parent::__construct(host: $host, port: $port, xMailer: 'Utopia Mailer');
    }
}
