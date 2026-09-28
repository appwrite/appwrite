<?php

namespace Utopia\Tests\Bench;

use Redis as Client;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit\Redis as TimeLimit;

final class Redis extends Base
{
    protected Client $redis;

    /**
     * @throws \Exception
     */
    public function setUp(): void
    {
        $this->redis = new Client();
        $this->redis->connect('redis', 6379);
        $this->adapter = new TimeLimit('login-attempt-from-{{ip}}', 3, 60 * 5, $this->redis);
        $this->abuse = new Abuse($this->adapter);
    }
}
