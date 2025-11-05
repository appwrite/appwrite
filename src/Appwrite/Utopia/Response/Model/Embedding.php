<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model;

class Embedding extends Model
{
    public function getName(): string
    {
        return 'Embedding';
    }

    public function getType(): string
    {
        return 'embedding';
    }

    public function __construct()
    {
        $this
            ->addRule('model', [
                'type' => self::TYPE_STRING,
                'description' => 'Embedding model used to generate embeddings.',
                'example' => 'embeddinggemma'
            ])
            ->addRule('dimensions', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of dimensions for each embedding vector.',
                'example' => 768
            ])
            ->addRule('embeddings', [
                'type' => self::TYPE_FLOAT,
                'array' => true,
                'description' => 'Embedding vector values.',
                'example' => [0.01, 0.02, 0.03]
            ]);
    }
}
