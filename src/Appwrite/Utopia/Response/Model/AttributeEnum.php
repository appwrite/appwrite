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
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute Key.',
                'default' => '',
                'example' => 'status',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute type.',
                'default' => '',
                'example' => 'string',
            ])
            ->addRule('elements', [
                'type' => self::TYPE_STRING,
                'description' => 'Array of elements in enumerated type.',
                'default' => null,
                'example' => 'element',
                'array' => true,
            ])
            ->addRule('format', [
                'type' => self::TYPE_STRING,
                'description' => 'String format.',
                'default' => 'enum',
                'example' => 'enum',
            ])
            ->addRule('default', [
                'type' => self::TYPE_STRING,
                'description' => 'Default value for attribute when not provided. Cannot be set when attribute is required.',
                'default' => null,
                'example' => 'element',
                'required' => false,
            ])
        ;
    }

    public array $conditions = [
        'type' => self::TYPE_STRING,
        'format' => \APP_DATABASE_ATTRIBUTE_ENUM
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'AttributeEnum';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ATTRIBUTE_ENUM;
    }
}
