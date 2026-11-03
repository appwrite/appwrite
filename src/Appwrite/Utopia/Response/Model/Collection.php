<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

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
            ->addRule('description', [
                'type' => self::TYPE_STRING,
                'description' => 'Optional description for the collection.',
                'default' => '',
                'example' => 'Movies available in the catalog.',
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
                'default' => false,
                'example' => true,
            ])
            ->addRule('attributes', [
                'type' => [
                    Response::MODEL_ATTRIBUTE_BOOLEAN,
                    // BigInt must come before Integer: response model dispatch is "first match wins",
                    // and Integer matches all int types (including bigint), while BigInt is more specific (size=8).
                    Response::MODEL_ATTRIBUTE_BIGINT,
                    Response::MODEL_ATTRIBUTE_INTEGER,
                    Response::MODEL_ATTRIBUTE_FLOAT,
                    Response::MODEL_ATTRIBUTE_EMAIL,
                    Response::MODEL_ATTRIBUTE_ENUM,
                    Response::MODEL_ATTRIBUTE_URL,
                    Response::MODEL_ATTRIBUTE_IP,
                    Response::MODEL_ATTRIBUTE_DATETIME,
                    Response::MODEL_ATTRIBUTE_RELATIONSHIP,
                    Response::MODEL_ATTRIBUTE_POINT,
                    Response::MODEL_ATTRIBUTE_LINE,
                    Response::MODEL_ATTRIBUTE_POLYGON,
                    Response::MODEL_ATTRIBUTE_VARCHAR,
                    Response::MODEL_ATTRIBUTE_TEXT,
                    Response::MODEL_ATTRIBUTE_MEDIUMTEXT,
                    Response::MODEL_ATTRIBUTE_LONGTEXT,
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
            ->addRule('bytesMax', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Maximum document size in bytes. Returns 0 when no limit applies.',
                'default' => 0,
                'example' => 65535,
            ])
            ->addRule('bytesUsed', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Currently used document size in bytes based on defined attributes.',
                'default' => 0,
                'example' => 1500,
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    /**
     * Report the permissions as the caller wrote them.
     *
     * The row stores them with every column dropped, because that is the form the read gate
     * matches and a column left there would hide the table from the role it was granted to.
     * The verbatim list lives beside it, and is what a caller asked for and should read back.
     */
    public function filter(Document $document): Document
    {
        $columnPermissions = $document->getAttribute('columnPermissions');

        if (!empty($columnPermissions) && \is_array($columnPermissions)) {
            $document->setAttribute('$permissions', $columnPermissions);
        }

        // columnPermissions itself needs no removing: output() builds the response from the
        // model's rules, so an attribute no rule declares never reaches a response, a webhook
        // or a realtime message.
        return $document;
    }

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
