<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Transfer\Resource;

class MigrationReport extends Model
{
    public function __construct()
    {
        $this
            ->addRule(Resource::TYPE_USER, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of users to be migrated.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule(Resource::TYPE_TEAM, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of teams to be migrated.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule(Resource::TYPE_DATABASE, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of databases to be migrated.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule(Resource::TYPE_DOCUMENT, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of documents to be migrated.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule(Resource::TYPE_FILE, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of files to be migrated.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule(Resource::TYPE_BUCKET, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of buckets to be migrated.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule(Resource::TYPE_FUNCTION, [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of functions to be migrated.',
                'default' => 0,
                'example' => 20,
            ])
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Size of files to be migrated in mb.',
                'default' => 0,
                'example' => 30000,
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Migration Report';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MIGRATION_REPORT;
    }
}
