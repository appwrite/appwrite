<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Attribute;

class AttributeIP extends Attribute
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('format', [
                'type' => self::TYPE_STRING,
                'description' => 'String format.',
                'default' => APP_DATABASE_ATTRIBUTE_IP,
                'example' => APP_DATABASE_ATTRIBUTE_IP,
                'array' => false,
                'require' => true,
            ])
            ->addRule('default', [
                'type' => self::TYPE_STRING,
                'description' => 'Default value for attribute when not provided. Cannot be set when attribute is required.',
                'default' => null,
                'example' => '192.0.2.0',
                'array' => false,
                'require' => false,
            ])
        ;
    }

    public array $conditions = [
        'type' => self::TYPE_STRING,
        'format' => \APP_DATABASE_ATTRIBUTE_IP
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName():string
    {
        return 'AttributeIP';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_ATTRIBUTE_IP;
    }
}
