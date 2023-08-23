<?php

namespace Tests\E2E\Scopes;

trait SideConsole
{
    public function getHeaders(): array
    {
        return [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-mode' => 'admin'
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
