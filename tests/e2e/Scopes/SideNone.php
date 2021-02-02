<?php

namespace Tests\E2E\Scopes;

trait SideNone
{
    public function getHeaders():array
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
