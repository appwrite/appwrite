<?php

namespace Tests\Compat\Queue;

use Utopia\Queue\Adapter\KubernetesJob;

/**
 * The run-to-completion adapter with a receive that does not block, so a
 * drain ends at once on an empty queue instead of after RECEIVE_TIMEOUT
 * (and leaves no blocking reservation behind). The Rust side sets the same
 * with `Adapter::with_receive_timeout(0)`.
 */
final class Drain extends KubernetesJob
{
    protected const int RECEIVE_TIMEOUT = 0;
}

/**
 * The long-running Swoole adapter, receiving with a one-second wait so a
 * stopped loop ends quickly. The Rust side sets the same with
 * `Adapter::with_receive_timeout(1)`.
 */
final class DrainSlow extends KubernetesJob
{
    // For NATS, whose pull with no wait at all collects nothing.
    protected const int RECEIVE_TIMEOUT = 1;
}

final class Workers extends \Utopia\Queue\Adapter\Swoole
{
    protected const int RECEIVE_TIMEOUT = 1;
}
