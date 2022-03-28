<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Attribute;

class AttributeTimestamp extends Attribute
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute Key.',
                'default' => '',
                'example' => 'count',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute type.',
                'default' => '',
                'example' => 'integer',
            ])
            ->addRule('format', [
                'type' => self::TYPE_STRING,
                'description' => 'Integer format.',
                'default' => APP_DATABASE_ATTRIBUTE_TIMESTAMP,
                'example' => APP_DATABASE_ATTRIBUTE_TIMESTAMP,
                'array' => false,
                'require' => true,
            ])
            ->addRule('min', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Minimum value to enforce for new documents.',
                'default' => null,
                'example' => 1648295746,
                'array' => false,
                'require' => false,
            ])
            ->addRule('max', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Maximum value to enforce for new documents.',
                'default' => null,
                'example' => 1648295746,
                'array' => false,
                'require' => false,
            ])
            ->addRule('default', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Default value for attribute when not provided. Cannot be set when attribute is required.',
                'default' => null,
                'example' => 1648295746,
                'array' => false,
                'require' => false,
            ])
        ;
    }

    public array $conditions = [
        'type' => self::TYPE_INTEGER,
    ];

    /**
     * Get Name * 
     * @return string
     */
    public function getName():string
    {
        return 'AttributeTimestamp';
    }

    /**
     * Get Type
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_ATTRIBUTE_TIMESTAMP;
    }
}