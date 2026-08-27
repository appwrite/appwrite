<?php

namespace Utopia\Mqtt;

use Utopia\DI\Container;
use Utopia\Platform\Action;

/**
 * Routes a decoded MQTT packet to the handler registered for its control-packet
 * type, resolving the handler's injections from the per-packet container. Mirrors
 * the realtime message dispatcher (src/Appwrite/Realtime/Message/Dispatcher.php),
 * keyed on the binary packet type instead of a JSON message type.
 */
class Dispatcher
{
    public const LABEL_TYPE = 'packetType';

    /** @var array<int, Action> */
    private array $handlers = [];

    public function addHandler(Action $handler): self
    {
        $type = $handler->getLabels()[self::LABEL_TYPE]
            ?? throw new \LogicException('MQTT packet handler is missing the packetType label.');

        $this->handlers[$type] = $handler;

        return $this;
    }

    /**
     * Dispatch to the handler for this packet type. Unhandled types (e.g. PUBACK)
     * are ignored, matching a broker that acknowledges only what it must.
     */
    public function dispatch(Container $container, int $type): void
    {
        $handler = $this->handlers[$type] ?? null;
        if ($handler === null) {
            return;
        }

        $args = [];
        foreach ($handler->getOptions() as $option) {
            $args[] = $container->get($option['name']);
        }

        ($handler->getCallback())(...$args);
    }
}
