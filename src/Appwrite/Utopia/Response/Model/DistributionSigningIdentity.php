<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Signing\Mode;
use Appwrite\Distribution\Signing\Status;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class DistributionSigningIdentity extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Signing identity ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Signing identity creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Signing identity update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('platform', [
                'type' => self::TYPE_ENUM,
                'description' => 'Platform the identity signs for.',
                'default' => Platform::Android->value,
                'example' => Platform::Android->value,
                'enum' => Platform::values(),
                'enumSDKName' => 'DistributionPlatform',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Signing identity name.',
                'default' => '',
                'example' => 'Release keystore',
            ])
            ->addRule('mode', [
                'type' => self::TYPE_ENUM,
                'description' => 'Whether the identity was imported or is managed through a store connection.',
                'default' => Mode::Imported->value,
                'example' => Mode::Imported->value,
                'enum' => Mode::values(),
                'enumSDKName' => 'DistributionSigningMode',
            ])
            ->addRule('fingerprint', [
                'type' => self::TYPE_STRING,
                'description' => 'SHA-256 fingerprint of the signing certificate.',
                'default' => '',
                'example' => '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
            ])
            ->addRule('teamId', [
                'type' => self::TYPE_STRING,
                'description' => 'Apple team ID. Empty on Android.',
                'default' => '',
                'example' => 'ABCDE12345',
            ])
            ->addRule('identifiers', [
                'type' => self::TYPE_STRING,
                'description' => 'Bundle IDs covered by the identity\'s provisioning profiles.',
                'default' => [],
                'example' => ['com.example.app'],
                'array' => true,
            ])
            ->addRule('connectionId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the store connection that created a managed identity. Empty for imported identities.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('externalId', [
                'type' => self::TYPE_STRING,
                'description' => 'Apple certificate resource ID of a managed identity.',
                'default' => '',
                'example' => '8T8K2Y9J4Q',
            ])
            ->addRule('expiresAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Earliest expiry of the certificate and its profiles, in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'Whether the identity can still sign.',
                'default' => Status::Active->value,
                'example' => Status::Active->value,
                'enum' => Status::values(),
                'enumSDKName' => 'DistributionSigningIdentityStatus',
            ]);
    }

    public function getName(): string
    {
        return 'DistributionSigningIdentity';
    }

    public function getType(): string
    {
        return Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY;
    }

    public function filter(Document $document): Document
    {
        $document->removeAttribute('secret');

        return $document;
    }
}
