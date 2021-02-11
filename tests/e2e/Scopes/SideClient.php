<?php

namespace Tests\E2E\Scopes;

trait SideClient
{
    public function getHeaders():array
    {
        return [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $this->getUser()['session'],
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
