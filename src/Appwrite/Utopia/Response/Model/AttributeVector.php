<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class AttributeVector extends Attribute
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Vector dimensions.',
                'default' => 0,
                'example' => 1536,
            ]);
    }

    public array $conditions = [
        'type' => 'vector',
    ];

    public function getName(): string
    {
        return 'AttributeVector';
    }

    public function getType(): string
    {
        return Response::MODEL_ATTRIBUTE_VECTOR;
    }
}


