<?php

namespace Appwrite\Mqtt\Handlers;

use Appwrite\Mqtt\Dispatcher;
use Appwrite\Mqtt\Response;
use Utopia\Mqtt\Packet;
use Utopia\Platform\Action;

class Disconnect extends Action
{
    public function __construct()
    {
        $this
            ->desc('Close the connection on client request')
            ->label(Dispatcher::LABEL_TYPE, Packet::DISCONNECT)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        $response->close();
    }
}
