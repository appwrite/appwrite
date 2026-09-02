<?php

namespace Utopia\Mqtt;

use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\UpDownCounter;

/**
 * The broker's telemetry instruments, created once from a telemetry adapter and
 * shared with every packet handler. Handlers record connection and delivery
 * outcomes (accepted/rejected, granted/denied, delivered/dropped) that only they
 * observe. A no-op telemetry adapter yields no-op instruments, so the broker runs
 * untelemetered (e.g. raw protocol testing).
 */
class Metrics
{
    public Counter $connectionsOpened;
    public UpDownCounter $connectionsActive;
    public Counter $subscriptions;
    public Counter $messagesPublished;
    public Counter $messagesDelivered;
    public Counter $messagesDropped;

    public function __construct(Telemetry $telemetry)
    {
        $this->connectionsOpened = $telemetry->createCounter('mqtt.connections.opened');
        $this->connectionsActive = $telemetry->createUpDownCounter('mqtt.connections.active');
        $this->subscriptions = $telemetry->createCounter('mqtt.subscriptions');
        $this->messagesPublished = $telemetry->createCounter('mqtt.messages.published');
        $this->messagesDelivered = $telemetry->createCounter('mqtt.messages.delivered');
        $this->messagesDropped = $telemetry->createCounter('mqtt.messages.dropped');
    }
}
