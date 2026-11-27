<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Distribution\Platform;
use Appwrite\Distribution\VersionStrategy;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class DistributionTarget extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Target ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Target creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Target update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('appId', [
                'type' => self::TYPE_STRING,
                'description' => 'App ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('platform', [
                'type' => self::TYPE_ENUM,
                'description' => 'Platform the target builds for.',
                'default' => Platform::Android->value,
                'example' => Platform::Android->value,
                'enum' => Platform::values(),
                'enumSDKName' => 'DistributionPlatform',
            ])
            ->addRule('identifier', [
                'type' => self::TYPE_STRING,
                'description' => 'Bundle ID on iOS or package name on Android.',
                'default' => '',
                'example' => 'com.example.app',
            ])
            ->addRule('connectionId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the store connection used for submissions. Its provider matches the target platform.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('signingIdentityId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the signing identity used to sign builds.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('storeAppId', [
                'type' => self::TYPE_STRING,
                'description' => 'Apple numeric app ID, resolved by the readiness check. Empty on Android.',
                'default' => '',
                'example' => '1234567890',
            ])
            ->addRule('track', [
                'type' => self::TYPE_STRING,
                'description' => 'Default store track for automatic submissions.',
                'default' => '',
                'example' => 'internal',
            ])
            ->addRule('automatic', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether successful Git builds are submitted to the default track automatically.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('versionStrategy', [
                'type' => self::TYPE_ENUM,
                'description' => 'How build numbers are allocated.',
                'default' => VersionStrategy::Automatic->value,
                'example' => VersionStrategy::Automatic->value,
                'enum' => VersionStrategy::values(),
                'enumSDKName' => 'DistributionVersionStrategy',
            ])
            ->addRule('versionCode', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Last allocated build number.',
                'default' => 0,
                'example' => 42,
            ])
            ->addRule('installCommand', [
                'type' => self::TYPE_STRING,
                'description' => 'Command that installs dependencies. Empty uses the framework default.',
                'default' => '',
                'example' => 'flutter pub get',
            ])
            ->addRule('buildCommand', [
                'type' => self::TYPE_STRING,
                'description' => 'Command that builds the app. Empty uses the framework default.',
                'default' => '',
                'example' => 'flutter build appbundle --release',
            ])
            ->addRule('outputPath', [
                'type' => self::TYPE_STRING,
                'description' => 'Path of the built binary. Empty uses the framework default.',
                'default' => '',
                'example' => 'build/app/outputs/bundle/release/app-release.aab',
            ])
            ->addRule('configuration', [
                'type' => self::TYPE_JSON,
                'description' => 'Platform build settings, such as scheme, workspace, project, configuration, flavor, Gradle task and toolchain versions.',
                'default' => [],
                'example' => ['flavor' => 'production'],
            ])
            ->addRule('specification', [
                'type' => self::TYPE_STRING,
                'description' => 'Build specification key. Empty uses the instance default.',
                'default' => '',
                'example' => 'd-4vcpu-8gb',
            ])
            ->addRule('timeout', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Build timeout in seconds. 0 uses the instance or plan default.',
                'default' => 0,
                'example' => 3600,
            ]);
    }

    public function getName(): string
    {
        return 'DistributionTarget';
    }

    public function getType(): string
    {
        return Response::MODEL_DISTRIBUTION_TARGET;
    }
}
