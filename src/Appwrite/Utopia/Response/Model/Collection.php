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
            ->addRule('$read', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection read permissions.',
                'default' => '',
                'example' => 'role:all',
                'array' => true
            ])
            ->addRule('$write', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection write permissions.',
                'default' => '',
                'example' => 'user:608f9da25e7e1',
                'array' => true
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection name.',
                'default' => '',
                'example' => 'My Collection',
            ])
            ->addRule('permission', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection permission model. Possible values: `document` or `collection`',
                'default' => '',
                'example' => 'document',
            ])
            ->addRule('attributes', [
                'type' => [
                    Response::MODEL_ATTRIBUTE_BOOLEAN,
                    Response::MODEL_ATTRIBUTE_INTEGER,
                    Response::MODEL_ATTRIBUTE_FLOAT,
                    Response::MODEL_ATTRIBUTE_EMAIL,
                    Response::MODEL_ATTRIBUTE_URL,
                    Response::MODEL_ATTRIBUTE_IP,
                    Response::MODEL_ATTRIBUTE_STRING, // needs to be last, since its condition would dominate any other string attribute
                ],
                'description' => 'Collection attributes.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true,
            ])
            ->addRule('indexes', [
                'type' => Response::MODEL_INDEX,
                'description' => 'Collection indexes.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName():string
    {
        return 'Collection';
    }

    /**
     * Get Collection
     *
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_COLLECTION;
    }
}
