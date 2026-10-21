<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ProjectTransfer extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Transfer ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Transfer creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Transfer update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('projectId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the project being transferred.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('projectName', [
                'type' => self::TYPE_STRING,
                'description' => 'Name of the project being transferred.',
                'default' => '',
                'example' => 'My Project',
            ])
            ->addRule('teamId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the organization that owns the project.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('targetTeamId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the only organization allowed to claim the project. Empty when any organization can claim it.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('expire', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Transfer expiration date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'Secret used to claim the project. Only returned when the transfer is created.',
                'default' => '',
                'example' => '919c2d18fb5d4...a2ae413da83346ad2',
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
        return 'ProjectTransfer';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PROJECT_TRANSFER;
    }
}
