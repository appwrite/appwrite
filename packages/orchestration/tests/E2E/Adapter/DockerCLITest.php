<?php

namespace Utopia\Orchestration\Tests\E2E\Adapter;

use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Orchestration;
use Utopia\Orchestration\Tests\E2E\Base;

class DockerCLITest extends Base
{
    /**
     * @var Orchestration|null
     */
    public static $orchestration = null;

    /**
     * Return name of adapter
     */
    public static function getAdapterName(): string
    {
        return 'Docker CLI';
    }

    public static function getOrchestration(): Orchestration
    {
        if (! is_null(self::$orchestration)) {
            return self::$orchestration;
        }

        $orchestration = new Orchestration(new DockerCLI());

        return self::$orchestration = $orchestration;
    }
}
