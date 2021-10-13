<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class AttributeList extends Model
{
    public function __construct()
    {
        $this
            ->addRule('sum', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total sum of items in the list.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule('attributes', [
                'type' => [
                    Response::MODEL_ATTRIBUTE_BOOLEAN,
                    Response::MODEL_ATTRIBUTE_INTEGER,
                    Response::MODEL_ATTRIBUTE_FLOAT,
                    Response::MODEL_ATTRIBUTE_EMAIL,
                    Response::MODEL_ATTRIBUTE_ENUM,
                    Response::MODEL_ATTRIBUTE_URL,
                    Response::MODEL_ATTRIBUTE_IP,
                    Response::MODEL_ATTRIBUTE_STRING // needs to be last, since its condition would dominate any other string attribute
                ],
                'description' => 'List of attributes.',
                'default' => [],
                'array' => true
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
        return 'Attributes List';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_ATTRIBUTE_LIST;
    }
}
