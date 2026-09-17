<?php

namespace Utopia\Mqtt;

use Utopia\Mqtt\Packet\Auth;
use Utopia\Mqtt\Packet\Connack;
use Utopia\Mqtt\Packet\Connect;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Packet\Publish;
use Utopia\Mqtt\Packet\Puback;
use Utopia\Mqtt\Packet\Suback;
use Utopia\Mqtt\Packet\Subscribe;
use Utopia\Mqtt\Packet\Unsubscribe;
use Utopia\Mqtt\Packet\Unsuback;

interface Handler
{
    public function onConnect(Connect $connect, Connection $connection): Connack|Auth;

    public function onAuthenticate(Auth $auth, Connection $connection): Connack|Auth|Disconnect;

    public function onSubscribe(Subscribe $subscribe, Connection $connection): Suback;

    public function onUnsubscribe(Unsubscribe $unsubscribe, Connection $connection): Unsuback;

    /** @param iterable<Connection> $subscribers */
    public function onPublish(Publish $publish, Connection $connection, iterable $subscribers): void;

    public function onPuback(Puback $puback, Connection $connection): void;

    public function onDisconnect(?Disconnect $disconnect, Connection $connection): void;
}
