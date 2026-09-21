<?php

namespace Tests\E2E\Scopes;

trait SideServer
{
    /**
     * @var array
     */
    protected $key = [];

    public function getHeaders(): array
    {
        return [
            'x-appwrite-key' => $this->getProject()['apiKey']
        ];
    }

    /**
     * @return string
     */
    public function getSide()
    {
        return 'server';
    }
}
