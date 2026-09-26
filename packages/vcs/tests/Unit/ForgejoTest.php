<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\Forgejo;

final class ForgejoTest extends GiteaBase
{
    protected static string $eventHeader = 'x-forgejo-event';
    protected static string $signatureHeader = 'x-forgejo-signature';

    protected function createAdapter(): Forgejo
    {
        return new Forgejo(new Cache(new None()));
    }
}
