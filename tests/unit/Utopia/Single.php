<?php

namespace Tests\Unit\Utopia;

use Appwrite\Utopia\Response\Model;

class Single extends Model
{
    public function __construct()
    {
        $this
            ->addRule('string', [
                'type' => self::TYPE_STRING,
                'example' => '5e5ea5c16897e',
                'required' => true
            ])
            ->addRule('integer', [
                'type' => self::TYPE_INTEGER,
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('boolean', [
                'type' => self::TYPE_BOOLEAN,
                'default' => true,
                'example' => true,
            ])
            ->addRule('required', [
                'type' => self::TYPE_STRING,
                'default' => 'default',
                'required' => true
            ]);
    }

    public function getName(): string
    {
        return 'Single';
    }

    public function getType(): string
    {
        return 'single';
    }
}
