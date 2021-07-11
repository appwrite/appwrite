<?php

namespace Tests\E2E\Scopes;

trait SideConsole
{
    public function getHeaders():array
    {
        return [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $this->getRoot()['session'],
        ];
    }

    /**
     * @return string
     */
    public function getSide()
    {
        return 'console';
    }
}
