<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Distribution\Release\Status;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class DistributionRelease extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Release ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Release creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Release update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('appId', [
                'type' => self::TYPE_STRING,
                'description' => 'App ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('version', [
                'type' => self::TYPE_STRING,
                'description' => 'Marketing version.',
                'default' => '',
                'example' => '1.2.0',
            ])
            ->addRule('notes', [
                'type' => self::TYPE_JSON,
                'description' => 'Release notes keyed by locale.',
                'default' => [],
                'example' => ['en-US' => 'Bug fixes and improvements.'],
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'Release status, derived from its submissions.',
                'default' => Status::Draft->value,
                'example' => Status::Active->value,
                'enum' => Status::values(),
                'enumSDKName' => 'DistributionReleaseStatus',
            ]);
    }

    public function getName(): string
    {
        return 'DistributionRelease';
    }

    public function getType(): string
    {
        return Response::MODEL_DISTRIBUTION_RELEASE;
    }
}
