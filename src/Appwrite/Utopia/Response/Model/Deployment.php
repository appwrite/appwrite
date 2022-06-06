<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Deployment extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Deployment ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'Resource ID.',
                'default' => '',
                'example' => '5e5ea6g16897e',
            ])
            ->addRule('resourceType', [
                'type' => self::TYPE_STRING,
                'description' => 'Resource type.',
                'default' => '',
                'example' => 'functions',
            ])
            ->addRule('dateCreated', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The deployment creation date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('entrypoint', [
                'type' => self::TYPE_STRING,
                'description' => 'The entrypoint file to use to execute the deployment code.',
                'default' => '',
                'example' => 'enabled',
            ])
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The code size in bytes.',
                'default' => 0,
                'example' => 128,
            ])
            ->addRule('buildId', [
                'type' => self::TYPE_STRING,
                'description' => 'The current build ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('activate', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the deployment should be automatically activated.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'The deployment status.',
                'default' => '',
                'example' => 'enabled',
            ])
            ->addRule('buildStdout', [
                'type' => self::TYPE_STRING,
                'description' => 'The build stdout.',
                'default' => '',
                'example' => 'enabled',
            ])
            ->addRule('buildStderr', [
                'type' => self::TYPE_STRING,
                'description' => 'The build stderr.',
                'default' => '',
                'example' => 'enabled',
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Deployment';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_DEPLOYMENT;
    }
}
