<?php

namespace Appwrite\Realtime\Message\Handlers;

use Appwrite\Realtime\Message\Dispatcher;
use Utopia\Platform\Action;

class Ping extends Action
{
    public function __construct()
    {
        $this
            ->desc('Reply to client heartbeat')
            ->label(Dispatcher::LABEL_MESSAGE_TYPE, 'ping')
            ->label(Dispatcher::LABEL_REQUIRES_PROJECT, false)
            ->callback($this->action(...));
    }

    /**
     * @return array<string, mixed>
     */
    public function action(): array
    {
        return [
            'type' => 'pong',
        ];
    }
}
