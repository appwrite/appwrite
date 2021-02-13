<?php

namespace Tests\E2E\Scopes;

trait SideClient
{
    public function getHeaders():array
    {
        return [
            'origin' => 'http://localhost',
            'cookie' => 'aw'.$this->getProject()['$id'].'=' . $this->getUser()['session'],
        ];
    }

    /**
     * @return string
     */
    public function getSide()
    {
        return 'client';
    }
}
