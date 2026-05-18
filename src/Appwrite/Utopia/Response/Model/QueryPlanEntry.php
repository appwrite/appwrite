<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class QueryPlanEntry extends Any
{
    public function getName(): string
    {
        return 'QueryPlanEntry';
    }

    public function getType(): string
    {
        return Response::MODEL_QUERY_PLAN_ENTRY;
    }

    public function __construct()
    {
        $this
            ->addRule('purpose', [
                'type' => self::TYPE_STRING,
                'description' => 'Reason this read was issued (e.g. "find" for each underlying find call).',
                'default' => 'find',
                'example' => 'find',
            ])
            ->addRule('context', [
                'type' => self::TYPE_JSON,
                'description' => 'User-facing identifiers this plan refers to (e.g. {"collection": "movies"}).',
                'default' => new \stdClass,
                'example' => ['collection' => 'movies'],
            ])
            ->addRule('plan', [
                'type' => self::TYPE_JSON,
                'description' => 'Vendor-native query plan. Carries `engine`, `rowsScanned`, `indexUsed`, `estimatedCost`, and a `tree` with the raw plan. Internal storage details are stripped.',
                'default' => new \stdClass,
                'example' => [
                    'engine' => 'sql',
                    'rowsScanned' => 25,
                    'indexUsed' => 'idx_status',
                    'estimatedCost' => 4.5,
                ],
            ]);
    }
}
