<?php

namespace Utopia\Query;

/**
 * Query over NativeQuery, the utopia extension's class that holds its methods and state. This
 * class declares what a class of an extension cannot: constants that are arrays.
 *
 * @phpstan-consistent-constructor
 */
class Query extends NativeQuery
{
    /**
     * @var list<Method>
     */
    protected const array LOGICAL_TYPES = [
        Method::And,
        Method::Or,
        Method::ElemMatch,
        Method::Relationship,
    ];

    /**
     * @var list<string>
     */
    public const array GROUP_BY_TIME_BUCKET_INTERVALS = ['1m', '5m', '15m', '1h', '1d', '1w', '1M'];
}
