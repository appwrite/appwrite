<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

enum AckPolicy: string
{
    case None = 'none';
    case All = 'all';
    case Explicit = 'explicit';

    /**
     * Acknowledged by a push consumer's flow-control replies (server 2.14+); what a
     * durable stream source reads through, see {@see StreamConsumerSource}.
     */
    case FlowControl = 'flow_control';
}
