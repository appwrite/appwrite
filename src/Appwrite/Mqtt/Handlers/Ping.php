<?php

namespace Appwrite\Mqtt\Handlers;

use Appwrite\Mqtt\Dispatcher;
use Appwrite\Mqtt\Response;
use Utopia\Mqtt\Packet;
use Utopia\Platform\Action;

class Ping extends Action
{
    public function __construct()
    {
        $this
            ->desc('Reply to a client heartbeat')
            ->label(Dispatcher::LABEL_TYPE, Packet::PINGREQ)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        $response->send(Packet::pingresp());
    }
}
