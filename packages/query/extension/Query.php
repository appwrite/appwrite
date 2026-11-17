<?php

namespace Utopia\Query;

/**
 * Query over the utopia extension's NativeQuery, which holds its methods and state. Loaded by
 * src/extension.php in place of src/Query.php when the extension's query library is on. It
 * declares what a class of an extension cannot: constants that are arrays, with the values
 * src/Query.php gives them.
 *
 * This file is outside the autoloaded directory on purpose, so static analysis reads
 * src/Query.php as the one declaration of the class.
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
