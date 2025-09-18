<?php

namespace Tests\E2E\Scopes;

trait SideNone
{
    public function getHeaders(bool $devKey = true): array
    {
        return [];
    }

    /**
     * @return string
     */
    public function getSide()
    {
        return 'none';
    }
}
