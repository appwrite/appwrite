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
                'example' => '',
            ])
            ->addRule('$read', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection read permissions.',
                'default' => '',
                'example' => '',
                'array' => true
            ])
            ->addRule('$write', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection write permissions.',
                'default' => '',
                'example' => '',
                'array' => true
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection name.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('attributes', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection attributes.',
                'default' => '',
                'example' => '',
                'array' => true
            ])
            ->addRule('indexes', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection indexes.',
                'default' => '',
                'example' => '',
                'array' => true
            ])
            ->addRule('attributesInQueue', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection attributes in creation queue.',
                'default' => '',
                'example' => '',
                'array' => true
            ])
            ->addRule('indexesInQueue', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection indexes in creation queue.',
                'default' => '',
                'example' => '',
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