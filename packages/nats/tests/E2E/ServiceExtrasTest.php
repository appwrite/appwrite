<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\Message;
use Utopia\NATS\Services\Service;
use Utopia\NATS\Services\ServiceException;

final class ServiceExtrasTest extends TestCase
{
    private Connection $conn;
    private Service $service;
    private string $name;

    protected function setUp(): void
    {
        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
        $this->conn = Connection::connect($url);
        $this->name = 'svcx_' . uniqid();

        $this->service = new Service(
            $this->conn,
            $this->name,
            '1.0.0',
            'Extras service',
            ['owner' => 'levi', 'env' => 'test'],
        );
    }

    protected function tearDown(): void
    {
        $this->service->stop();
        $this->conn->close();
    }

    public function testGroupedEndpointIsReachableAtPrefixedSubject(): void
    {
        // Root group carries the unique service name so subjects don't collide.
        $group = $this->service->addGroup($this->name)->addGroup('math');
        $group->addEndpoint('add', fn(Message $msg): string => 'sum:' . $msg->data);

        // Nested group prefixes cumulatively.
        $sub = $group->addGroup('trig');
        $sub->addEndpoint('sin', fn(Message $msg): string => 'sin:' . $msg->data);

        $this->service->start();

        $r1 = $this->conn->request("{$this->name}.math.add", '2', 2.0);
        $this->assertSame('sum:2', $r1->data);

        $r2 = $this->conn->request("{$this->name}.math.trig.sin", '0', 2.0);
        $this->assertSame('sin:0', $r2->data);
    }

    public function testCustomErrorCodeSurfacesInHeadersAndCountsError(): void
    {
        $this->service->addEndpoint('fail', "{$this->name}.fail", function (Message $msg): string {
            throw new ServiceException('418', 'I am a teapot');
        });
        $this->service->start();

        $response = $this->conn->request("{$this->name}.fail", 'x', 2.0);

        $this->assertInstanceOf(\Utopia\NATS\Headers::class, $response->headers);
        $this->assertSame('418', $response->headers->get('Nats-Service-Error-Code'));
        $this->assertSame('I am a teapot', $response->headers->get('Nats-Service-Error'));

        // Error count is tracked in stats.
        $stats = json_decode($this->conn->request("\$SRV.STATS.{$this->name}", '', 2.0)->data, true);
        $byName = [];
        foreach ($stats['endpoints'] as $ep) {
            $byName[$ep['name']] = $ep;
        }
        $this->assertSame(1, $byName['fail']['num_requests']);
        $this->assertSame(1, $byName['fail']['num_errors']);
    }

    public function testInfoIncludesEndpointsWithSubjectsAndMetadata(): void
    {
        $group = $this->service->addGroup($this->name)->addGroup('v1');
        $group->addEndpoint(
            'status',
            fn(Message $msg): string => 'ok',
            null,
            null,
            ['visibility' => 'public'],
        );
        $this->service->start();

        $info = json_decode($this->conn->request("\$SRV.INFO.{$this->name}", '', 2.0)->data, true);

        // Service-level metadata.
        $this->assertSame('levi', $info['metadata']['owner']);
        $this->assertSame('test', $info['metadata']['env']);

        // Endpoint appears with its prefixed subject and its own metadata.
        $byName = [];
        foreach ($info['endpoints'] as $ep) {
            $byName[$ep['name']] = $ep;
        }
        $this->assertSame("{$this->name}.v1.status", $byName['status']['subject']);
        $this->assertSame('public', $byName['status']['metadata']['visibility']);
    }

    public function testCustomQueueGroupIsHonored(): void
    {
        $queue = 'workers_' . uniqid();

        // Group-level queue group is inherited by its endpoints.
        $group = $this->service->addGroup('jobs', $queue);
        $group->addEndpoint('run', fn(Message $msg): string => 'done');

        // Per-endpoint override on the bare service.
        $this->service->addEndpoint(
            'direct',
            "{$this->name}.direct",
            fn(Message $msg): string => 'direct',
            $queue,
        );
        $this->service->start();

        $info = json_decode($this->conn->request("\$SRV.INFO.{$this->name}", '', 2.0)->data, true);
        $byName = [];
        foreach ($info['endpoints'] as $ep) {
            $byName[$ep['name']] = $ep;
        }
        $this->assertSame($queue, $byName['run']['queue_group']);
        $this->assertSame($queue, $byName['direct']['queue_group']);
    }

    public function testQueueGroupLoadBalancesAcrossInstances(): void
    {
        $queue = 'lb_' . uniqid();
        $subject = "{$this->name}.work";

        // Two endpoints on the same subject + queue group behave as two queue
        // members: NATS delivers each request to only one of them.
        $this->service->addEndpoint('a', $subject, fn(Message $msg): string => 'a', $queue);
        $this->service->addEndpoint('b', $subject, fn(Message $msg): string => 'b', $queue);
        $this->service->start();

        $seen = ['a' => 0, 'b' => 0];
        for ($i = 0; $i < 30; $i++) {
            $reply = $this->conn->request($subject, (string) $i, 2.0)->data;
            $seen[$reply]++;
        }

        $this->assertSame(30, $seen['a'] + $seen['b']);
        $this->assertGreaterThan(0, $seen['a']);
        $this->assertGreaterThan(0, $seen['b']);
    }
}
