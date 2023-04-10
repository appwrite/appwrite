<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class AttributeRelationship extends Attribute
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('relatedCollection', [
                'type' => self::TYPE_STRING,
                'description' => 'The ID of the related collection.',
                'default' => null,
                'example' => 'collection',
            ])
            ->addRule('relationType', [
                'type' => self::TYPE_STRING,
                'description' => 'The type of the relationship.',
                'default' => '',
                'example' => 'oneToOne|oneToMany|manyToOne|manyToMany',
            ])
            ->addRule('twoWay', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is the relationship two-way?',
                'default' => false,
                'example' => false,
            ])
            ->addRule('twoWayKey', [
                'type' => self::TYPE_STRING,
                'description' => 'The key of the two-way relationship.',
                'default' => '',
                'example' => 'string',
            ])
            ->addRule('onDelete', [
                'type' => self::TYPE_STRING,
                'description' => 'How deleting the parent document will propagate to child documents.',
                'default' => 'restrict',
                'example' => 'restrict|cascade|setNull',
            ])
            ->addRule('side', [
                'type' => self::TYPE_STRING,
                'description' => 'Whether this is the parent or child side of the relationship',
                'default' => '',
                'example' => 'parent|child',
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
