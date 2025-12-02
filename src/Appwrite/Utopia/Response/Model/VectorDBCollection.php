<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class VectorDBCollection extends Collection
{
    public function __construct()
    {
        parent::__construct();
        $this
            ->addRule('dimension', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Embedding dimension.',
                'default' => 0,
                'example' => 1536,
            ])
            ->addRule('attributes', [
                'type' => [
                    Response::MODEL_ATTRIBUTE_OBJECT,
                    Response::MODEL_ATTRIBUTE_VECTOR,
                ],
                'description' => 'Collection attributes.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])
        ;
    }

    public function getName(): string
    {
        return 'VectorDB Collection';
    }

    public function getType(): string
    {
        return Response::MODEL_VECTORDB_COLLECTION;
    }
}
