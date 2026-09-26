<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\Gogs;

final class GogsTest extends GiteaBase
{
    protected static string $defaultBranch = 'master';

    protected static string $eventHeader = 'x-gogs-event';
    protected static string $signatureHeader = 'x-gogs-signature';

    protected function createAdapter(): Gogs
    {
        return new Gogs(new Cache(new None()));
    }
}
