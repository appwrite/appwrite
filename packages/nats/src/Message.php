<?php

declare(strict_types=1);

namespace Utopia\NATS;

final class Message
{
    public function __construct(
        public readonly string $subject,
        public readonly string $data,
        public readonly ?string $replyTo = null,
        public readonly ?Headers $headers = null,
        public readonly ?string $sid = null,
    ) {
    }
}
