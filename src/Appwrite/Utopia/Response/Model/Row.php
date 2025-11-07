<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Utopia\Database\Document as DatabaseDocument;

class Row extends Any
{
    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Row';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ROW;
    }

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Row ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$sequence', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Row automatically incrementing ID.',
                'default' => 0,
                'example' => 1,
                'readOnly' => true,
            ])
            ->addRule('$tableId', [
                'type' => self::TYPE_STRING,
                'description' => 'Table ID.',
                'default' => '',
                'example' => '5e5ea5c15117e',
                'readOnly' => true,
            ])
            ->addRule('$databaseId', [
                'type' => self::TYPE_STRING,
                'description' => 'Database ID.',
                'default' => '',
                'example' => '5e5ea5c15117e',
                'readOnly' => true,
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Row creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Row update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$permissions', [
                'type' => self::TYPE_STRING,
                'description' => 'Row permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).',
                'default' => '',
                'example' => ['read("any")'],
                'array' => true,
            ]);
    }

    public function filter(DatabaseDocument $document): DatabaseDocument
    {
        $document->removeAttribute('$collection');
        $document->removeAttribute('$tenant');
        $document->setAttribute('$sequence', (int)$document->getAttribute('$sequence', 0));

        foreach ($document->getAttributes() as $column) {
            if (\is_array($column)) {
                foreach ($column as $subAttribute) {
                    if ($subAttribute instanceof DatabaseDocument) {
                        $this->filter($subAttribute);
                    }
                }
            } elseif ($column instanceof DatabaseDocument) {
                $this->filter($column);
            }
        }

        return $document;
    }
}
