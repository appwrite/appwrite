<?php

namespace Tests\E2E\Scopes;

trait ProjectConsole
{
    public function getProject(): array
    {
        return [
            '$uid' => 'console',
            'name' => 'Appwrite',
            'apiKey' => '',
        ];
    }
}
