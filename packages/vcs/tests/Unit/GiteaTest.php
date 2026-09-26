<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\Gitea;

final class GiteaTest extends GiteaBase
{
    protected static string $eventHeader = 'x-gitea-event';
    protected static string $signatureHeader = 'x-gitea-signature';

    protected function createAdapter(): Gitea
    {
        return new Gitea(new Cache(new None()));
    }
}
