<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

/**
 * One captured query plan from a single physical read issued during the
 * explained operation. A listRows that resolves relationships produces
 * multiple entries (one per underlying find()), in execution order.
 */
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
                'description' => 'What this read was issued for. Currently always "find"; future values may include "count" or "sum".',
                'default' => 'find',
                'example' => 'find',
            ])
            ->addRule('context', [
                'type' => self::TYPE_JSON,
                'description' => 'Metadata about which user-facing collection this plan refers to (e.g. {"collection": "movies"} or {"collection": "reviews"} for a relationship fetch).',
                'default' => new \stdClass(),
                'example' => ['collection' => 'movies'],
            ])
            ->addRule('plan', [
                'type' => self::TYPE_JSON,
                'description' => 'Vendor-native query plan. Always carries `engine`, `rowsScanned`, `indexUsed`, `estimatedCost`; may also carry a `tree` field with the raw plan for debugging. Internal storage details (the `_perms` companion table, the `_metadata` system table, internal column names) are stripped before returning.',
                'default' => new \stdClass(),
                'example' => [
                    'engine' => 'sql',
                    'rowsScanned' => 25,
                    'indexUsed' => 'idx_status_createdAt',
                    'estimatedCost' => 4.5,
                ],
            ]);
    }
}
