<?php

namespace Tests\E2E\Scopes;

use Utopia\Database\Helpers\ID;

trait ProjectConsole
{
    public function getProject(): array
    {
        return [
            '$id' => ID::custom('console'),
            'name' => 'Appwrite',
            'apiKey' => '',
        ];
    }
}
