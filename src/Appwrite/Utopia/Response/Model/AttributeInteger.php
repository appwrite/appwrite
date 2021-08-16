<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Attribute;

class AttributeInteger extends Attribute
{
    public function __construct()
    {
        $this
            ->addRule('format', [
                'type' => self::TYPE_STRING,
                'description' => 'Integer format.',
                'default' => null,
                'example' => \json_encode([
                    'name' => APP_DATABASE_ATTRIBUTE_INT_RANGE,
                    'min' => 0,
                    'max' => 10,
                ]),
                'array' => false,
                'require' => false,
            ])
            ->addRule('default', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Default value for attribute when not provided. Cannot be set when attribute is required.',
                'default' => null,
                'example' => 10,
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
        return 'AttributeInteger';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_ATTRIBUTE_INTEGER;
    }
}