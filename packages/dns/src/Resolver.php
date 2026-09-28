<?php

declare(strict_types=1);

namespace Utopia\DNS;

interface Resolver
{
    /**
     * Returns a DNS response for the given query.
     *
     * @param Query $query The DNS query and its source.
     * @return Message The DNS response.
     */
    public function resolve(Query $query): Message;
}
