<?php

namespace Tests\E2E\Scopes;

use Utopia\Database\Id;

trait ProjectConsole
{
    public function getProject(): array
    {
        return [
            '$id' => Id::custom('console'),
            'name' => 'Appwrite',
            'apiKey' => '',
        ];
    }
}
