<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

enum AckPolicy: string
{
    case None = 'none';
    case All = 'all';
    case Explicit = 'explicit';

    /**
     * Acknowledged by the flow-control replies of a push consumer rather than per
     * message (server 2.14+). What a durable stream source reads through; see
     * {@see StreamConsumerSource}.
     */
    case FlowControl = 'flow_control';
}
