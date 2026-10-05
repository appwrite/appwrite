<?php

namespace Utopia\Query\Schema\ClickHouse;

enum Engine: string
{
    case MergeTree = 'MergeTree';
    case ReplacingMergeTree = 'ReplacingMergeTree';
    case SummingMergeTree = 'SummingMergeTree';
    case AggregatingMergeTree = 'AggregatingMergeTree';
    case CollapsingMergeTree = 'CollapsingMergeTree';
    case ReplicatedMergeTree = 'ReplicatedMergeTree';
    case Memory = 'Memory';
    case Log = 'Log';
    case TinyLog = 'TinyLog';
    case StripeLog = 'StripeLog';

    /**
     * Engines that do not require ORDER BY.
     */
    public function requiresOrderBy(): bool
    {
        return match ($this) {
            self::Memory, self::Log, self::TinyLog, self::StripeLog => false,
            default => true,
        };
    }
}
