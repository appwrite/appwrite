<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Func extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Function ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Function creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Function update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('execute', [
                'type' => self::TYPE_STRING,
                'description' => 'Execution permissions.',
                'default' => [],
                'example' => 'users',
                'array' => true,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Function name.',
                'default' => '',
                'example' => 'My Function',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Function enabled.',
                'default' => true,
                'example' => false,
            ])
            ->addRule('live', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is the function deployed with the latest configuration? This is set to false if you\'ve changed an environment variables, entrypoint, commands, or other settings that needs redeploy to be applied. When the value is false, redeploy the function to update it with the latest configuration.',
                'default' => true,
                'example' => false,
            ])
            ->addRule('logging', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether executions will be logged. When set to false, executions will not be logged, but will reduce resource used by your Appwrite project.',
                'default' => true,
                'example' => false,
            ])
            ->addRule('runtime', [
                'type' => self::TYPE_STRING,
                'description' => 'Function execution runtime.',
                'default' => '',
                'example' => 'python-3.8',
            ])
            ->addRule('deployment', [
                'type' => self::TYPE_STRING,
                'description' => 'Function\'s active deployment ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('vars', [
                'type' => Response::MODEL_VARIABLE,
                'description' => 'Function variables.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('events', [
                'type' => self::TYPE_STRING,
                'description' => 'Function trigger events.',
                'default' => [],
                'example' => 'account.create',
                'array' => true,
            ])
            ->addRule('schedule', [
                'type' => self::TYPE_STRING,
                'description' => 'Function execution schedult in CRON format.',
                'default' => '',
                'example' => '5 4 * * *',
            ])
            ->addRule('timeout', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Function execution timeout in seconds.',
                'default' => 15,
                'example' => 300,
            ])
            ->addRule('entrypoint', [
                'type' => self::TYPE_STRING,
                'description' => 'The entrypoint file used to execute the deployment.',
                'default' => '',
                'example' => 'index.js',
            ])
            ->addRule('commands', [
                'type' => self::TYPE_STRING,
                'description' => 'The build command used to build the deployment.',
                'default' => '',
                'example' => 'npm install',
            ])
            ->addRule('version', [
                'type' => self::TYPE_STRING,
                'description' => 'Version of Open Runtimes used for the function.',
                'default' => 'v3',
                'example' => 'v2',
            ])
            ->addRule('installationId', [
                'type' => self::TYPE_STRING,
                'description' => 'Function VCS (Version Control System) installation id.',
                'default' => '',
                'example' => '6m40at4ejk5h2u9s1hboo',
            ])
            ->addRule('providerRepositoryId', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) Repository ID',
                'default' => '',
                'example' => 'appwrite',
            ])
            ->addRule('providerBranch', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) branch name',
                'default' => '',
                'example' => 'main',
            ])
            ->addRule('providerRootDirectory', [
                'type' => self::TYPE_STRING,
                'description' => 'Path to function in VCS (Version Control System) repository',
                'default' => '',
                'example' => 'functions/helloWorld',
            ])
            ->addRule('providerSilentMode', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is VCS (Version Control System) connection is in silent mode? When in silence mode, no comments will be posted on the repository pull or merge requests',
                'default' => false,
                'example' => false,
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
        return 'Function';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_FUNCTION;
    }
}
