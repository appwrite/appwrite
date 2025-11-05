<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class VectorDBCollection extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Collection creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Collection update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$permissions', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).',
                'default' => '',
                'example' => ['read("any")'],
                'array' => true
            ])
            ->addRule('databaseId', [
                'type' => self::TYPE_STRING,
                'description' => 'Database ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection name.',
                'default' => '',
                'example' => 'My Collection',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Collection enabled. Can be \'enabled\' or \'disabled\'. When disabled, the collection is inaccessible to users, but remains accessible to Server SDKs using API keys.',
                'default' => true,
                'example' => false,
            ])
            ->addRule('documentSecurity', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether document-level permissions are enabled. [Learn more about permissions](https://appwrite.io/docs/permissions).',
                'default' => '',
                'example' => true,
            ])
            ->addRule('dimensions', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Embedding dimensions.',
                'default' => 0,
                'example' => 1536,
            ])
            ->addRule('search', [
                'type' => self::TYPE_STRING,
                'description' => 'Search text.',
                'default' => '',
                'example' => 'collectionId name',
            ])
            ->addRule('attributes', [
                'type' => [
                    Response::MODEL_ATTRIBUTE_OBJECT,
                    Response::MODEL_ATTRIBUTE_VECTOR,
                ],
                'description' => 'Collection attributes.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])
            ->addRule('indexes', [
                'type' => Response::MODEL_INDEX,
                'description' => 'Collection indexes.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
        ;
    }

    public function getName(): string
    {
        return 'VectorDB Collection';
    }

    public function getType(): string
    {
        return Response::MODEL_VECTORDB_COLLECTION;
    }
}
