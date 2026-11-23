<?php

declare(strict_types=1);

namespace Utopia\Queue\Broker;

/**
 * Whether a broker may create and rewrite a queue's server-side configuration.
 *
 * Provisioning is a capability, not a side effect of touching a queue. A broker
 * carries the stream and consumer settings it was constructed with, so any process
 * holding a differently-configured broker rewrites those settings simply by using
 * the queue — a maintenance task built with one set of knobs can silently restyle
 * the streams a worker fleet is running on.
 *
 * {@see self::Require} closes that: the broker uses what is already provisioned and
 * refuses when it is absent, so the set of processes allowed to write a queue's
 * configuration stays closed to the queue's own producer and consumer.
 */
enum Provisioning
{
    /**
     * Create the streams and durable consumers if absent, and bring an existing
     * queue's configuration in line with this broker's settings. The default, and
     * what a queue's own producer and consumer want.
     */
    case Ensure;

    /**
     * Never send stream or worker-consumer configuration. The queue must already be
     * provisioned; if it is not, the broker refuses rather than creating it.
     *
     * The boundary is stream config plus the two worker consumers — the settings a
     * running fleet depends on. A maintenance consumer that reads the dead stream
     * (see {@see Nats::retry()}) is still created, because it carries none of them.
     */
    case Require;
}
