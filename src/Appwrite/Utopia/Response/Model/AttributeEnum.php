<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Attribute;

class AttributeEnum extends Attribute
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('elements', [
                'type' => self::TYPE_STRING,
                'description' => 'Array of elements in enumerated type.',
                'default' => null,
                'example' => 'element',
                'array' => true,
                'require' => true,
            ])
            ->addRule('format', [
                'type' => self::TYPE_STRING,
                'description' => 'String format.',
                'default' => APP_DATABASE_ATTRIBUTE_ENUM,
                'example' => APP_DATABASE_ATTRIBUTE_ENUM,
                'array' => false,
                'require' => true,
            ])
            ->addRule('default', [
                'type' => self::TYPE_STRING,
                'description' => 'Default value for attribute when not provided. Cannot be set when attribute is required.',
                'default' => null,
                'example' => 'element',
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
        return 'AttributeEnum';
    }

    /**
     * Get Collection
     *
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_ATTRIBUTE_ENUM;
    }
}