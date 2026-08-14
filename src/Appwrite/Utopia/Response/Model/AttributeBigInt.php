<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Query\Schema\ColumnType;

class AttributeBigInt extends Attribute
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute Key.',
                'default' => '',
                'example' => 'count',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Attribute type.',
                'default' => '',
                'example' => 'bigint',
            ])
            ->addRule('min', [
                'type' => self::TYPE_INTEGER,
                'format' => 'int64',
                'description' => 'Minimum value to enforce for new documents.',
                'default' => null,
                'required' => false,
                'example' => 1,
            ])
            ->addRule('max', [
                'type' => self::TYPE_INTEGER,
                'format' => 'int64',
                'description' => 'Maximum value to enforce for new documents.',
                'default' => null,
                'required' => false,
                'example' => 10,
            ])
            ->addRule('default', [
                'type' => self::TYPE_INTEGER,
                'format' => 'int64',
                'description' => 'Default value for attribute when not provided. Cannot be set when attribute is required.',
                'default' => null,
                'required' => false,
                'example' => 10,
            ])
        ;
    }

    public array $conditions = [
        'type' => ['bigint', 'biginteger'],
    ];

    public function filter(Document $document): Document
    {
        $type = $document->getAttribute('type');
        if ($type instanceof ColumnType) {
            $type = $type->value;
        }
        if ($type === ColumnType::BigInteger->value) {
            $document->setAttribute('type', 'bigint');
        }

        return $document;
    }

    public function getName(): string
    {
        return 'AttributeBigInt';
    }

    public function getType(): string
    {
        return Response::MODEL_ATTRIBUTE_BIGINT;
    }
}
