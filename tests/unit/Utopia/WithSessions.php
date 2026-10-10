<?php

namespace Tests\Unit\Utopia;

use Appwrite\Utopia\Response\Model;

class WithSessions extends Model
{
    public function __construct()
    {
        $this->addRule('sessions', [
            'type' => 'single',
            'array' => true,
            'default' => [],
            'example' => [],
        ]);
    }

    public function getName(): string
    {
        return 'WithSessions';
    }

    public function getType(): string
    {
        return 'withSessions';
    }
}
