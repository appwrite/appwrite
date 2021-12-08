<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Tag extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Tag ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('functionId', [
                'type' => self::TYPE_STRING,
                'description' => 'Function ID.',
                'default' => '',
                'example' => '5e5ea6g16897e',
            ])
            ->addRule('dateCreated', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The tag creation date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('entrypoint', [
                'type' => self::TYPE_STRING,
                'description' => 'The entrypoint file to use to execute the tag code.',
                'default' => '',
                'example' => 'enabled',
            ])
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The code size in bytes.',
                'default' => 0,
                'example' => 128,
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'The tags current built status',
                'default' => '',
                'example' => 'ready',
            ])
            ->addRule('buildId', [
                'type' => self::TYPE_STRING,
                'description' => 'The current build ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('buildStdout', [
                'type' => self::TYPE_STRING,
                'description' => 'The stdout of the build.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('buildStderr', [
                'type' => self::TYPE_STRING,
                'description' => 'The stderr of the build.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('automaticDeploy', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the tag should be automatically deployed.',
                'default' => false,
                'example' => true,
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName():string
    {
        return 'Tag';
    }

    /**
     * Get Collection
     *
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_TAG;
    }
}
