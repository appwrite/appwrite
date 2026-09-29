<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\Message;
use Utopia\NATS\Services\Service;

final class ServiceTest extends TestCase
{
    private Connection $conn;
    private Service $service;
    private string $name;
    private string $echoSubject;

    protected function setUp(): void
    {
        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
        $this->conn = Connection::connect($url);
        $this->name = 'svc_' . uniqid();
        $this->echoSubject = "{$this->name}.echo";

        $this->service = new Service($this->conn, $this->name, '1.2.3', 'Test service');
        $this->service->addEndpoint('echo', $this->echoSubject, fn(Message $msg): string => 'echo:' . $msg->data);
        $this->service->addEndpoint('boom', "{$this->name}.boom", function (Message $msg): string {
            throw new \RuntimeException('kaboom');
        });
        $this->service->start();
    }

    protected function tearDown(): void
    {
        $this->service->stop();
        $this->conn->close();
    }

    public function testEndpointHandlesRequest(): void
    {
        $response = $this->conn->request($this->echoSubject, 'hello', 2.0);
        $this->assertSame('echo:hello', $response->data);
    }

    public function testPingReturnsServiceIdentity(): void
    {
        $response = $this->conn->request('$SRV.PING', '', 2.0);
        $data = json_decode($response->data, true);

        $this->assertSame('io.nats.micro.v1.ping_response', $data['type']);
        $this->assertSame($this->name, $data['name']);
        $this->assertSame('1.2.3', $data['version']);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame($this->service->getId(), $data['id']);
    }

    public function testInfoListsEndpoints(): void
    {
        $response = $this->conn->request("\$SRV.INFO.{$this->name}", '', 2.0);
        $data = json_decode($response->data, true);

        $this->assertSame('io.nats.micro.v1.info_response', $data['type']);
        $this->assertSame('Test service', $data['description']);

        $subjects = array_map(fn(array $e) => $e['subject'], $data['endpoints']);
        $this->assertContains($this->echoSubject, $subjects);
    }

    public function testStatsTrackRequestAndErrorCounts(): void
    {
        // Drive one successful request and one failing request.
        $this->conn->request($this->echoSubject, 'a', 2.0);
        $this->conn->request($this->echoSubject, 'b', 2.0);
        $this->conn->request("{$this->name}.boom", 'x', 2.0);

        $response = $this->conn->request("\$SRV.STATS.{$this->name}", '', 2.0);
        $data = json_decode($response->data, true);

        $this->assertSame('io.nats.micro.v1.stats_response', $data['type']);
        $this->assertArrayHasKey('started', $data);

        $byName = [];
        foreach ($data['endpoints'] as $ep) {
            $byName[$ep['name']] = $ep;
        }

        $this->assertSame(2, $byName['echo']['num_requests']);
        $this->assertSame(0, $byName['echo']['num_errors']);
        $this->assertSame(1, $byName['boom']['num_requests']);
        $this->assertSame(1, $byName['boom']['num_errors']);
        $this->assertGreaterThanOrEqual(0, $byName['echo']['processing_time']);
    }
}
