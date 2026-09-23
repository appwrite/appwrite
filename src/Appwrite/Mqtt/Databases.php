<?php

namespace Appwrite\Mqtt;

use Appwrite\Database\Factory;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Group;

final readonly class Databases
{
    public function __construct(
        private Group $pools,
        private Cache $cache,
    ) {
    }

    public function console(): Database
    {
        return $this->factory()->platform(metadata: [
            'host' => \gethostname(),
            'project' => '_console',
        ]);
    }

    public function project(Document $project): Database
    {
        return $this->factory()->project($project, metadata: [
            'host' => \gethostname(),
            'project' => $project->getId(),
        ]);
    }

    private function factory(): Factory
    {
        return new Factory($this->pools, $this->cache, new Authorization());
    }
}
