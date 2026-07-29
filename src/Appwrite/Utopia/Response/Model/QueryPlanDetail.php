<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class QueryPlanDetail extends Model
{
    public function getName(): string
    {
        return 'QueryPlanDetail';
    }

    public function getType(): string
    {
        return Response::MODEL_QUERY_PLAN_DETAIL;
    }

    public function __construct()
    {
        // The library plan also carries `engine`; it is omitted here so the
        // public DTO does not advertise the backing database engine.
        $this
            ->addRule('rowsScanned', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Estimated rows the planner expects to examine. Null when the engine reports no estimate.',
                'default' => null,
                'required' => false,
                'example' => 1200,
            ])
            ->addRule('indexUsed', [
                'type' => self::TYPE_STRING,
                'description' => 'Index the chosen access path uses. Null when the query falls back to a full scan.',
                'default' => null,
                'required' => false,
                'example' => 'idx_status',
            ])
            ->addRule('estimatedCost', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Planner cost estimate. Null when the engine has no cost model.',
                'default' => null,
                'required' => false,
                'example' => 4.5,
            ])
            ->addRule('rowsReturned', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Actual rows the executed query returned. Null for aggregates (count/sum) and engines that do not run the query.',
                'default' => null,
                'required' => false,
                'example' => 25,
            ])
            ->addRule('executionTime', [
                'type' => self::TYPE_FLOAT,
                'description' => 'Actual wall-clock time of the executed query in milliseconds. Null when the query was not executed.',
                'default' => null,
                'required' => false,
                'example' => 1.84,
            ])
            ->addRule('tree', [
                'type' => self::TYPE_JSON,
                'description' => 'Raw query plan from the engine, with internal storage identifiers stripped. Carries the full access-path detail (scan type, candidate indexes, filter conditions) for deep diagnosis. Shape varies by engine.',
                'default' => new \stdClass(),
                'required' => false,
                'example' => ['query_block' => ['select_id' => 1]],
            ])
            ->addRule('error', [
                'type' => self::TYPE_STRING,
                'description' => 'Set when the engine could not produce a plan for this statement; carries the failure reason.',
                'default' => null,
                'required' => false,
                'example' => 'EXPLAIN not supported for this statement',
            ]);
    }
}
