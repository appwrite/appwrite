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
                'example' => '1975-12-06 13:30:59',
            ])
            ->addRule('format', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Datetime format.',
                'default' => APP_DATABASE_ATTRIBUTE_DATETIME,
                'example' => APP_DATABASE_ATTRIBUTE_DATETIME,
                'array' => false,
                'require' => true,
            ])
            ->addRule('default', [
                'type' => self::TYPE_STRING,
                'description' => 'Default value for attribute when not provided. Only null is optional',
                'default' => null,
                'example' => '1975-12-06 13:30:59',
                'array' => false,
                'require' => false,
            ])
        ;
    }

    public array $conditions = [
        'type' => self::TYPE_DATETIME,
        'format' => \APP_DATABASE_ATTRIBUTE_DATETIME
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
