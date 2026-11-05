<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

enum StorageType: string
{
    case File = 'file';
    case Memory = 'memory';
}
