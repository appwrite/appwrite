<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Backup extends Model
{
    public function __construct()
    {
        $this
            ->addRule('policyId', [
                'type' => self::TYPE_STRING,
                'description' => 'Backup policy id.',
                'default' => '',
                'example' => 'did8jx6ws45jana098ab7',
            ])
            ->addRule('policyInternalId', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Backup policy internal id.',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule('startedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'The backup start time.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('finishedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'The backup finish time.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('progress', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Backup progress status.',
                'default' => 0,
                'example' => 50,
            ])
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Backup size in bytes.',
                'default' => 0,
                'example' => 100000,
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
        return 'Backup';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_BACKUP;
    }
}
