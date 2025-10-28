<?php

namespace Tests\E2E\Scopes;

trait SideClient
{
    public function getHeaders(bool $devKey = true): array
    {
        $headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $this->getUser()['session'],

        ];
        if ($devKey && isset($this->getProject()['devKey'])) {
            $headers['x-appwrite-dev-key'] = $this->getProject()['devKey'];
        }
        return $headers;
    }

    /**
     * @return string
     */
    public function getSide()
    {
        return 'client';
    }
}
