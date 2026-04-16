<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ProviderRepository extends Model
{
    public function __construct()
    {
        $this
            ->addRule('id', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) repository ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) repository name.',
                'default' => '',
                'example' => 'appwrite',
            ])
            ->addRule('organization', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) organization name',
                'default' => [],
                'example' => 'appwrite',
                'array' => false,
            ])
            ->addRule('provider', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) provider name.',
                'default' => '',
                'example' => 'github',
            ])
            ->addRule('private', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is VCS (Version Control System) repository private?',
                'default' => false,
                'example' => true,
            ])
            ->addRule('defaultBranch', [
                'type' => self::TYPE_STRING,
                'description' => "VCS (Version Control System) repository's default branch name.",
                'default' => '',
                'example' => 'main',
            ])
            ->addRule('pushedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Last commit date in ISO 8601 format.',
                'default' => APP_DATABASE_ATTRIBUTE_DATETIME,
                'example' => APP_DATABASE_ATTRIBUTE_DATETIME,
                'array' => false,
            ])
            ->addRule('variables', [
                'type' => self::TYPE_STRING,
                'description' => 'Environment variables found in .env files',
                'default' => [],
                'array' => true,
                'example' => ['PORT', 'NODE_ENV'],
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'ProviderRepository';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PROVIDER_REPOSITORY;
    }
}
