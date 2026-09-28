<?php

namespace Utopia\Abuse\Tests\Bench;

use Redis as Client;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit\Redis as TimeLimit;
use Utopia\Abuse\Tests\E2E\Services;

final class Redis extends Base
{
    protected Client $redis;

    /**
     * @throws \Exception
     */
    public function setUp(): void
    {
        $this->redis = new Client();
        $this->redis->connect(Services::HOST, Services::REDIS_PORT);
        $this->adapter = new TimeLimit('login-attempt-from-{{ip}}', 3, 60 * 5, $this->redis);
        $this->abuse = new Abuse($this->adapter);
    }
}
