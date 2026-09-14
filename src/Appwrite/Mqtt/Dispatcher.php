<?php

namespace Appwrite\Mqtt;

use Appwrite\Extend\Exception;
use Utopia\DI\Container;
use Utopia\Platform\Action;

/**
 * Routes a decoded MQTT packet to the handler registered for its control-packet
 * type, resolving the handler's injections from the per-packet container, keyed on
 * the binary packet type.
 *
 * The handler set is fixed at construction and never mutated afterwards: there is no
 * addHandler/removeHandler. In a coroutine broker a single dispatcher is shared across
 * every connection and packet, so a mutable registry would leak handler state across
 * requests and invite ad-hoc reconfiguration; an immutable table keeps dispatch a pure
 * lookup.
 */
class Dispatcher
{
    public const LABEL_TYPE = 'packetType';

    /** @var array<int, Action> */
    private readonly array $handlers;

    /**
     * @param array<int, Action> $handlers each labelled with its packet type (self::LABEL_TYPE)
     */
    public function __construct(array $handlers)
    {
        $table = [];
        foreach ($handlers as $handler) {
            $type = $handler->getLabels()[self::LABEL_TYPE]
                ?? throw new Exception(Exception::GENERAL_SERVER_ERROR, 'MQTT packet handler is missing the packetType label.');

            $table[$type] = $handler;
        }

        $this->handlers = $table;
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
