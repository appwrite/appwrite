<?php

namespace Utopia\Mqtt\Handlers;

use Utopia\Mqtt\Dispatcher;
use Utopia\Mqtt\Packet;
use Utopia\Platform\Action;

class Ping extends Action
{
    public function __construct()
    {
        $this
            ->desc('Reply to a client heartbeat')
            ->label(Dispatcher::LABEL_TYPE, Packet::PINGREQ)
            ->inject('reply')
            ->callback($this->action(...));
    }

    /**
     * @param callable(string, bool): void $reply writes a packet back to this connection (and optionally closes it)
     */
    public function action(callable $reply): void
    {
        $reply(chr(Packet::PINGRESP << 4) . Packet::encodeLength(0), false);
    }
}
