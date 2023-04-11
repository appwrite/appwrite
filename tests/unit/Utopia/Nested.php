<?php

namespace Tests\Unit\Utopia;

use Appwrite\Utopia\Response\Model;

class Nested extends Model
{
    public function __construct()
    {
        $this
            ->addRule('lists', [
                'type' => 'lists',
                'default' => '',
            ])
            ->addRule('single', [
                'type' => 'single',
                'default' => '',
            ]);
    }

    public function getName(): string
    {
        return 'Nested';
    }

    public function getType(): string
    {
        return 'nested';
    }
}
