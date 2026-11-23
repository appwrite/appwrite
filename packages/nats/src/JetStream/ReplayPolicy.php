<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

enum ReplayPolicy: string
{
    case Instant = 'instant';
    case Original = 'original';
}
