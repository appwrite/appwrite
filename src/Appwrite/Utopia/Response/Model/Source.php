<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Source extends Model
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
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Transfer type.',
                'default' => '',
                'example' => 'appwrite',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Transfer name.',
                'default' => '',
                'example' => 'Appwrite',
            ])
            ->addRule('lastCheck', [
                'type' => self::TYPE_JSON,
                'description' => 'A JSON Object with the result of the last source check.',
                'default' => '',
                'example' => [
                    'success' => false,
                    'message' => 'Transfer completed successfully'
                ],
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
        return 'Source';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_SOURCE;
    }
}