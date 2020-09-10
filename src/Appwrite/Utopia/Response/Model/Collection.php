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
                'type' => 'string',
                'description' => 'Collection ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$permissions', [
                'type' => Response::MODEL_PERMISSIONS,
                'description' => 'Collection permissions.',
                'example' => new \stdClass,
                'array' => false,
            ])
            ->addRule('name', [
                'type' => 'string',
                'description' => 'Collection name.',
                'example' => 'Movies',
            ])
            ->addRule('dateCreated', [
                'type' => 'integer',
                'description' => 'Collection creation date in Unix timestamp.',
                'example' => 1592981250,
            ])
            ->addRule('dateUpdated', [
                'type' => 'integer',
                'description' => 'Collection creation date in Unix timestamp.',
                'example' => 1592981550,
            ])
            ->addRule('rules', [
                'type' => Response::MODEL_RULE,
                'description' => 'Collection rules.',
                'example' => [],
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