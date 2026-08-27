<?php

namespace Utopia\Mqtt\Handlers;

use Utopia\Mqtt\Dispatcher;
use Utopia\Mqtt\Packet;
use Utopia\Platform\Action;

class Disconnect extends Action
{
    public function __construct()
    {
        $this
            ->desc('Close the connection on client request')
            ->label(Dispatcher::LABEL_TYPE, Packet::DISCONNECT)
            ->inject('reply')
            ->callback($this->action(...));
    }

    /**
     * @param callable(string, bool): void $reply writes a packet back to this connection (and optionally closes it)
     */
    public function action(callable $reply): void
    {
        $reply('', true);
    }
}
