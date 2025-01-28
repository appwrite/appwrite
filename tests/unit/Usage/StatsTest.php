<?php

namespace Tests\Unit\Usage;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class StatsTest extends TestCase
{
    protected ?Connection $connection = null;
    protected ?Client $client = null;

    protected const QUEUE_NAME = 'usage-test-q';

    public function setUp(): void
    {
        global $register;
        $connection = $register->get('pools')->get('queue')->pop()->getResource();
        $this->connection = $connection;
        $this->client     = new Client(self::QUEUE_NAME, $this->connection);
    }

    public function tearDown(): void
    {
    }

    public function testSamePayload(): void
    {
        $inToQueue = [
            'key_1'  => 'value_1',
            'key_2'  => 'value_2',
        ];

        $result = $this->client->enqueue($inToQueue);
        $this->assertTrue($result);
        $outFromQueue  = $this->connection->leftPopArray('utopia-queue.queue.' . self::QUEUE_NAME, 0)['payload'];
        $this->assertNotEmpty($outFromQueue);
        $this->assertSame($inToQueue, $outFromQueue);
    }
}
