<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class MigrationVercelProject extends Model
{
    public function __construct()
    {
        $this
            ->addRule('projectId', [
                'type' => self::TYPE_STRING,
                'description' => 'Vercel project ID.',
                'default' => '',
                'example' => 'prj_12HKQaOmR5t5Uy6vdcQsNIiZgHGB',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Project name.',
                'default' => '',
                'example' => 'my-site',
            ])
            ->addRule('framework', [
                'type' => self::TYPE_STRING,
                'description' => 'Appwrite framework the project builds as, or other when Appwrite has no match for its Vercel framework.',
                'default' => 'other',
                'example' => 'nextjs',
            ])
            ->addRule('installCommand', [
                'type' => self::TYPE_STRING,
                'description' => 'Install command, empty when the project uses its framework default.',
                'default' => '',
                'example' => 'npm install',
            ])
            ->addRule('buildCommand', [
                'type' => self::TYPE_STRING,
                'description' => 'Build command, empty when the project uses its framework default.',
                'default' => '',
                'example' => 'npm run build',
            ])
            ->addRule('outputDirectory', [
                'type' => self::TYPE_STRING,
                'description' => 'Output directory, empty when the project uses its framework default.',
                'default' => '',
                'example' => 'dist',
            ])
            ->addRule('rootDirectory', [
                'type' => self::TYPE_STRING,
                'description' => 'Path to the site code in the repository.',
                'default' => '',
                'example' => 'apps/web',
            ])
            ->addRule('repositoryProvider', [
                'type' => self::TYPE_STRING,
                'description' => 'Git provider of the linked repository, empty when the project has none.',
                'default' => '',
                'example' => 'github',
            ])
            ->addRule('repositoryOwner', [
                'type' => self::TYPE_STRING,
                'description' => 'Owner of the linked repository.',
                'default' => '',
                'example' => 'appwrite',
            ])
            ->addRule('repositoryName', [
                'type' => self::TYPE_STRING,
                'description' => 'Name of the linked repository.',
                'default' => '',
                'example' => 'website',
            ])
            ->addRule('branch', [
                'type' => self::TYPE_STRING,
                'description' => 'Production branch of the linked repository.',
                'default' => '',
                'example' => 'main',
            ])
            ->addRule('variables', [
                'type' => Response::MODEL_MIGRATION_VERCEL_VARIABLE,
                'description' => 'Production environment variables. Empty when listing projects.',
                'default' => [],
                'example' => [],
                'array' => true,
            ]);
    }

    public function getName(): string
    {
        return 'MigrationVercelProject';
    }

    public function getType(): string
    {
        return Response::MODEL_MIGRATION_VERCEL_PROJECT;
    }
}
