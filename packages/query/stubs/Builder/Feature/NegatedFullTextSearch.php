<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

/**
 * Separate from {@see FullTextSearch} because some engines can match a
 * full-text index but cannot negate that match. MongoDB's `$text` operator,
 * for example, has no negated form.
 */
interface NegatedFullTextSearch
{
    public function filterNotSearch(string $attribute, string $value): static;
}
