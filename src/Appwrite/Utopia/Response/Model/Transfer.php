<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Transfer extends Model
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
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Transfer status.',
                'default' => '',
                'example' => 'pending',
            ])
            ->addRule('stage', [
                'type' => self::TYPE_STRING,
                'description' => 'Transfer stage.',
                'default' => '',
                'example' => 'init',
            ])
            ->addRule('source', [
                'type' => self::TYPE_STRING,
                'description' => 'Source ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('destination', [
                'type' => self::TYPE_STRING,
                'description' => 'Destination ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('resources', [
                'type' => self::TYPE_STRING,
                'description' => 'Resources to transfer.',
                'default' => [],
                'example' => ['users'],
                'array' => true
            ])
            ->addRule('progress', [
                'type' => self::TYPE_JSON,
                'description' => 'Transfer progress.',
                'default' => [],
                'example' => '{"source":[], "destination": []}',
            ])
            ->addRule('latestUpdate', [
                'type' => self::TYPE_JSON,
                'description' => 'Latest update.',
                'default' => [],
                'example' => '{"source":[], "destination": []}',
            ])
            ->addRule('errorData', [
                'type' => self::TYPE_JSON,
                'description' => 'Error data.',
                'default' => [],
                'example' => '{"source":[], "destination": []}',
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
        return 'Transfer';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_TRANSFER;
    }
}