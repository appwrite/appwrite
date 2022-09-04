<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Attribute;

class AttributeInteger extends Attribute
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
            ->addRule('min', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Minimum value to enforce for new documents.',
                'default' => null,
                'example' => 1,
                'required' => false,
            ])
            ->addRule('max', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Maximum value to enforce for new documents.',
                'default' => null,
                'example' => 10,
                'required' => false,
            ])
            ->addRule('default', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Default value for attribute when not provided. Cannot be set when attribute is required.',
                'default' => null,
                'example' => 10,
                'required' => false,
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
    public function getName(): string
    {
        return 'AttributeInteger';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ATTRIBUTE_INTEGER;
    }
}
