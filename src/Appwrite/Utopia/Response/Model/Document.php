<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Utopia\Database\Document as DatabaseDocument;

class Document extends Any
{
    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Document';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_DOCUMENT;
    }

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Document ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$collectionId', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection ID.',
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
                'description' => 'Document creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Document update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$permissions', [
                'type' => self::TYPE_STRING,
                'description' => 'Document permissions. [Learn more about permissions](/docs/permissions).',
                'default' => '',
                'example' => ['read("any")'],
                'array' => true,
            ]);
    }

    public function filter(DatabaseDocument $document): DatabaseDocument
    {
        $document->removeAttribute('$internalId');
        $document->removeAttribute('$collection'); // $collection is the internal collection ID

        foreach ($document->getAttributes() as $attribute) {
            if (\is_array($attribute)) {
                foreach ($attribute as $subAttribute) {
                    if ($subAttribute instanceof DatabaseDocument) {
                        $this->filter($subAttribute);
                    }
                }
            } elseif ($attribute instanceof DatabaseDocument) {
                $this->filter($attribute);
            }
        }

        return $document;
    }
}
