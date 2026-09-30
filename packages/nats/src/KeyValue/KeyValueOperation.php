<?php

declare(strict_types=1);

namespace Utopia\NATS\KeyValue;

enum KeyValueOperation: string
{
    case Put = 'PUT';
    case Delete = 'DEL';
    case Purge = 'PURGE';
}
