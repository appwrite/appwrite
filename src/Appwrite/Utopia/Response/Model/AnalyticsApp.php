<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class AnalyticsApp extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Analytics app ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Analytics app creation time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Analytics app last update time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Human-readable name of the tracked website or application.',
                'default' => '',
                'example' => 'My Website',
            ])
            ->addRule('domain', [
                'type' => self::TYPE_STRING,
                'description' => 'Primary domain being tracked (e.g. example.com).',
                'default' => '',
                'example' => 'example.com',
            ])
            ->addRule('timezone', [
                'type' => self::TYPE_STRING,
                'description' => 'IANA timezone used to define the daily boundary for stats.',
                'default' => 'UTC',
                'example' => 'UTC',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether tracking is currently active.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('public', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether stats for this app are publicly viewable.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('allowedOrigins', [
                'type' => self::TYPE_STRING,
                'description' => 'List of origins allowed to send tracking events. Use ["*"] to allow all.',
                'default' => ['*'],
                'example' => ['https://example.com'],
                'array' => true,
            ])
            ->addRule('snippetId', [
                'type' => self::TYPE_STRING,
                'description' => 'Unique identifier for the tracking script snippet.',
                'default' => '',
                'example' => 'snp_a1b2c3d4e5',
            ]);
    }

    public function getName(): string
    {
        return 'AnalyticsApp';
    }

    public function getType(): string
    {
        return Response::MODEL_ANALYTICS_APP;
    }
}
