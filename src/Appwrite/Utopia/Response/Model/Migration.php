<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class Migration extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Migration ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Migration creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Variable creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Migration status ( pending, processing, failed, completed ) ',
                'default' => '',
                'example' => 'pending',
            ])
            ->addRule('stage', [
                'type' => self::TYPE_STRING,
                'description' => 'Migration stage ( init, processing, source-check, destination-check, migrating, finished )',
                'default' => '',
                'example' => 'init',
            ])
            ->addRule('source', [
                'type' => self::TYPE_STRING,
                'description' => 'A string containing the type of source of the migration.',
                'default' => '',
                'example' => 'Appwrite',
            ])
            ->addRule('destination', [
                'type' => self::TYPE_STRING,
                'description' => 'A string containing the type of destination of the migration.',
                'default' => 'Appwrite',
                'example' => 'Appwrite',
            ])
            ->addRule('resources', [
                'type' => self::TYPE_STRING,
                'description' => 'Resources to migrate.',
                'default' => [],
                'example' => ['user'],
                'array' => true
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'Id of the resource to migrate.',
                'default' => '',
                'example' => 'databaseId:collectionId',
                'array' => false
            ])
            ->addRule('statusCounters', [
                'type' => self::TYPE_JSON,
                'description' => 'A group of counters that represent the total progress of the migration.',
                'default' => [],
                'example' => '{"Database": {"PENDING": 0, "SUCCESS": 1, "ERROR": 0, "SKIP": 0, "PROCESSING": 0, "WARNING": 0}}',
            ])
            ->addRule('resourceData', [
                'type' => self::TYPE_JSON,
                'description' => 'An array of objects containing the report data of the resources that were migrated.',
                'default' => [],
                'example' => '[{"resource":"Database","id":"public","status":"SUCCESS","message":""}]',
            ])
            ->addRule('errors', [
                'type' => self::TYPE_STRING,
                'description' => 'All errors that occurred during the migration process.',
                'array' => true,
                'default' => [],
                'example' => [],
            ])
            ->addRule('options', [
                'type' => self::TYPE_JSON,
                'description' => 'Migration options used during the migration process.',
                'default' => [],
                'example' => '{"bucketId": "exports", "notify": false}',
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
        return 'Migration';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MIGRATION;
    }

    public function filter(Document $document): Document
    {
        $errors = $document->getAttribute('errors', []);
        if (empty($errors)) {
            return $document;
        }

        foreach ($errors as $index => $error) {
            $decoded = \json_decode($error, true);

            if (\is_array($decoded)) {
                if (isset($decoded['trace'])) {
                    unset($decoded['trace']);
                }
                $errors[$index] = \json_encode($decoded);
            }
        }

        $document->setAttribute('errors', $errors);

        return $document;
    }
}
