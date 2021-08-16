<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Attribute;

class AttributeFloat extends Attribute
{
    public function __construct()
    {
        $this
            ->addRule('format', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Float format.',
                'default' => null,
                'example' => \json_encode([
                    'name' => APP_DATABASE_ATTRIBUTE_FLOAT_RANGE,
                    'min' => 1.5,
                    'max' => 2.5,
                ]),
                'array' => false,
                'require' => false,
            ])
            ->addRule('default', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Default value for attribute when not provided. Cannot be set when attribute is required.',
                'default' => null,
                'example' => 2.5,
                'array' => false,
                'require' => false,
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
        return 'AttributeFloat';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_ATTRIBUTE_FLOAT;
    }
}