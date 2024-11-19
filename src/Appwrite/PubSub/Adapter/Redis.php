<?php

namespace Appwrite\PubSub\Adapter;

use Appwrite\PubSub\Adapter;

class Redis implements Adapter
{
    private \Redis $client;

    public function __construct(\Redis $client)
    {
        $this->client = $client;

    }

    public function ping($message = null): bool
    {
        return $this->client->ping($message);
    }

    public function subscribe($channels, $callback)
    {
        return $this->client->subscribe($channels, $callback);
    }

    public function publish($channel, $message)
    {
        return $this->client->publish($channel, $message);
    }
}
