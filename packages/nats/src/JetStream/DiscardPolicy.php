<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

enum DiscardPolicy: string
{
    case Old = 'old';
    case New = 'new';
}
