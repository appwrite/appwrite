<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Attribute;

class AttributeEmail extends Attribute
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute Key.',
                'default' => '',
                'example' => 'userEmail',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute type.',
                'default' => '',
                'example' => 'string',
            ])
            ->addRule('format', [
                'type' => self::TYPE_STRING,
                'description' => 'String format.',
                'default' => 'email',
                'example' => 'email',
            ])
            ->addRule('default', [
                'type' => self::TYPE_STRING,
                'description' => 'Default value for attribute when not provided. Cannot be set when attribute is required.',
                'default' => null,
                'example' => 'default@example.com',
                'required' => false,
            ])
        ;
    }

    public array $conditions = [
        'type' => self::TYPE_STRING,
        'format' => \APP_DATABASE_ATTRIBUTE_EMAIL
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'AttributeEmail';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ATTRIBUTE_EMAIL;
    }
}
