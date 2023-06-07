<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use stdClass;
use Utopia\Database\Document;

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
            ->addRule('logging', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Function logging.',
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
                'example' => 1592981237,
            ])
            ->addRule('entrypoint', [
                'type' => self::TYPE_STRING,
                'description' => 'The entrypoint file used to execute the deployment.',
                'default' => '',
                'example' => 'index.js',
            ])
            ->addRule('buildCommand', [
                'type' => self::TYPE_STRING,
                'description' => 'The build command used to build the deployment.',
                'default' => '',
                'example' => 'npm run build',
            ])
            ->addRule('installCommand', [
                'type' => self::TYPE_STRING,
                'description' => 'The install command used to build the deployment.',
                'default' => '',
                'example' => 'npm install',
            ])
            ->addRule('vcsInstallationId', [
                'type' => self::TYPE_STRING,
                'description' => 'Function vcs installation id.',
                'default' => '',
                'example' => '644051bd6572792165cc',
            ])
            ->addRule('vcsRepositoryId', [
                'type' => self::TYPE_STRING,
                'description' => 'Git Repository ID',
                'default' => '',
                'example' => 'appwrite',
            ])
            ->addRule('vcsBranch', [
                'type' => self::TYPE_STRING,
                'description' => 'Git branch name',
                'default' => '',
                'example' => 'main',
            ])
            ->addRule('vcsRootDirectory', [
                'type' => self::TYPE_STRING,
                'description' => 'Path to function in git repository',
                'default' => '',
                'example' => 'functions/helloWorld',
            ])
            ->addRule('vcsSilentMode', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is VCS connection is in silent mode?',
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
