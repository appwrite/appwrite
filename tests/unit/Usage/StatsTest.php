<?php

namespace Tests\Unit\Usage;

use Appwrite\URL\URL as AppwriteURL;
use PHPUnit\Framework\TestCase;
use Utopia\App;
use Utopia\DSN\DSN;
use Utopia\Queue;
use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class StatsTest extends TestCase
{
    protected ?Connection $connection = null;
    protected ?Client $client = null;

    protected const QUEUE_NAME = 'usage-test-q';

    public function setUp(): void
    {
        $env =  App::getEnv('_APP_CONNECTIONS_QUEUE', AppwriteURL::unparse([
                    'scheme' => 'redis',
                    'host' => App::getEnv('_APP_REDIS_HOST', 'redis'),
                    'port' => App::getEnv('_APP_REDIS_PORT', '6379'),
                    'user' => App::getEnv('_APP_REDIS_USER', ''),
                    'pass' => App::getEnv('_APP_REDIS_PASS', ''),
                ]));

        $dsn = explode('=', $env);
        $dsn = $dsn[1] ?? '';
        $dsn = new DSN($dsn);
        $this->connection = new Queue\Connection\Redis($dsn->getHost(), $dsn->getPort());
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
