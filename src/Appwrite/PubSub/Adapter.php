<?php

namespace Appwrite\PubSub;

interface Adapter
{
    public function ping($message = null): bool;

    public function subscribe($channels, $callback);

    public function publish($channel, $message);

}
