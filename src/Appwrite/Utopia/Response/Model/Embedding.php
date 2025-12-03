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
            ->addRule('dimension', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of dimensions for each embedding vector.',
                'example' => 768
            ])
            ->addRule('embedding', [
                'type' => self::TYPE_FLOAT,
                'array' => true,
                'default' => [],
                'description' => 'Embedding vector values. If an error occurs, this will be an empty array.',
                'example' => [0.01, 0.02, 0.03]
            ])
            ->addRule('error', [
                'type' => self::TYPE_STRING,
                'array' => false,
                'default' => '',
                'description' => 'Error message if embedding generation fails. Empty string if no error.',
                'example' => 'Error message'
            ]);
    }
}
