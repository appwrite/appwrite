<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class AttributeText extends Attribute
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('default', [
                'type' => self::TYPE_STRING,
                'description' => 'Default value for attribute when not provided. Cannot be set when attribute is required.',
                'default' => null,
                'required' => false,
                'example' => 'default',
            ])
            ->addRule('encrypt', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Defines whether this attribute is encrypted or not.',
                'default' => false,
                'required' => false,
                'example' => false,
            ])
        ;
    }

    public array $conditions = [
        'type' => 'text',
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'AttributeText';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ATTRIBUTE_TEXT;
    }
}
