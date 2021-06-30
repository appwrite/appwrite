<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Attribute extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$collection', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection ID.',
                'default' => '',
                'example' => '60ccf71b98a2d',
            ])
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute ID.',
                'default' => '',
                'example' => '60ccf71b98a2d',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute type.',
                'default' => '',
                'example' => 'integer',
            ])
            ->addRule('size', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute size.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('required', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is attribute required?',
                'default' => false,
                'example' => false,
            ])
            ->addRule('signed', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is attribute signed?',
                'default' => true,
                'example' => true,
                'required' => false,
            ])
            ->addRule('array', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is attribute an array?',
                'default' => false,
                'example' => false,
                'required' => false
            ])
            ->addRule('filters', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute filters.',
                'default' => [],
                'example' => [],
                'array' => true,
                'required' => false,
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
        return 'Attribute';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_ATTRIBUTE;
    }
}