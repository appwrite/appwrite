<?php

namespace Utopia\Abuse\Tests\Bench;

use RedisCluster as Client;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit\RedisCluster as RedisClusterAdapter;
use Utopia\Abuse\Tests\E2E\Services;

final class RedisCluster extends Base
{
    protected Client $redis;

    /**
     * @throws \Exception
     */
    public function setUp(): void
    {
        $this->redis = new Client(null, Services::CLUSTER_SEEDS);
        $this->adapter = new RedisClusterAdapter('login-attempt-from-{{ip}}', 3, 60 * 5, $this->redis);
        $this->abuse = new Abuse($this->adapter);
    }
}
