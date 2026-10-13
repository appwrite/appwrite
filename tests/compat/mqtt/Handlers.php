<?php

namespace Tests\Compat\Mqtt;

use Utopia\Mqtt\Adapter\Swoole\Timer;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Handler;
use Utopia\Mqtt\Packet\Auth;
use Utopia\Mqtt\Packet\Connack;
use Utopia\Mqtt\Packet\Connect;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Packet\Filter;
use Utopia\Mqtt\Packet\Puback;
use Utopia\Mqtt\Packet\Publish;
use Utopia\Mqtt\Packet\Suback;
use Utopia\Mqtt\Packet\Subscribe;
use Utopia\Mqtt\Packet\Unsuback;
use Utopia\Mqtt\Packet\Unsubscribe;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

/**
 * A timer that records what the broker schedules and clears; tick(id) runs
 * the tick hook (the adapter closes the connection). The twin of `Timeline`
 * in crates/tools/compat/src/libs/mqtt.rs.
 */
final class Timeline implements Timer
{
    /** @var callable|null */
    private $onTick = null;

    /** @param \ArrayObject<int, mixed> $log */
    public function __construct(public \ArrayObject $log)
    {
    }

    public function onTick(callable $callback): self
    {
        $this->onTick = $callback;

        return $this;
    }

    /** The clear hook is not used: clears are logged by clear(), as by the Rust twin. */
    public function onClear(callable $callback): self
    {
        return $this;
    }

    public function schedule(int $id, int $keepAlive): void
    {
        $this->log[] = ['schedule', $id, $keepAlive];
    }

    public function clear(int $id): void
    {
        $this->log[] = ['clear', $id];
    }

    public function tick(int $id): void
    {
        if ($this->onTick !== null) {
            \call_user_func($this->onTick, $id);
        }
    }
}

/**
 * The compat suite's broker decisions, logged; the twin of `Recorder` in
 * crates/tools/compat/src/libs/mqtt.rs. A password or authentication data
 * of "deny" is refused ("busy": server busy); the method "challenge" starts
 * enhanced authentication; a User Property "prefix" scopes the connection.
 * Filters starting with "deny" are denied, "short" gets no code, others are
 * granted their requested QoS. A publish to "boom" throws; others are
 * delivered to each subscriber at the lowest of the publish's QoS, the
 * granted QoS and 1, QoS 1 deliveries tracked with a running sequence.
 */
final class Recorder implements Handler
{
    private int $sequence = 0;

    /**
     * @param \ArrayObject<int, mixed> $log
     * @param \Closure|null $shutdown called for a publish to "compat/shutdown" (server.php's broker mode)
     * @param Memory|null $adapter closes a client connecting with the password "hangup" while onConnect runs
     */
    public function __construct(public \ArrayObject $log, private ?\Closure $shutdown = null, private ?Memory $adapter = null)
    {
    }

    /** A map as [key, value] pairs, keys as strings. */
    public static function pairs(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            $out[] = [(string) $key, $value];
        }

        return $out;
    }

    public function onConnect(Connect $connect, Connection $connection): Connack|Auth
    {
        $user = $connect->userProperties();
        $this->log[] = ['connect', $connection->fd, $connect->protocolLevel, $connect->clientId, $connect->cleanStart, $connect->keepAlive, $connect->username, $connect->password, $connect->will === null ? null : [$connect->will->topic, $connect->will->payload, $connect->will->qos, $connect->will->retain], $connect->authMethod, $connect->authData, self::pairs($user), $connection->getClientId()];
        if (isset($user['prefix'])) {
            $connection->prefix = $user['prefix'];
        }
        if ($connect->password === 'hangup' && $this->adapter instanceof Memory) {
            // The client hangs up while onConnect waits on I/O.
            $this->adapter->close($connection->fd);
            $this->log[] = ['hangup', $connection->fd, $connection->closed, $connection->active];
        }
        if ($connect->password === 'deny' || $connect->authData === 'deny') {
            return Connack::refuse(Connack::NOT_AUTHORIZED);
        }
        if ($connect->password === 'busy') {
            return Connack::refuse(Connack::SERVER_BUSY);
        }
        if ($connect->authMethod === 'challenge') {
            return Auth::challenge('challenge', 'nonce');
        }
        $properties = $connect->authMethod === null ? null : (new Properties())->add(new Property(Property::AUTHENTICATION_METHOD, $connect->authMethod));

        return Connack::accept(false, $properties);
    }

    public function onAuthenticate(Auth $auth, Connection $connection): Connack|Auth|Disconnect
    {
        $this->log[] = ['auth', $connection->fd, $auth->reasonCode, $auth->method, $auth->data, self::pairs($auth->userProperties())];
        if ($auth->data === 'deny') {
            return Disconnect::refuse(Disconnect::NOT_AUTHORIZED, 'denied');
        }
        if ($auth->reasonCode === Auth::CONTINUE) {
            return Connack::accept();
        }

        return Auth::success($auth->method);
    }

    public function onSubscribe(Subscribe $subscribe, Connection $connection): Suback
    {
        $this->log[] = ['subscribe', $connection->fd, $subscribe->packetId, array_map(fn (Filter $f) => [$f->topic, $f->qos], $subscribe->filters())];
        $suback = new Suback();
        foreach ($subscribe->filters() as $filter) {
            if (str_starts_with($filter->topic, 'deny')) {
                $suback->deny();
            } elseif ($filter->topic !== 'short') {
                $suback->grant($filter->qos);
            }
        }

        return $suback;
    }

    public function onUnsubscribe(Unsubscribe $unsubscribe, Connection $connection): Unsuback
    {
        $this->log[] = ['unsubscribe', $connection->fd, $unsubscribe->packetId, $unsubscribe->filters()];
        $unsuback = new Unsuback();
        foreach ($unsubscribe->filters() as $filter) {
            str_starts_with($filter, 'deny') ? $unsuback->fail() : $unsuback->success();
        }

        return $unsuback;
    }

    public function onPublish(Publish $publish, Connection $connection, iterable $subscribers): void
    {
        $list = [];
        foreach ($subscribers as $fd => [$subscriber, $granted]) {
            $list[] = [$fd, $granted];
        }
        $this->log[] = ['publish', $connection->fd, $publish->topic, $publish->payload, $publish->qos, $publish->dup, $publish->retain, $publish->packetId, self::pairs($publish->userProperties()), $list];
        if ($publish->topic === 'boom') {
            throw new \RuntimeException('boom');
        }
        if ($publish->topic === 'compat/shutdown' && $this->shutdown !== null) {
            ($this->shutdown)();

            return;
        }
        foreach ($subscribers as [$subscriber, $granted]) {
            $qos = min($publish->qos, $granted, 1);
            $subscriber->publish($publish->topic, $publish->payload, $qos, false, $qos === 1 ? ++$this->sequence : null);
        }
        if ($publish->qos === 1) {
            $connection->puback($publish->packetId);
        }
    }

    public function onPuback(Puback $puback, Connection $connection): void
    {
        $this->log[] = ['puback', $connection->fd, $puback->packetId, $puback->reasonCode, $connection->acknowledge($puback->packetId)];
    }

    public function onDisconnect(?Disconnect $disconnect, Connection $connection): void
    {
        $this->log[] = ['disconnect', $connection->fd, $disconnect?->reasonCode];
    }
}

/**
 * The benchmark's broker decisions: accept every connection, grant every
 * filter, deliver every publish at QoS 0. The twin of `Deliver` in
 * crates/tools/compat/src/libs/mqtt.rs.
 */
final class Deliver implements Handler
{
    public function onConnect(Connect $connect, Connection $connection): Connack
    {
        return Connack::accept();
    }

    public function onAuthenticate(Auth $auth, Connection $connection): Auth
    {
        return Auth::success($auth->method);
    }

    public function onSubscribe(Subscribe $subscribe, Connection $connection): Suback
    {
        $suback = new Suback();
        foreach ($subscribe->filters() as $filter) {
            $suback->grant(0);
        }

        return $suback;
    }

    public function onUnsubscribe(Unsubscribe $unsubscribe, Connection $connection): Unsuback
    {
        return new Unsuback();
    }

    public function onPublish(Publish $publish, Connection $connection, iterable $subscribers): void
    {
        foreach ($subscribers as [$subscriber]) {
            $subscriber->publish($publish->topic, $publish->payload);
        }
    }

    public function onPuback(Puback $puback, Connection $connection): void
    {
    }

    public function onDisconnect(?Disconnect $disconnect, Connection $connection): void
    {
    }
}
