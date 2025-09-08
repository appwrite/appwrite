<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class AttributeLine extends Attribute
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
                'example' => [[0, 0], [1, 1]]
            ])
        ;
    }

    public array $conditions = [
        'type' => 'linestring',
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'AttributeLine';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ATTRIBUTE_LINE;
    }
}
