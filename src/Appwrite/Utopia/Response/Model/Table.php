<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class Table extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Table ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Table creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Table update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$permissions', [
                'type' => self::TYPE_STRING,
                'description' => 'Table permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).',
                'default' => '',
                'example' => ['read("any")'],
                'array' => true
            ])
            ->addRule('databaseId', [
                'type' => self::TYPE_STRING,
                'description' => 'Database ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Table name.',
                'default' => '',
                'example' => 'My Table',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Table enabled. Can be \'enabled\' or \'disabled\'. When disabled, the table is inaccessible to users, but remains accessible to Server SDKs using API keys.',
                'default' => true,
                'example' => false,
            ])
            ->addRule('rowSecurity', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether row-level permissions are enabled. [Learn more about permissions](https://appwrite.io/docs/permissions).',
                'default' => '',
                'example' => true,
            ])
            ->addRule('columns', [
                'type' => [
                    Response::MODEL_COLUMN_BOOLEAN,
                    Response::MODEL_COLUMN_INTEGER,
                    Response::MODEL_COLUMN_FLOAT,
                    Response::MODEL_COLUMN_EMAIL,
                    Response::MODEL_COLUMN_ENUM,
                    Response::MODEL_COLUMN_URL,
                    Response::MODEL_COLUMN_IP,
                    Response::MODEL_COLUMN_DATETIME,
                    Response::MODEL_COLUMN_RELATIONSHIP,
                    Response::MODEL_COLUMN_POINT,
                    Response::MODEL_COLUMN_LINE,
                    Response::MODEL_COLUMN_POLYGON,
                    Response::MODEL_COLUMN_STRING, // needs to be last, since its condition would dominate any other string attribute
                ],
                'description' => 'Table columns.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])
            ->addRule('indexes', [
                'type' => Response::MODEL_COLUMN_INDEX,
                'description' => 'Table indexes.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Table';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_TABLE;
    }

    /**
     * Process Document before returning it to the client for backwards compatibility!
     */
    public function filter(Document $document): Document
    {
        $columns = $document->getAttribute('attributes', []);
        if (!empty($columns) && \is_array($columns)) {
            $columns = $this->remapNestedRelatedCollections($columns);
        }

        $document->setAttribute('columns', $columns);

        $related = $document->getAttribute('relatedCollection');
        if ($related !== null) {
            $document->setAttribute('relatedTable', $related);
        }

        $documentSecurity = $document->getAttribute('documentSecurity');
        $document->setAttribute('rowSecurity', $documentSecurity);

        // remove anyways as they are already copied above.
        $document
            ->removeAttribute('attributes')
            ->removeAttribute('documentSecurity')
            ->removeAttribute('relatedCollection');

        return $document;
    }

    // 1.7 now sends back `relatedTable` instead of `relatedCollection`.
    // This is necessary because the actual database underneath uses `relatedCollection`.
    private function remapNestedRelatedCollections(array $columns): array
    {
        foreach ($columns as $i => $column) {
            if (isset($column['relatedCollection'])) {
                $columns[$i]['relatedTable'] = $column['relatedCollection'];
                unset($columns[$i]['relatedCollection']);
            }
        }
        return $columns;
    }
}
