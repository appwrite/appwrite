<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class TemplateRuntime extends Model
{
    public function __construct()
    {
        $this
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Runtime Name.',
                'default' => '',
                'example' => 'node-19.0',
            ])
            ->addRule('commands', [
                'type' => self::TYPE_STRING,
                'description' => 'The build command used to build the deployment.',
                'default' => '',
                'example' => 'npm install',
            ])
            ->addRule('entrypoint', [
                'type' => self::TYPE_STRING,
                'description' => 'The entrypoint file used to execute the deployment.',
                'default' => '',
                'example' => 'index.js',
            ])
            ->addRule('providerRootDirectory', [
                'type' => self::TYPE_STRING,
                'description' => 'Path to function in VCS (Version Control System) repository',
                'default' => '',
                'example' => 'node/starter',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Template Runtime';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_TEMPLATE_RUNTIME;
    }
}
