<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class InsightCTA extends Model
{
    public function __construct()
    {
        $this
            ->addRule('label', [
                'type' => self::TYPE_STRING,
                'description' => 'Human-readable label for the CTA, used in UI.',
                'default' => '',
                'example' => 'Create missing index',
            ])
            ->addRule('service', [
                'type' => self::TYPE_STRING,
                'description' => 'Public API service (SDK namespace) the client should invoke. Must match the engine that owns the resource — for index suggestions: databases (legacy), tablesDB, documentsDB, or vectorsDB.',
                'default' => '',
                'example' => 'tablesDB',
            ])
            ->addRule('method', [
                'type' => self::TYPE_STRING,
                'description' => 'Public API method on the chosen service the client should invoke when this CTA is triggered.',
                'default' => '',
                'example' => 'createIndex',
            ])
            ->addRule('params', [
                'type' => self::TYPE_JSON,
                'description' => 'Parameter map the client should pass to the service method when this CTA is triggered. Keys match the target API\'s parameter names (e.g. databaseId/tableId/columns for tablesDB, databaseId/collectionId/attributes for the legacy Databases API).',
                'default' => new \stdClass(),
                'example' => ['databaseId' => 'main', 'tableId' => 'orders', 'key' => '_idx_status', 'type' => 'key', 'columns' => ['status']],
            ]);
    }

    public function getName(): string
    {
        return 'InsightCTA';
    }

    public function getType(): string
    {
        return Response::MODEL_INSIGHT_CTA;
    }
}
