<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\AttributeString;

class AttributeEmail extends AttributeString
{
    public function __construct()
    {
        $this
            ->addRule('format', [
                'type' => self::TYPE_STRING,
                'description' => 'String format.',
                'default' => \json_encode(['name'=>APP_DATABASE_ATTRIBUTE_EMAIL]),
                'example' => \json_encode(['name'=>APP_DATABASE_ATTRIBUTE_EMAIL]),
                'array' => false,
                'require' => true,
            ])
            ->addRule('default', [
                'type' => self::TYPE_STRING,
                'description' => 'Default value for attribute when not provided. Cannot be set when attribute is required.',
                'default' => null,
                'example' => 'default@example.com',
                'array' => false,
                'require' => false,
            ])
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'AttributeEmail';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_ATTRIBUTE_EMAIL;
    }
}