<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Distribution\Artifact\Source;
use Appwrite\Distribution\Artifact\Status;
use Appwrite\Distribution\Artifact\Type;
use Appwrite\Distribution\Platform;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class DistributionArtifact extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Artifact ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Artifact creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Artifact update date in ISO 8601 format.',
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
                'description' => 'Target ID. Empty until an uploaded binary is inspected.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('platform', [
                'type' => self::TYPE_STRING,
                'description' => 'Platform of the binary. Empty until an uploaded binary is inspected, otherwise one of `' . \implode('`, `', Platform::values()) . '`.',
                'default' => '',
                'example' => Platform::Android->value,
            ])
            ->addRule('type', [
                'type' => self::TYPE_ENUM,
                'description' => 'Binary format.',
                'default' => Type::Aab->value,
                'example' => Type::Aab->value,
                'enum' => Type::values(),
                'enumSDKName' => 'DistributionArtifactType',
            ])
            ->addRule('source', [
                'type' => self::TYPE_ENUM,
                'description' => 'Whether the artifact was built or uploaded.',
                'default' => Source::Upload->value,
                'example' => Source::Build->value,
                'enum' => Source::values(),
                'enumSDKName' => 'DistributionArtifactSource',
            ])
            ->addRule('buildId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the build that produced the artifact. Empty for uploads.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'Artifact status.',
                'default' => Status::Uploading->value,
                'example' => Status::Ready->value,
                'enum' => Status::values(),
                'enumSDKName' => 'DistributionArtifactStatus',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Original file name.',
                'default' => '',
                'example' => 'app-release.aab',
            ])
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Binary size in bytes.',
                'default' => 0,
                'example' => 52428800,
                'format' => 'int64',
            ])
            ->addRule('chunksTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of chunks of the upload.',
                'default' => 0,
                'example' => 10,
            ])
            ->addRule('chunksUploaded', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of chunks uploaded so far.',
                'default' => 0,
                'example' => 10,
            ])
            ->addRule('digest', [
                'type' => self::TYPE_STRING,
                'description' => 'SHA-256 digest of the binary.',
                'default' => '',
                'example' => '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
            ])
            ->addRule('identifier', [
                'type' => self::TYPE_STRING,
                'description' => 'Bundle ID or package name read from the binary.',
                'default' => '',
                'example' => 'com.example.app',
            ])
            ->addRule('versionName', [
                'type' => self::TYPE_STRING,
                'description' => 'Marketing version read from the binary.',
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
                'description' => 'The store\'s native build identifier: iOS `CFBundleVersion`, or the Android version code as text.',
                'default' => '',
                'example' => '42',
            ])
            ->addRule('signed', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the binary is signed.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('fingerprint', [
                'type' => self::TYPE_STRING,
                'description' => 'SHA-256 fingerprint of the signer certificate.',
                'default' => '',
                'example' => '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
            ])
            ->addRule('manifest', [
                'type' => self::TYPE_JSON,
                'description' => 'Inspection output: minimum and target SDK, entitlements, profile expiry and embedded bundle IDs.',
                'default' => [],
                'example' => ['minSdk' => 24, 'targetSdk' => 35],
            ])
            ->addRule('errorCode', [
                'type' => self::TYPE_STRING,
                'description' => 'Machine-readable failure code. Empty unless the artifact is invalid.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('errorMessage', [
                'type' => self::TYPE_STRING,
                'description' => 'Failure message. Empty unless the artifact is invalid.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('expiresAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the artifact is deleted under the retention policy, in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    public function getName(): string
    {
        return 'DistributionArtifact';
    }

    public function getType(): string
    {
        return Response::MODEL_DISTRIBUTION_ARTIFACT;
    }
}
