<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class FunctionFramework extends Model
{
    public function __construct()
    {
        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Framework key.',
                'default' => '',
                'example' => 'express',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Framework name.',
                'default' => '',
                'example' => 'Express',
            ])
            ->addRule('runtimes', [
                'type' => self::TYPE_STRING,
                'description' => 'Runtimes the framework can run on.',
                'default' => [],
                'example' => 'node-22',
                'array' => true,
            ])
            ->addRule('entrypoint', [
                'type' => self::TYPE_STRING,
                'description' => 'Default entrypoint: the file that starts the app.',
                'default' => '',
                'example' => 'server.js',
            ])
            ->addRule('installCommand', [
                'type' => self::TYPE_STRING,
                'description' => 'Default build command.',
                'default' => '',
                'example' => 'npm install',
            ]);
    }

    public function getName(): string
    {
        return 'Function Framework';
    }

    public function getType(): string
    {
        return Response::MODEL_FUNCTION_FRAMEWORK;
    }
}
