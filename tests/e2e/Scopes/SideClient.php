<?php

namespace Tests\E2E\Scopes;

trait SideClient
{
    public function getHeaders():array
    {
        return [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_'.$this->getProject()['$uid'].'=' . $this->getUser()['session'],
        ];
    }
}
