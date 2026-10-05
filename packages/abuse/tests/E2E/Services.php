<?php

namespace Utopia\Abuse\Tests\E2E;

/**
 * Where docker-compose.yml publishes the services on the host; keep in sync.
 */
final class Services
{
    public const string HOST = '127.0.0.1';

    public const int REDIS_PORT = 16386;

    public const int MYSQL_PORT = 13308;

    /** The cluster advertises its own ports, so they are not remapped. */
    public const array CLUSTER_SEEDS = ['127.0.0.1:17010', '127.0.0.1:17011', '127.0.0.1:17012'];
}
