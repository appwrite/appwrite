<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Client;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

use function Swoole\Coroutine\run;

/**
 * End-to-end coverage of the TLS-wrapped TCP listener against the real Swoole broker
 * (tests/Fixtures/Swoole, mqtts port 18832): the same CONNECT/CONNACK, SUBSCRIBE/SUBACK
 * and PING exchange as the raw-TCP AdapterTest, but over a TLS connection, proving the
 * Tls transport's socket flags and SSL listener settings.
 */
class TlsAdapterTest extends TestCase
{
    private function v5Auth(string $credential = 'ok'): Properties
    {
        return (new Properties())
            ->add(new Property(Property::AUTHENTICATION_METHOD, 'test'))
            ->add(new Property(Property::AUTHENTICATION_DATA, $credential))
            ->add(new Property(Property::USER, ['projectId' => 'p1']));
    }

    public function testV5SessionOverTls(): void
    {
        run(function (): void {
            $client = new Client('mqtts://127.0.0.1:18832', ['timeout' => 10]);
            $client->connect();

            $client->send(V5::connect('tls-client', 60, true, $this->v5Auth()));
            $connack = $this->receive($client);
            $this->assertSame(Packet::CONNACK, $connack->type);
            $this->assertSame(V5::REASON_SUCCESS, ord($connack->body[1]));

            $client->send(V5::subscribe(1, ['sensors/+/temp'], 1));
            $suback = $this->receive($client);
            $this->assertSame(Packet::SUBACK, $suback->type);
            $this->assertSame(chr(Packet::QOS_1), substr($suback->body, 3, 1));

            $client->send(Packet::pingreq());
            $this->assertSame(Packet::PINGRESP, $this->receive($client)->type);

            $client->send(V5::disconnect(V5::REASON_SUCCESS));
            $this->assertNull($client->receive());
            $this->assertFalse($client->isConnected());
        });
    }

    /** Read one MQTT packet, failing the test if the broker sent nothing. */
    private function receive(Client $client): Packet
    {
        $raw = $client->receive();
        $this->assertNotNull($raw, 'expected an MQTT packet from the broker');

        return Packet::parse($raw);
    }
}
