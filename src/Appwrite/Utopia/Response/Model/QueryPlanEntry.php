<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class QueryPlanEntry extends Model
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
                'description' => 'Reason this read was issued (e.g. "find" for the row lookup, "count" for the total).',
                'default' => 'find',
                'example' => 'find',
            ])
            ->addRule('context', [
                'type' => self::TYPE_JSON,
                'description' => 'User-facing identifiers this plan refers to (e.g. {"collection": "movies"}).',
                'default' => new \stdClass(),
                'example' => ['collection' => 'movies'],
            ])
            ->addRule('plan', [
                'type' => Response::MODEL_QUERY_PLAN_DETAIL,
                'description' => 'Normalized query plan with engine-native detail. Internal storage identifiers are stripped.',
                'default' => new \stdClass(),
                'example' => [],
            ]);
    }
}
