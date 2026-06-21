<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Report extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Report ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Report creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Report update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('appId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the third-party app that submitted the report.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Analyzer that produced this report. e.g. lighthouse, audit, databaseAnalyzer.',
                'default' => '',
                'example' => 'lighthouse',
            ])
            ->addRule('title', [
                'type' => self::TYPE_STRING,
                'description' => 'Short, human-readable title for the report.',
                'default' => '',
                'example' => 'Lighthouse audit for https://appwrite.io/',
            ])
            ->addRule('summary', [
                'type' => self::TYPE_STRING,
                'description' => 'Markdown summary describing the report.',
                'default' => '',
                'example' => 'Performance score 78. 4 opportunities found.',
            ])
            ->addRule('targetType', [
                'type' => self::TYPE_STRING,
                'description' => 'Plural noun describing what the report analyzes, e.g. databases, sites, urls.',
                'default' => '',
                'example' => 'urls',
            ])
            ->addRule('target', [
                'type' => self::TYPE_STRING,
                'description' => 'Free-form target identifier (URL for lighthouse, resource ID for db).',
                'default' => '',
                'example' => 'https://appwrite.io/',
            ])
            ->addRule('categories', [
                'type' => self::TYPE_STRING,
                'description' => 'Categories covered by the report, e.g. performance, accessibility.',
                'default' => [],
                'example' => ['performance', 'accessibility'],
                'array' => true,
            ])
            ->addRule('insights', [
                'type' => Response::MODEL_INSIGHT,
                'description' => 'Insights nested under this report.',
                'default' => [],
                'example' => [],
                'array' => true,
            ])
            ->addRule('analyzedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Time the report was analyzed in ISO 8601 format.',
                'default' => null,
                'example' => self::TYPE_DATETIME_EXAMPLE,
                'required' => false,
            ]);
    }

    public function getName(): string
    {
        return 'Report';
    }

    public function getType(): string
    {
        return Response::MODEL_REPORT;
    }
}
