<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class BackupPolicy extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Backup Policy ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'The name of the policy',
                'default' => '',
                'example' => 'Hourly backups',
                'required' => true,
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Variable creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Variable creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('resourceType', [
                'type' => self::TYPE_STRING,
                'description' => 'Resource Type ( backup-database, backup-project ) ',
                'default' => '',
                'example' => 'backup-database',
                'required' => true,
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'Resource Id od the resource ',
                'default' => '',
                'example' => 'backup-database',
                'required' => true,
            ])
            ->addRule('retention', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Days to save resource before delete',
                'default' => [],
                'example' => '',
                'required' => true,
            ])
            ->addRule('hours', [
                'type' => self::TYPE_INTEGER,
                'description' => 'How often in hours to backup the resource',
                'default' => [],
                'example' => '',
                'required' => true,
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is policy enabled',
                'default' => false,
                'example' => true,
                'required' => true,
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
        return 'BackupPolicy';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_BACKUP_POLICY;
    }
}
