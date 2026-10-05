<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

use function Swoole\Coroutine\run;

/**
 * End-to-end coverage of the MQTT-over-WebSocket carrier against the real Swoole broker
 * (tests/Fixtures/Swoole, WebSocket port 18831): the same CONNECT/CONNACK, SUBSCRIBE/SUBACK
 * and PING exchange as the raw-TCP AdapterTest, but each MQTT packet rides in a WebSocket
 * binary frame, proving the adapter's WebSocket listener, packet reassembly, and framed send.
 */
class WebSocketAdapterTest extends TestCase
{
    private Client $client;

    private string $buffer = '';

    private function auth(): Properties
    {
        return (new Properties())
            ->add(new Property(Property::AUTHENTICATION_METHOD, 'test'))
            ->add(new Property(Property::USER, ['projectId' => 'p1']));
    }

    public function testV5SessionOverWebSocket(): void
    {
        run(function (): void {
            $this->client = new Client('127.0.0.1', 18831);
            $this->client->set(['timeout' => 10]);
            $this->assertTrue($this->client->upgrade('/mqtt'), 'websocket upgrade failed');

            $this->send(V5::connect('ws-client', 60, true, $this->auth()));
            $connack = $this->receive();
            $this->assertSame(Packet::CONNACK, $connack->type);
            $this->assertSame(V5::REASON_SUCCESS, ord($connack->body[1]));

            $this->send(V5::subscribe(1, ['sensors/+/temp'], 1));
            $suback = $this->receive();
            $this->assertSame(Packet::SUBACK, $suback->type);
            $this->assertSame(chr(Packet::QOS_1), substr($suback->body, 3, 1));

            $this->send(Packet::pingreq());
            $this->assertSame(Packet::PINGRESP, $this->receive()->type);

            $this->client->close();
        });
    }

    /** Send one MQTT packet as a WebSocket binary frame. */
    private function send(string $packet): void
    {
        $this->assertTrue($this->client->push($packet, WEBSOCKET_OPCODE_BINARY));
    }

    /** Read frames until one whole MQTT packet is reassembled out of their payloads. */
    private function receive(): Packet
    {
        while (true) {
            [$packets, $rest] = Packet::frames($this->buffer);
            if ($packets !== []) {
                $this->buffer = $rest;

                return Packet::parse($packets[0]);
            }

            $frame = $this->client->recv(10);
            $this->assertInstanceOf(Frame::class, $frame, 'websocket receive failed');
            $this->buffer .= (string) $frame->data;
        }
    }
}
