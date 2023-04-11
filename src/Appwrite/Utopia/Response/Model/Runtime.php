<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Runtime extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Runtime ID.',
                'default' => '',
                'example' => 'python-3.8',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Runtime Name.',
                'default' => '',
                'example' => 'Python',
            ])
            ->addRule('version', [
                'type' => self::TYPE_STRING,
                'description' => 'Runtime version.',
                'default' => '',
                'example' => '3.8',
            ])
            ->addRule('base', [
                'type' => self::TYPE_STRING,
                'description' => 'Base Docker image used to build the runtime.',
                'default' => '',
                'example' => 'python:3.8-alpine',
            ])
            ->addRule('image', [
                'type' => self::TYPE_STRING,
                'description' => 'Image name of Docker Hub.',
                'default' => '',
                'example' => 'appwrite\/runtime-for-python:3.8',
            ])
            ->addRule('logo', [
                'type' => self::TYPE_STRING,
                'description' => 'Name of the logo image.',
                'default' => '',
                'example' => 'python.png',
            ])
            ->addRule('supports', [
                'type' => self::TYPE_STRING,
                'description' => 'List of supported architectures.',
                'default' => '',
                'example' => 'amd64',
                'array' => true,
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Runtime';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_RUNTIME;
    }
}
