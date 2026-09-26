<?php

declare(strict_types=1);

namespace Utopia\DNS\Resolver;

use Utopia\DNS\Message;
use Utopia\DNS\Query;
use Utopia\DNS\Resolver;
use Utopia\DNS\Zone;
use Utopia\DNS\Zone\Resolver as ZoneResolver;

class Memory implements Resolver
{
    public function __construct(private readonly Zone $zone) {}

    public function resolve(Query $query): Message
    {
        return ZoneResolver::lookup($query->message, $this->zone);
    }
}
