<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class AttributePolygon extends Attribute
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('default', [
                'type' => self::TYPE_ARRAY,
                'description' => 'Default value for attribute when not provided. Cannot be set when attribute is required.',
                'default' => null,
                'required' => false,
                'example' => [[[0, 0], [0, 10]], [[10, 10], [0, 0]]]
            ])
        ;
    }

    public array $conditions = [
        'type' => 'polygon',
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'AttributePolygon';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ATTRIBUTE_POLYGON;
    }
}
