<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests;

use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Handler;
use Utopia\Mqtt\Packet\Auth;
use Utopia\Mqtt\Packet\Connack;
use Utopia\Mqtt\Packet\Connect;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Packet\Puback;
use Utopia\Mqtt\Packet\Publish;
use Utopia\Mqtt\Packet\Suback;
use Utopia\Mqtt\Packet\Subscribe;
use Utopia\Mqtt\Packet\Unsuback;
use Utopia\Mqtt\Packet\Unsubscribe;

/**
 * Accepts every CONNECT and counts them, so a test can assert what reached the handler.
 */
final class AcceptingHandler implements Handler
{
    public int $connects = 0;

    public string $clientId = '';

    public function onConnect(Connect $connect, Connection $connection): Connack
    {
        $this->connects++;
        $this->clientId = $connection->getClientId();

        return Connack::accept();
    }

    public function onAuthenticate(Auth $auth, Connection $connection): Connack|Auth|Disconnect
    {
        throw new \LogicException('not exercised');
    }

    public function onSubscribe(Subscribe $subscribe, Connection $connection): Suback
    {
        throw new \LogicException('not exercised');
    }

    public function onUnsubscribe(Unsubscribe $unsubscribe, Connection $connection): Unsuback
    {
        throw new \LogicException('not exercised');
    }

    public function onPublish(Publish $publish, Connection $connection, iterable $subscribers): void
    {
    }

    public function onPuback(Puback $puback, Connection $connection): void
    {
    }

    public function onDisconnect(?Disconnect $disconnect, Connection $connection): void
    {
    }
}
