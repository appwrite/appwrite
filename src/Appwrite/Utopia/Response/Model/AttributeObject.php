<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class AttributeObject extends Attribute
{
    public function __construct()
    {
        parent::__construct();
    }

    public array $conditions = [
        'type' => 'object',
    ];

    public function getName(): string
    {
        return 'AttributeObject';
    }

    public function getType(): string
    {
        return Response::MODEL_ATTRIBUTE_OBJECT;
    }
}


