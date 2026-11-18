<?php

declare(strict_types=1);

namespace Utopia\Client\Tests\Server;

final class Tally
{
    public int $connections = 0;

    public int $served = 0;

    public int $resets = 0;
}
