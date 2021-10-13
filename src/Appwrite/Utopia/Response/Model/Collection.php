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
            ->addRule('$permissions', [
                'type' => Response::MODEL_PERMISSIONS,
                'description' => 'Collection permissions.',
                'default' => new \stdClass,
                'example' => new \stdClass,
                'array' => false,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection name.',
                'default' => '',
                'example' => 'Movies',
            ])
            ->addRule('dateCreated', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Collection creation date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('dateUpdated', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Collection creation date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981550,
            ])
            ->addRule('rules', [
                'type' => Response::MODEL_RULE,
                'description' => 'Collection rules.',
                'default' => [],
                'array' => true,
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
