<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

enum AckPolicy: string
{
    case None = 'none';
    case All = 'all';
    case Explicit = 'explicit';
}
