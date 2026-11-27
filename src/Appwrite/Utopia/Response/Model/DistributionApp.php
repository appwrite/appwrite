<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Distribution\Build\Status as BuildStatus;
use Appwrite\Distribution\Framework;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class DistributionApp extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'App ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'App creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'App update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'App name.',
                'default' => '',
                'example' => 'My App',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the app is enabled. A disabled app ignores pushes and rejects new builds.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('framework', [
                'type' => self::TYPE_ENUM,
                'description' => 'Framework the app is built with.',
                'default' => Framework::Flutter->value,
                'example' => Framework::Flutter->value,
                'enum' => Framework::values(),
                'enumSDKName' => 'DistributionFramework',
            ])
            ->addRule('installationId', [
                'type' => self::TYPE_STRING,
                'description' => 'App VCS (Version Control System) installation ID.',
                'default' => '',
                'example' => '6m40at4ejk5h2u9s1hboo',
            ])
            ->addRule('providerRepositoryId', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) repository ID.',
                'default' => '',
                'example' => 'appwrite',
            ])
            ->addRule('repositoryId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the connected repository.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('providerBranch', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) production branch name.',
                'default' => '',
                'example' => 'main',
            ])
            ->addRule('providerRootDirectory', [
                'type' => self::TYPE_STRING,
                'description' => 'Path to the app in the VCS (Version Control System) repository.',
                'default' => '',
                'example' => 'apps/mobile',
            ])
            ->addRule('providerBranches', [
                'type' => self::TYPE_STRING,
                'description' => 'List of branch name patterns that trigger automatic builds. Supports glob wildcards. Empty list builds only the production branch.',
                'default' => [],
                'example' => ['main', 'release/*'],
                'array' => true,
            ])
            ->addRule('providerPaths', [
                'type' => self::TYPE_STRING,
                'description' => 'List of file path patterns that trigger automatic builds. Supports glob wildcards. Empty list builds on all file changes.',
                'default' => [],
                'example' => ['lib/**', '!docs/**'],
                'array' => true,
            ])
            ->addRule('providerTags', [
                'type' => self::TYPE_STRING,
                'description' => 'List of tag name patterns that trigger automatic builds. Supports glob wildcards. Empty list builds no tags.',
                'default' => [],
                'example' => ['v*'],
                'array' => true,
            ])
            ->addRule('providerSilentMode', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether commit checks are suppressed on the repository.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('providerComments', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether a build summary is posted on pull requests. Stored for parity with sites and functions; Distribution posts no pull request comments today.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('providerDeployments', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether pushes that match the branch, path and tag rules are built automatically.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('providerStatuses', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the build status is reported on each commit.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('latestBuildId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the app\'s latest build.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('latestBuildStatus', [
                'type' => self::TYPE_STRING,
                'description' => 'Status of the latest build. Empty before the first build, otherwise one of `' . \implode('`, `', BuildStatus::values()) . '`.',
                'default' => '',
                'example' => BuildStatus::Ready->value,
            ])
            ->addRule('latestBuildCreatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Latest build creation date in ISO 8601 format. Empty before the first build.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    public function getName(): string
    {
        return 'DistributionApp';
    }

    public function getType(): string
    {
        return Response::MODEL_DISTRIBUTION_APP;
    }
}
