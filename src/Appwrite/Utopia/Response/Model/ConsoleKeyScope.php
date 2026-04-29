<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ConsoleKeyScope extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Scope ID.',
                'default' => '',
                'example' => 'users.read',
            ])
            ->addRule('description', [
                'type' => self::TYPE_STRING,
                'description' => 'Scope description.',
                'default' => '',
                'example' => 'Access to read your project\'s users',
            ])
        ;
    }

    public function getName(): string
    {
        return 'Console Key Scope';
    }

    public function getType(): string
    {
        return Response::MODEL_CONSOLE_KEY_SCOPE;
    }
}
