<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class AttributeFloat extends Attribute
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute Key.',
                'default' => '',
                'example' => 'percentageCompleted',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute type.',
                'default' => '',
                'example' => 'double',
            ])
            ->addRule('min', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Minimum value to enforce for new documents.',
                'default' => null,
                'required' => false,
                'example' => 1.5,
            ])
            ->addRule('max', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Maximum value to enforce for new documents.',
                'default' => null,
                'required' => false,
                'example' => 10.5,
            ])
            ->addRule('default', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Default value for attribute when not provided. Cannot be set when attribute is required.',
                'default' => null,
                'required' => false,
                'example' => 2.5,
            ])
        ;
    }

    public array $conditions = [
        'type' => self::TYPE_FLOAT,
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'AttributeFloat';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ATTRIBUTE_FLOAT;
    }
}
