<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ConsoleKeyScopeList extends Model
{
    public function __construct()
    {
        $this
            ->addRule('total', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of key scopes exposed by the server.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule('scopes', [
                'type' => Response::MODEL_CONSOLE_KEY_SCOPE,
                'description' => 'List of key scopes, each with its ID and description.',
                'default' => [],
                'array' => true,
            ])
        ;
    }

    public function getName(): string
    {
        return 'Console Key Scopes List';
    }

    public function getType(): string
    {
        return Response::MODEL_CONSOLE_KEY_SCOPE_LIST;
    }
}
