<?php

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

/**
 * Test fixture: a filter that always throws, with a configurable code.
 * Used to assert how Request::getParams() reacts to filter exceptions.
 */
class ThrowingFilter extends Filter
{
    public int $calls = 0;

    public function __construct(private int $code, private string $reason)
    {
    }

    public function parse(array $content, string $model): array
    {
        $this->calls++;
        throw new \Exception($this->reason, $this->code);
    }
}
