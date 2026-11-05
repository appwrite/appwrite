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
        return (bool) $this->client->ping($message);
    }

    public function subscribe($channels, $callback)
    {
        // A subscriber waits for the next message for as long as it takes
        $this->client->setOption(\Redis::OPT_READ_TIMEOUT, -1);

        return $this->client->subscribe($channels, $callback);
    }

    public function publish($channel, $message)
    {
        return $this->client->publish($channel, $message);
    }
}
