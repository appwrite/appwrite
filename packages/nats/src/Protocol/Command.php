<?php

declare(strict_types=1);

namespace Utopia\NATS\Protocol;

enum Command: string
{
    case Connect = 'CONNECT';
    case Pub = 'PUB';
    case HPub = 'HPUB';
    case Sub = 'SUB';
    case Unsub = 'UNSUB';
    case Ping = 'PING';
    case Pong = 'PONG';
}
