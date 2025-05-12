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
            ->addRule('$tableId', [
                'type' => self::TYPE_STRING,
                'description' => 'Table ID.',
                'default' => '',
                'example' => '5e5ea5c15117e',
            ])
            ->addRule('$databaseId', [
                'type' => self::TYPE_STRING,
                'description' => 'Database ID.',
                'default' => '',
                'example' => '5e5ea5c15117e',
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
        $document->removeAttribute('$internalId');
        $document->removeAttribute('$collection');
        $document->removeAttribute('$tenant');

        $collectionId = $document->getAttribute('$collectionId', '');
        if (!empty($collectionId)) {
            $document
                ->removeAttribute('$collectionId')
                ->setAttribute('$tableId', $collectionId);
        }

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
