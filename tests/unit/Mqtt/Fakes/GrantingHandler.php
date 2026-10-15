<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Fakes;

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
 * A broker handler that accepts every CONNECT (identity from the projectId/userId user
 * properties) and grants every SUBSCRIBE filter. It installs subscriptions that Appwrite's
 * subscribe gate would refuse, such as one granted before the gate existed, so delivery
 * can be tested on its own.
 */
final class GrantingHandler implements Handler
{
    public function onConnect(Connect $connect, Connection $connection): Connack
    {
        $properties = $connect->userProperties();
        $connection->prefix = $properties['projectId'] ?? '';
        $connection->identity = ['projectId' => $connection->prefix, 'userId' => $properties['userId'] ?? ''];

        return Connack::accept();
    }

    public function onAuthenticate(Auth $auth, Connection $connection): Connack
    {
        return Connack::accept();
    }

    public function onSubscribe(Subscribe $subscribe, Connection $connection): Suback
    {
        $suback = new Suback();
        foreach ($subscribe->filters() as $filter) {
            $suback->grant($filter->qos);
        }

        return $suback;
    }

    public function onUnsubscribe(Unsubscribe $unsubscribe, Connection $connection): Unsuback
    {
        return new Unsuback();
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
