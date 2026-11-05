<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Client;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Specs\V3;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

use function Swoole\Coroutine\run;

/**
 * End-to-end coverage of the whole codec against the real Swoole broker
 * (tests/Fixtures/Swoole): full 3.1.1 and 5.0 sessions — CONNECT/CONNACK with
 * authentication, SUBSCRIBE/SUBACK, PUBLISH/PUBACK, wildcard fan-out to another
 * connection, PINGREQ/PINGRESP, v5 re-auth, UNSUBSCRIBE, and DISCONNECT.
 */
class AdapterTest extends TestCase
{
    private function client(float $timeout = 10): Client
    {
        return new Client('mqtt://127.0.0.1:18830', ['timeout' => $timeout]);
    }

    private function v5Auth(string $credential = 'ok'): Properties
    {
        return (new Properties())
            ->add(new Property(Property::AUTHENTICATION_METHOD, 'test'))
            ->add(new Property(Property::AUTHENTICATION_DATA, $credential))
            ->add(new Property(Property::USER, ['projectId' => 'p1']));
    }

    public function testV5FullSession(): void
    {
        run(function (): void {
            $client = $this->client();
            $client->connect();

            // CONNECT with enhanced auth + metadata -> CONNACK success. The broker
            // echoes back what it decoded, so we can assert it parsed the CONNECT.
            $client->send(V5::connect('v5-client', 60, true, $this->v5Auth()));
            $connack = Packet::parse($client->receive());
            $this->assertSame(Packet::CONNACK, $connack->type);
            $this->assertSame(V5::REASON_SUCCESS, ord($connack->body[1]));

            [$acknowledged] = Properties::parse($connack->body, 2); // past ack flags + reason
            $this->assertSame('test', $acknowledged->get(Property::AUTHENTICATION_METHOD));
            $this->assertSame(['projectId' => 'p1'], $acknowledged->user());

            // SUBSCRIBE -> SUBACK granted QoS 1 (after packet id + empty property block).
            $client->send(V5::subscribe(1, ['sensors/+/temp'], 1));
            $suback = Packet::parse($client->receive());
            $this->assertSame(Packet::SUBACK, $suback->type);
            $this->assertSame(chr(Packet::QOS_1), substr($suback->body, 3, 1));

            // PINGREQ -> PINGRESP.
            $client->send(Packet::pingreq());
            $this->assertSame(Packet::PINGRESP, Packet::parse($client->receive())->type);

            // Re-auth mid-connection -> AUTH.
            $client->send(V5::auth(V5::AUTH_REAUTH, $this->v5Auth('fresh')));
            $this->assertSame(Packet::AUTH, Packet::parse($client->receive())->type);

            // UNSUBSCRIBE -> UNSUBACK.
            $client->send(V5::unsubscribe(2, ['sensors/+/temp']));
            $this->assertSame(Packet::UNSUBACK, Packet::parse($client->receive())->type);

            // DISCONNECT closes the socket.
            $client->send(V5::disconnect(V5::REASON_SUCCESS));
            $this->assertNull($client->receive());
            $this->assertFalse($client->isConnected());
        });
    }

    public function testV5PublishFanOutWithWildcard(): void
    {
        run(function (): void {
            $subscriber = $this->client();
            $subscriber->connect();
            $subscriber->send(V5::connect('v5-sub', 60, true, $this->v5Auth()));
            Packet::parse($subscriber->receive()); // connack
            $subscriber->send(V5::subscribe(1, ['sensors/+/temp'], 1));
            Packet::parse($subscriber->receive()); // suback

            $publisher = $this->client();
            $publisher->connect();
            $publisher->send(V5::connect('v5-pub', 60, true, $this->v5Auth()));
            Packet::parse($publisher->receive()); // connack

            // QoS 1 PUBLISH to a topic the wildcard matches, with a User Property.
            $properties = (new Properties())->add(new Property(Property::USER, ['unit' => 'C']));
            $publisher->send(V5::publish('sensors/room1/temp', '21.5', 1, 100, $properties));

            // Publisher gets its PUBACK.
            $this->assertSame(Packet::PUBACK, Packet::parse($publisher->receive())->type);

            // Subscriber gets the message, and the publisher's property survived the
            // broker's decode -> re-encode round-trip.
            $delivered = Packet::parse($subscriber->receive());
            $this->assertSame(Packet::PUBLISH, $delivered->type);
            [$topic, $offset] = Packet::readString($delivered->body, 0);
            $this->assertSame('sensors/room1/temp', $topic);
            [$deliveredProperties, $offset] = Properties::parse($delivered->body, $offset); // QoS 0
            $this->assertSame(['unit' => 'C'], $deliveredProperties->user());
            $this->assertSame('21.5', substr($delivered->body, $offset));
        });
    }

    public function testV3FullSession(): void
    {
        run(function (): void {
            $client = $this->client();
            $client->connect();

            // CONNECT with username/password -> CONNACK accepted.
            $client->send(V3::connect('v3-client', 60, true, 'user', 'ok'));
            $connack = Packet::parse($client->receive());
            $this->assertSame(Packet::CONNACK, $connack->type);
            $this->assertSame(V3::RETURN_ACCEPTED, ord($connack->body[1]));

            // SUBSCRIBE -> SUBACK granted QoS 1 (no property block in 3.1.1).
            $client->send(V3::subscribe(1, ['news/tech'], 1));
            $suback = Packet::parse($client->receive());
            $this->assertSame(Packet::SUBACK, $suback->type);
            $this->assertSame(chr(Packet::QOS_1), substr($suback->body, 2, 1));

            // PUBLISH QoS 0 to the subscribed topic -> delivered back (no property block).
            $client->send(V3::publish('news/tech', 'hello', 0, 0));
            $delivered = Packet::parse($client->receive());
            $this->assertSame(Packet::PUBLISH, $delivered->type);
            [$topic, $offset] = Packet::readString($delivered->body, 0);
            $this->assertSame('news/tech', $topic);
            $this->assertSame('hello', substr($delivered->body, $offset));

            $client->send(V3::disconnect());
            $this->assertNull($client->receive());
            $this->assertFalse($client->isConnected());
        });
    }

    public function testAuthRejectionClosesConnection(): void
    {
        run(function (): void {
            // v5: a "deny" credential is refused with reason Not Authorized, then closed.
            $v5 = $this->client();
            $v5->connect();
            $v5->send(V5::connect('v5-bad', 60, true, $this->v5Auth('deny')));
            $connack = Packet::parse($v5->receive());
            $this->assertSame(Packet::CONNACK, $connack->type);
            $this->assertSame(V5::REASON_NOT_AUTHORIZED, ord($connack->body[1]));
            $this->assertNull($v5->receive());
            $this->assertFalse($v5->isConnected());

            // v3: a "deny" password is refused with return code Not Authorized.
            $v3 = $this->client();
            $v3->connect();
            $v3->send(V3::connect('v3-bad', 60, true, 'user', 'deny'));
            $connack = Packet::parse($v3->receive());
            $this->assertSame(Packet::CONNACK, $connack->type);
            $this->assertSame(V3::RETURN_NOT_AUTHORIZED, ord($connack->body[1]));
            $this->assertNull($v3->receive()); // observe the server-side close
            $this->assertFalse($v3->isConnected());
        });
    }

    public function testUnsubscribeStopsDelivery(): void
    {
        run(function (): void {
            $client = $this->client(timeout: 2);
            $client->connect();
            $client->send(V5::connect('v5-unsub', 60, true, $this->v5Auth()));
            Packet::parse($client->receive()); // connack

            $client->send(V5::subscribe(1, ['room/1'], 0));
            Packet::parse($client->receive()); // suback

            // Subscribed: the self-published message is delivered.
            $client->send(V5::publish('room/1', 'first', 0, 0));
            $this->assertSame(Packet::PUBLISH, Packet::parse($client->receive())->type);

            // Unsubscribe, then publish again: nothing is delivered (receive times out).
            $client->send(V5::unsubscribe(2, ['room/1']));
            Packet::parse($client->receive()); // unsuback
            $client->send(V5::publish('room/1', 'second', 0, 0));
            $this->assertNull($client->receive());
        });
    }
}
