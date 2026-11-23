<?php

namespace Utopia\Abuse\Tests\E2E\Challenges\Nonce;

use Utopia\Abuse\Challenges\Nonce\RedisCluster;
use Utopia\Abuse\Tests\E2E\Services;

final class RedisClusterTest extends Base
{
    private static ?\RedisCluster $redis = null;

    #[\Override]
    public function getNonce(string $key): RedisCluster
    {
        self::$redis ??= new \RedisCluster(null, Services::CLUSTER_SEEDS);

        return new RedisCluster($key, self::$redis);
    }
}
