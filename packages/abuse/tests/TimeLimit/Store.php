<?php

namespace Utopia\Abuse\Tests\TimeLimit;

final class Store
{
    /**
     * @var array<string, int>
     */
    public array $counts = [];

    public int $calls = 0;

    public function __construct(public int $now)
    {
    }
}
