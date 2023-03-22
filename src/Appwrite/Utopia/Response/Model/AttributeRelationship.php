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
                'example' => '',
            ])
            ->addRule('relatedCollection', [
                'type' => self::TYPE_STRING,
                'description' => 'The Id of the related collection',
                'default' => null,
                'example' => 'collection',
            ])
            ->addRule('relationType', [
                'type' => self::TYPE_STRING,
                'description' => 'The type of the relationship ',
                'default' => null,
                'example' => 'oneToOne|oneToMany|manyToOne|manyToMany',
            ])
            ->addRule('twoWay', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is the relationship going two ways?',
                'default' => null,
                'example' => 'relationship',
            ])
            ->addRule('twoWayKey', [
                'type' => self::TYPE_STRING,
                'description' => 'The key of the 2 way relationship',
                'default' => null,
                'example' => 'string',
            ])
//            ->addRule('onUpdate', [
//                'type' => self::TYPE_STRING,
//                'description' => 'How to set related documents after parent document is updated',
//                'default' => null,
//                'example' => 'restrict|cascade|setNull',
//            ])
            ->addRule('onDelete', [
                'type' => self::TYPE_STRING,
                'description' => 'How to set related documents after parent document is deleted',
                'default' => null,
                'example' => 'restrict|cascade|setNull',
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
