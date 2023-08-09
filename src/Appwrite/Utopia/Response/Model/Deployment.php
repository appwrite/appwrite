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
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Deployment creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Deployment update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Type of deployment.',
                'default' => '',
                'example' => 'vcs',
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
            ->addRule('entrypoint', [
                'type' => self::TYPE_STRING,
                'description' => 'The entrypoint file to use to execute the deployment code.',
                'default' => '',
                'example' => 'index.js',
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
                'description' => 'The deployment status. Possible values are "processing", "building", "waiting", "ready", and "failed".',
                'default' => '',
                'example' => 'ready',
            ])
            ->addRule('buildLogs', [
                'type' => self::TYPE_STRING,
                'description' => 'The build logs.',
                'default' => '',
                'example' => 'Compiling source files...',
            ])
            ->addRule('buildTime', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The current build time in seconds.',
                'default' => 0,
                'example' => 128,
            ])
            ->addRule('providerRepositoryName', [
                'type' => self::TYPE_STRING,
                'description' => 'The name of the vcs provider repository',
                'default' => '',
                'example' => 'database',
            ])
            ->addRule('providerRepositoryOwner', [
                'type' => self::TYPE_STRING,
                'description' => 'The name of the vcs provider repository owner',
                'default' => '',
                'example' => 'utopia',
            ])
            ->addRule('providerRepositoryUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'The url of the vcs provider repository',
                'default' => '',
                'example' => 'https://github.com/vermakhushboo/g4-node-function',
            ])
            ->addRule('providerBranch', [
                'type' => self::TYPE_STRING,
                'description' => 'The branch name of the vcs provider repository',
                'default' => '',
                'example' => 'main',
            ])
            ->addRule('providerCommitHash', [
                'type' => self::TYPE_STRING,
                'description' => 'The commit hash of the vcs commit',
                'default' => '',
                'example' => '7c3f25d',
            ])
            ->addRule('providerCommitAuthorUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'The url of vcs commit author',
                'default' => '',
                'example' => 'https://github.com/vermakhushboo',
            ])
            ->addRule('providerCommitAuthor', [
                'type' => self::TYPE_STRING,
                'description' => 'The name of vcs commit author',
                'default' => '',
                'example' => 'Khushboo Verma',
            ])
            ->addRule('providerCommitMessage', [
                'type' => self::TYPE_STRING,
                'description' => 'The commit message',
                'default' => '',
                'example' => 'Update index.js',
            ])
            ->addRule('providerCommitUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'The url of the vcs commit',
                'default' => '',
                'example' => 'https://github.com/vermakhushboo/g4-node-function/commit/60c0416257a9cbcdd96b2d370c38d8f8d150ccfb',
            ])
            ->addRule('providerBranch', [
                'type' => self::TYPE_STRING,
                'description' => 'The branch of the vcs repository',
                'default' => '',
                'example' => '0.7.x',
            ])
            ->addRule('providerBranchUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'The branch of the vcs repository',
                'default' => '',
                'example' => 'https://github.com/vermakhushboo/appwrite/tree/0.7.x',
            ]);
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
