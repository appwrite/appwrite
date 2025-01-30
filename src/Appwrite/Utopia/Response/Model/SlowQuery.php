<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class SlowQuery extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Slow Query ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Slow query creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Slow query update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('blocked', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is this slow query blocked from execution?',
                'default' => false,
                'example' => true,
            ])
            ->addRule('count', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The number of times this slow query has executed.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule('queries', [
                'type' => self::TYPE_STRING,
                'description' => 'The slow query itself.',
                'default' => '',
                'example' => '["limit(10000)"]',
            ])
            ->addRule('path', [
                'type' => self::TYPE_STRING,
                'description' => 'The path of the endpoint that executed this slow query.',
                'default' => '',
                'example' => '/v1/databases/123/collections',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Slow Queries';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_SLOW_QUERY;
    }
}
