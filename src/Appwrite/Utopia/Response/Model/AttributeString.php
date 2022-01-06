<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Attribute;

class AttributeString extends Attribute
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('size', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute size.',
                'default' => 0,
                'example' => 128,
            ])
            ->addRule('default', [
                'type' => self::TYPE_STRING,
                'description' => 'Default value for attribute when not provided. Cannot be set when attribute is required.',
                'default' => null,
                'example' => 'default',
                'array' => false,
                'require' => false,
            ])
        ;
    }

    public array $conditions = [
        'type' => self::TYPE_STRING,
    ];

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'AttributeString';
    }

    /**
     * Get Type
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_ATTRIBUTE_STRING;
    }
}