<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Collection extends Model
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
                'description' => 'Collection permissions. [Learn more about permissions](/docs/permissions).',
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
                'description' => 'Whether document-level permissions are enabled. [Learn more about permissions](/docs/permissions).',
                'default' => '',
                'example' => true,
            ])
            ->addRule('attributes', [
                'type' => [
                    Response::MODEL_ATTRIBUTE_BOOLEAN,
                    Response::MODEL_ATTRIBUTE_INTEGER,
                    Response::MODEL_ATTRIBUTE_FLOAT,
                    Response::MODEL_ATTRIBUTE_EMAIL,
                    Response::MODEL_ATTRIBUTE_ENUM,
                    Response::MODEL_ATTRIBUTE_URL,
                    Response::MODEL_ATTRIBUTE_IP,
                    Response::MODEL_ATTRIBUTE_DATETIME,
                    Response::MODEL_ATTRIBUTE_RELATIONSHIP,
                    Response::MODEL_ATTRIBUTE_STRING, // needs to be last, since its condition would dominate any other string attribute
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

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Collection';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_COLLECTION;
    }
}
