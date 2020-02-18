<?php

namespace Tests\E2E\Scopes;

trait ProjectConsole
{
    public function getProject(): array
    {
        return [
            '$id' => 'console',
            'name' => 'Appwrite',
            'apiKey' => '',
        ];
    }
}
