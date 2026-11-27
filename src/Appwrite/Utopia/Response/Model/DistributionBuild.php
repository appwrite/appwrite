<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Distribution\Build\Stage;
use Appwrite\Distribution\Build\Status;
use Appwrite\Distribution\Build\Trigger;
use Appwrite\Distribution\Platform;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class DistributionBuild extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Build ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Build creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Build update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('appId', [
                'type' => self::TYPE_STRING,
                'description' => 'App ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('targetId', [
                'type' => self::TYPE_STRING,
                'description' => 'Target ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('platform', [
                'type' => self::TYPE_ENUM,
                'description' => 'Platform the build is for.',
                'default' => Platform::Android->value,
                'example' => Platform::Android->value,
                'enum' => Platform::values(),
                'enumSDKName' => 'DistributionPlatform',
            ])
            ->addRule('type', [
                'type' => self::TYPE_ENUM,
                'description' => 'What started the build.',
                'default' => Trigger::Manual->value,
                'example' => Trigger::Vcs->value,
                'enum' => Trigger::values(),
                'enumSDKName' => 'DistributionBuildTrigger',
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'Build status.',
                'default' => Status::Waiting->value,
                'example' => Status::Ready->value,
                'enum' => Status::values(),
                'enumSDKName' => 'DistributionBuildStatus',
            ])
            ->addRule('stage', [
                'type' => self::TYPE_STRING,
                'description' => 'Stage where a failure happened. Empty until one does, otherwise one of `' . \implode('`, `', Stage::values()) . '`.',
                'default' => '',
                'example' => Stage::Build->value,
            ])
            ->addRule('attempt', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Dispatch attempt, starting at 1.',
                'default' => 1,
                'example' => 1,
            ])
            ->addRule('providerCommitHash', [
                'type' => self::TYPE_STRING,
                'description' => 'Hash of the built commit.',
                'default' => '',
                'example' => '7c3f25d',
            ])
            ->addRule('providerBranch', [
                'type' => self::TYPE_STRING,
                'description' => 'Branch of the built commit.',
                'default' => '',
                'example' => 'main',
            ])
            ->addRule('providerTag', [
                'type' => self::TYPE_STRING,
                'description' => 'Tag of the built commit.',
                'default' => '',
                'example' => 'v1.2.0',
            ])
            ->addRule('providerCommitMessage', [
                'type' => self::TYPE_STRING,
                'description' => 'Commit message, truncated to 255 characters.',
                'default' => '',
                'example' => 'Update the onboarding flow',
            ])
            ->addRule('providerCommitAuthor', [
                'type' => self::TYPE_STRING,
                'description' => 'Commit author name.',
                'default' => '',
                'example' => 'Jane Doe',
            ])
            ->addRule('providerCommitAuthorUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'Commit author profile URL.',
                'default' => '',
                'example' => 'https://github.com/jane',
            ])
            ->addRule('providerCommitUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'Commit URL.',
                'default' => '',
                'example' => 'https://github.com/example/app/commit/7c3f25d',
            ])
            ->addRule('providerBranchUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'Branch URL.',
                'default' => '',
                'example' => 'https://github.com/example/app/tree/main',
            ])
            ->addRule('providerRepositoryName', [
                'type' => self::TYPE_STRING,
                'description' => 'Repository name.',
                'default' => '',
                'example' => 'app',
            ])
            ->addRule('providerRepositoryOwner', [
                'type' => self::TYPE_STRING,
                'description' => 'Repository owner.',
                'default' => '',
                'example' => 'example',
            ])
            ->addRule('providerRepositoryUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'Repository URL.',
                'default' => '',
                'example' => 'https://github.com/example/app',
            ])
            ->addRule('providerRootDirectory', [
                'type' => self::TYPE_STRING,
                'description' => 'Path to the app in the repository at build time.',
                'default' => '',
                'example' => 'apps/mobile',
            ])
            ->addRule('versionName', [
                'type' => self::TYPE_STRING,
                'description' => 'Marketing version of the built binary.',
                'default' => '',
                'example' => '1.2.0',
            ])
            ->addRule('versionCode', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Build number as an integer. 0 when the iOS build number is not a plain integer; `buildNumber` then carries it.',
                'default' => 0,
                'example' => 42,
            ])
            ->addRule('buildNumber', [
                'type' => self::TYPE_STRING,
                'description' => 'The store\'s native build identifier.',
                'default' => '',
                'example' => '42',
            ])
            ->addRule('toolchain', [
                'type' => self::TYPE_JSON,
                'description' => 'Image tag and tool versions the build ran with.',
                'default' => [],
                'example' => ['image' => 'appwrite/distribution-android:1.0.0', 'flutter' => '3.24.0'],
            ])
            ->addRule('specification', [
                'type' => self::TYPE_STRING,
                'description' => 'Build specification at dispatch.',
                'default' => '',
                'example' => 'd-4vcpu-8gb',
            ])
            ->addRule('cpus', [
                'type' => self::TYPE_INTEGER,
                'description' => 'CPU cores at dispatch.',
                'default' => 0,
                'example' => 4,
            ])
            ->addRule('memory', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Memory in megabytes at dispatch.',
                'default' => 0,
                'example' => 8192,
            ])
            ->addRule('timeout', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Timeout in seconds at dispatch.',
                'default' => 0,
                'example' => 3600,
            ])
            ->addRule('artifactId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the artifact the build produced. Empty until the build is ready.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('releaseId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the release the build was created for. Empty for builds outside a release.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('logsSize', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Size of the stored logs in bytes.',
                'default' => 0,
                'example' => 128000,
            ])
            ->addRule('dispatchedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the build was dispatched, in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('startedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the build started, in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('endedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the build ended, in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('duration', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Build runtime in seconds.',
                'default' => 0,
                'example' => 540,
            ])
            ->addRule('errorCode', [
                'type' => self::TYPE_STRING,
                'description' => 'Machine-readable failure code. Empty unless the build failed.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('errorMessage', [
                'type' => self::TYPE_STRING,
                'description' => 'Failure message. Empty unless the build failed.',
                'default' => '',
                'example' => '',
            ]);
    }

    public function getName(): string
    {
        return 'DistributionBuild';
    }

    public function getType(): string
    {
        return Response::MODEL_DISTRIBUTION_BUILD;
    }
}
