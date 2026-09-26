<?php

declare(strict_types=1);

namespace Tests\E2E\Utopia\DNS;

use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;
use Utopia\DNS\ProxyProtocol;

final class ProxyProtocolTest extends TestCase
{
    public const int PORT = 5302;

    public function testV1Header(): void
    {
        $response = $this->queryBehindProxy("PROXY TCP4 203.0.113.9 10.0.0.1 42424 53\r\n");

        $this->assertCount(1, $response->answers);
        $this->assertSame('dev.appwrite.io', $response->answers[0]->name);
        $this->assertSame('180.12.3.24', $response->answers[0]->rdata);
    }

    public function testV2Header(): void
    {
        $addresses = inet_pton('203.0.113.9') . inet_pton('10.0.0.1') . pack('n', 42424) . pack('n', 53);
        $header = ProxyProtocol::SIGNATURE_V2 . "\x21\x11" . pack('n', \strlen($addresses)) . $addresses;

        $response = $this->queryBehindProxy($header);

        $this->assertCount(1, $response->answers);
        $this->assertSame('180.12.3.24', $response->answers[0]->rdata);
    }

    public function testInvalidHeaderClosesConnection(): void
    {
        $socket = $this->connect();
        fwrite($socket, "NOT A PROXY HEADER\r\n");

        $this->assertSame('', (string) stream_get_contents($socket, 2));
        fclose($socket);
    }

    protected function queryBehindProxy(string $proxyHeader): Message
    {
        $query = Message::query(new Question('dev.appwrite.io', Record::TYPE_A))->encode();

        $socket = $this->connect();
        fwrite($socket, $proxyHeader . pack('n', \strlen($query)) . $query);

        $prefix = (string) stream_get_contents($socket, 2);
        $this->assertSame(2, \strlen($prefix), 'Expected a 2-byte length prefix');

        $unpacked = unpack('n', $prefix);
        $this->assertIsArray($unpacked);
        $this->assertIsInt($unpacked[1]);

        $payload = (string) stream_get_contents($socket, $unpacked[1]);
        fclose($socket);

        return Message::decode($payload);
    }

    /**
     * @return resource
     */
    protected function connect()
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::PORT, $errorCode, $errorMessage, 5);
        $this->assertNotFalse($socket, \sprintf('Connection failed: %s', $errorMessage));
        stream_set_timeout($socket, 5);

        return $socket;
    }
}
