<?php

namespace Utopia\Mqtt;

use Throwable;
use Utopia\Mqtt\Packet\Auth;
use Utopia\Mqtt\Packet\Connack;
use Utopia\Mqtt\Packet\Connect;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Packet\Puback;
use Utopia\Mqtt\Packet\Publish;
use Utopia\Mqtt\Packet\Specs\V3;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Packet\Suback;
use Utopia\Mqtt\Packet\Subscribe;
use Utopia\Mqtt\Packet\Unsuback;
use Utopia\Mqtt\Packet\Unsubscribe;
use Utopia\Mqtt\Subscription\Store;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\UpDownCounter;

class Server
{
    private const MAX_QOS = 2;

    /** Seconds a freshly opened connection has to complete a CONNECT before it is reaped. */
    private const CONNECT_GRACE = 30;

    /** @var array<int, Connection> */
    private array $connections = [];

    private ?Counter $packetsReceived = null;

    private ?Counter $connectionsOpened = null;

    private ?UpDownCounter $connectionsActive = null;

    private ?Counter $subscriptions = null;

    /** @var list<callable> */
    private array $errorCallbacks = [];

    /** @var callable|null */
    private $onStart = null;

    /** @var callable|null */
    private $onWorkerStart = null;

    /** @var callable|null */
    private $onStop = null;

    public function __construct(
        private readonly Adapter $adapter,
        private readonly Handler $handler,
        private readonly Store $store = new Store(),
    ) {
        $this->setTelemetry(new NoTelemetry());
    }

    public function setTelemetry(Telemetry $telemetry): self
    {
        $this->packetsReceived = $telemetry->createCounter('mqtt.packets.received');
        $this->connectionsOpened = $telemetry->createCounter('mqtt.connections.opened');
        $this->connectionsActive = $telemetry->createUpDownCounter('mqtt.connections.active');
        $this->subscriptions = $telemetry->createCounter('mqtt.subscriptions');

        return $this;
    }

    public function onStart(callable $callback): self
    {
        $this->onStart = $callback;

        return $this;
    }

    public function onWorkerStart(callable $callback): self
    {
        $this->onWorkerStart = $callback;

        return $this;
    }

    public function onStop(callable $callback): self
    {
        $this->onStop = $callback;

        return $this;
    }

    public function error(callable $callback): self
    {
        $this->errorCallbacks[] = $callback;

        return $this;
    }

    public function start(): void
    {
        $this->adapter->onOpen($this->opened(...));
        $this->adapter->onReceive($this->receive(...));
        $this->adapter->onClose($this->closed(...));

        if ($this->onStart !== null) {
            $this->adapter->onStart($this->onStart);
        }

        if ($this->onWorkerStart !== null) {
            $this->adapter->onWorkerStart($this->onWorkerStart);
        }

        try {
            $this->adapter->start();
        } catch (Throwable $error) {
            $this->handleError($error, 'start');
        }
    }

    public function stop(): void
    {
        try {
            $this->adapter->shutdown();
        } catch (Throwable $error) {
            $this->handleError($error, 'shutdown');
        }

        if ($this->onStop !== null) {
            \call_user_func($this->onStop);
        }
    }

    /** @return array<int, array{0: Connection, 1: int}> fd => [connection, granted QoS] */
    public function subscribers(string $prefix, string $topic): array
    {
        $subscribers = [];
        foreach ($this->store->getSubscribers($prefix, $topic) as $fd => $qos) {
            if (isset($this->connections[$fd])) {
                $subscribers[$fd] = [$this->connections[$fd], $qos];
            }
        }

        return $subscribers;
    }

    private function receive(int $fd, string $data): void
    {
        $connection = $this->connections[$fd] ??= $this->open($fd);

        try {
            $this->dispatch(Packet::parse($data), $connection);
        } catch (Throwable $error) {
            $this->handleError($error, 'receive');
            $this->adapter->close($fd);

            return;
        }

        if ($connection->active) {
            $this->refreshKeepAlive($connection);
        }
    }

    private function dispatch(Packet $packet, Connection $connection): void
    {
        $this->packetsReceived?->add(1, ['type' => $packet->name()]);

        switch ($packet->type) {
            case Packet::CONNECT:
                $connect = Connect::decode($packet->body);
                $connection->protocol = $connect->protocolLevel;
                $connection->cleanStart = $connect->cleanStart;
                $connection->keepAlive = $connect->keepAlive;
                $connection->setClientId($connect->clientId);

                $result = $this->handler->onConnect($connect, $connection);
                $this->adapter->send($connection->fd, $this->encode($result, $connection->protocol));

                if ($result instanceof Connack && $result->accepted()) {
                    $connection->active = true;
                    $this->connectionsOpened?->add(1, ['result' => 'accepted']);
                    $this->connectionsActive?->add(1);
                } elseif ($result instanceof Connack) {
                    $this->connectionsOpened?->add(1, ['result' => 'rejected']);
                    $this->adapter->close($connection->fd);
                }
                break;

            case Packet::AUTH:
                $result = $this->handler->onAuthenticate(Auth::decode($packet->body), $connection);
                $this->adapter->send($connection->fd, $this->encode($result, $connection->protocol));

                if ($result instanceof Disconnect) {
                    $this->adapter->close($connection->fd);
                }
                break;

            case Packet::SUBSCRIBE:
                $subscribe = Subscribe::decode($packet->body, $connection->protocol);
                $suback = $this->handler->onSubscribe($subscribe, $connection);
                $this->record($subscribe, $suback, $connection);
                $this->adapter->send($connection->fd, $this->encodeSuback($suback, $subscribe->packetId, $connection->protocol));
                break;

            case Packet::UNSUBSCRIBE:
                $unsubscribe = Unsubscribe::decode($packet->body, $connection->protocol);
                foreach ($unsubscribe->filters() as $filter) {
                    $this->store->unsubscribe($filter, $connection->fd);
                }
                $unsuback = $this->handler->onUnsubscribe($unsubscribe, $connection);
                $this->adapter->send($connection->fd, $this->encodeUnsuback($unsuback, $unsubscribe->packetId, $connection->protocol));
                break;

            case Packet::PUBLISH:
                $publish = Publish::decode($packet, $connection->protocol);
                $this->handler->onPublish($publish, $connection, $this->subscribers($connection->prefix, $publish->topic));
                break;

            case Packet::PUBACK:
                $this->handler->onPuback(Puback::decode($packet->body), $connection);
                break;

            case Packet::PINGREQ:
                $this->adapter->send($connection->fd, Packet::pingresp());
                break;

            case Packet::DISCONNECT:
                $this->handler->onDisconnect(Disconnect::decode($packet->body), $connection);
                $this->cleanup($connection->fd);
                $this->adapter->close($connection->fd);
                break;
        }
    }

    private function opened(int $fd): void
    {
        $this->connections[$fd] ??= $this->open($fd);
        // Give the peer a bounded window to CONNECT; a CONNECT re-arms this to keep-alive, so a
        // connection that never authenticates is reaped instead of holding the slot indefinitely.
        $this->adapter->timer()->schedule($fd, self::CONNECT_GRACE);
    }

    private function open(int $fd): Connection
    {
        return new Connection($fd, $this->adapter);
    }

    private function closed(int $fd): void
    {
        $connection = $this->connections[$fd] ?? null;
        if ($connection === null) {
            return;
        }

        $this->handler->onDisconnect(null, $connection);
        $this->cleanup($fd);
    }

    private function cleanup(int $fd): void
    {
        if (($this->connections[$fd] ?? null)?->active === true) {
            $this->connectionsActive?->add(-1);
        }

        $this->adapter->timer()->clear($fd);
        $this->store->close($fd);
        unset($this->connections[$fd]);
    }

    private function refreshKeepAlive(Connection $connection): void
    {
        $this->adapter->timer()->schedule($connection->fd, $connection->keepAlive);
    }

    private function record(Subscribe $subscribe, Suback $suback, Connection $connection): void
    {
        $codes = $suback->codes();
        foreach ($subscribe->filters() as $index => $filter) {
            $code = $codes[$index] ?? Suback::DENIED;
            if ($code <= self::MAX_QOS) {
                $this->store->subscribe($connection->prefix, $connection->getClientId(), $filter->topic, $connection->fd, $code);
                $this->subscriptions?->add(1, ['result' => 'granted']);
            } else {
                $this->subscriptions?->add(1, ['result' => 'denied']);
            }
        }
    }

    private function encode(Connack|Auth|Disconnect $packet, int $protocol): string
    {
        return match (true) {
            $packet instanceof Connack => $this->encodeConnack($packet, $protocol),
            $packet instanceof Auth => V5::auth($packet->reasonCode, $this->authProperties($packet)),
            $packet instanceof Disconnect => $protocol >= V5::PROTOCOL_LEVEL ? V5::disconnect($packet->reasonCode, $packet->properties) : V3::disconnect(),
        };
    }

    private function encodeConnack(Connack $connack, int $protocol): string
    {
        if ($protocol >= V5::PROTOCOL_LEVEL) {
            return V5::connack($connack->reasonCode, $connack->properties);
        }

        return V3::connack(match ($connack->reasonCode) {
            Connack::SUCCESS => V3::RETURN_ACCEPTED,
            Connack::BAD_CREDENTIALS => V3::RETURN_BAD_CREDENTIALS,
            Connack::SERVER_UNAVAILABLE, Connack::SERVER_BUSY => V3::RETURN_SERVER_UNAVAILABLE,
            default => V3::RETURN_NOT_AUTHORIZED,
        });
    }

    private function encodeSuback(Suback $suback, int $packetId, int $protocol): string
    {
        $id = \pack('n', $packetId);
        $codes = $suback->codes();

        if ($protocol >= V5::PROTOCOL_LEVEL) {
            return V5::suback($id, \implode('', \array_map('chr', $codes)));
        }

        $returnCodes = '';
        foreach ($codes as $code) {
            $returnCodes .= \chr($code <= self::MAX_QOS ? $code : V3::SUBSCRIBE_FAILURE);
        }

        return V3::suback($id, $returnCodes);
    }

    private function encodeUnsuback(Unsuback $unsuback, int $packetId, int $protocol): string
    {
        $id = \pack('n', $packetId);

        if ($protocol >= V5::PROTOCOL_LEVEL) {
            return V5::unsuback($id, \count($unsuback->codes()));
        }

        return V3::unsuback($id);
    }

    private function authProperties(Auth $auth): Properties
    {
        $properties = new Properties();
        if ($auth->method !== '') {
            $properties->add(new Property(Property::AUTHENTICATION_METHOD, $auth->method));
        }
        if ($auth->data !== '') {
            $properties->add(new Property(Property::AUTHENTICATION_DATA, $auth->data));
        }

        return $properties;
    }

    private function handleError(Throwable $error, string $action): void
    {
        foreach ($this->errorCallbacks as $callback) {
            \call_user_func($callback, $error, $action);
        }
    }
}
