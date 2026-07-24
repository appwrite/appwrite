<?php

namespace Appwrite\Event\Delivery;

enum Sink: string
{
    case Function = 'function';
    case Realtime = 'realtime';
    case Webhook = 'webhook';
}
