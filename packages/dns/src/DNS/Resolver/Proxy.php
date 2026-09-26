<?php

declare(strict_types=1);

namespace Utopia\DNS\Resolver;

use Utopia\DNS\Client;
use Utopia\DNS\Message;
use Utopia\DNS\Query;
use Utopia\DNS\Resolver;

class Proxy implements Resolver
{
    protected Client $client;

    /**
     * Create a new Proxy resolver
     *
     * @param string $server DNS server IP address
     * @param int $port DNS server port (default: 53)
     */
    public function __construct(protected string $server, protected int $port = 53)
    {
        $this->client = new Client($this->server, $this->port);
    }

    /**
     * Resolve DNS Record by proxying to another DNS server
     */
    public function resolve(Query $query): Message
    {
        return $this->client->query($query->message);
    }
}
