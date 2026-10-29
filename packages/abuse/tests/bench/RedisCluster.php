<?php

namespace Utopia\Abuse\Tests\Bench;

use RedisCluster as Client;
use Utopia\Abuse\Adapter\TimeLimit\RedisCluster as RedisClusterAdapter;
use Utopia\Abuse\Tests\E2E\Services;

final class RedisCluster extends Base
{
    protected Client $redis;

    /**
     * @throws \Exception
     */
    #[\Override]
    public function setUp(): void
    {
        $this->redis = new Client(null, Services::CLUSTER_SEEDS);
        $this->adapter = new RedisClusterAdapter('login-attempt-from-{{ip}}', 3, 60 * 5, $this->redis);
    }
}
