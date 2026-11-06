<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Database\Attribute as AttributeDefinition;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Query\Schema\ColumnType;

class ColumnBigInt extends Column
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Column Key.',
                'default' => '',
                'example' => 'count',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Column type.',
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
                'description' => 'Default value for column when not provided. Cannot be set when column is required.',
                'default' => null,
                'required' => false,
                'example' => 10,
            ])
        ;
    }

    public array $conditions = [
        'type' => 'bigint'
    ];

    public function matches(Document $document): bool
    {
        return $this->isBigInteger($document->getAttribute('type'));
    }

    public function filter(Document $document): Document
    {
        $type = $document->getAttribute('type');

        if ($this->isBigInteger($type)) {
            $document->setAttribute('type', AttributeDefinition::storedType($type));
        }

        return $document;
    }

    private function isBigInteger(mixed $type): bool
    {
        return ($type instanceof ColumnType || \is_string($type)) && AttributeDefinition::sameType($type, ColumnType::BigInteger);
    }

    public function getName(): string
    {
        return 'ColumnBigInt';
    }

    public function getType(): string
    {
        return Response::MODEL_COLUMN_BIGINT;
    }
}
