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
            ->addRule('functionId', [
                'type' => self::TYPE_STRING,
                'description' => 'Function ID.',
                'default' => '',
                'example' => '5e5ea6g16897e',
            ])
            ->addRule('dateCreated', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The deployment creation date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('entrypoint', [
                'type' => self::TYPE_STRING,
                'description' => 'The entrypoint file to use to execute the delpoyment code.',
                'default' => '',
                'example' => 'enabled',
            ])
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The code size in bytes.',
                'default' => 0,
                'example' => 128,
            ])
            // Build Status
            // Failed - The deployment build has failed. More details can usually be found in buildStderr
            // Ready - The deployment build was successful and the deployment is ready to be deployed
            // Processing - The deployment is currently waiting to have a build triggered
            // Building - The deployment is currently being built
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'The deployment\'s current built status',
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
            ->addRule('deploy', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the deployment should be automatically deployed.',
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
        return 'Deployment';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_DEPLOYMENT;
    }
}
