<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Distribution\Connection\Status;
use Appwrite\Distribution\Provider;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class DistributionConnection extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Connection ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Connection creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Connection update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('provider', [
                'type' => self::TYPE_ENUM,
                'description' => 'Store the connection signs in to.',
                'default' => Provider::Apple->value,
                'example' => Provider::Apple->value,
                'enum' => Provider::values(),
                'enumSDKName' => 'DistributionProvider',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Connection name.',
                'default' => '',
                'example' => 'App Store Connect',
            ])
            ->addRule('account', [
                'type' => self::TYPE_JSON,
                'description' => 'Non-secret facts about the store account, such as the Apple team name and key ID suffix, or the Google service account email.',
                'default' => [],
                'example' => ['teamName' => 'Example Inc.', 'keyId' => '4XYZ'],
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'Whether the credentials passed their last validation.',
                'default' => Status::Active->value,
                'example' => Status::Active->value,
                'enum' => Status::values(),
                'enumSDKName' => 'DistributionConnectionStatus',
            ])
            ->addRule('error', [
                'type' => self::TYPE_STRING,
                'description' => 'Last validation failure. Empty when the connection is active.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('checkedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Date the credentials were last validated, in ISO 8601 format.',
                'default' => null,
                'required' => false,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    public function getName(): string
    {
        return 'DistributionConnection';
    }

    public function getType(): string
    {
        return Response::MODEL_DISTRIBUTION_CONNECTION;
    }

    public function filter(Document $document): Document
    {
        $document->removeAttribute('credentials');

        return $document;
    }
}
