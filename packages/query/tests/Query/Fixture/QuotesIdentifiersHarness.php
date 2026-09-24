<?php

namespace Tests\Query\Fixture;

use Utopia\Query\QuotesIdentifiers;

/**
 * Named helper so static analysis can resolve quote() through the trait.
 */
final class QuotesIdentifiersHarness
{
    use QuotesIdentifiers {
        quote as public;
        quoteLiteral as public;
    }
}
