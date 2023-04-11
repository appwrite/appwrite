<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class AttributeDatetime extends Attribute
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute Key.',
                'default' => '',
                'example' => 'birthDay',
            ])
            ->addRule('type', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Attribute type.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('format', [
                'type' => self::TYPE_DATETIME,
                'description' => 'ISO 8601 format.',
                'default' => APP_DATABASE_ATTRIBUTE_DATETIME,
                'example' => APP_DATABASE_ATTRIBUTE_DATETIME,
                'array' => false,
            ])
            ->addRule('default', [
                'type' => self::TYPE_STRING,
                'description' => 'Default value for attribute when not provided. Only null is optional',
                'default' => null,
                'example' => self::TYPE_DATETIME_EXAMPLE,
                'array' => false,
                'required' => false,
            ]);
    }

    public array $conditions = [
        'type' => self::TYPE_DATETIME,
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'AttributeDatetime';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ATTRIBUTE_DATETIME;
    }
}
