<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class AttributeRelationship extends Attribute
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute Key.',
                'default' => '',
                'example' => 'relationship',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute type.',
                'default' => '',
                'example' => 'relationship',
            ])
            ->addRule('default', [
                'type' => self::TYPE_STRING,
                'description' => 'Default value for attribute when not provided. Only null is optional',
                'default' => null,
                'example' => 'relationship',
            ])
            ->addRule('options', [
                'type' => [
                    'relatedCollection',
                    'relationType',
                    'twoWay',
                    'twoWayKey',
                    'onUpdate',
                    'onDelete',
                    'side',
                ],
                'description' => 'Options attributes.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])
        ;
    }

    public array $conditions = [
        'type' => self::TYPE_RELATIONSHIP,
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'AttributeRelationship';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ATTRIBUTE_RELATIONSHIP;
    }
}
