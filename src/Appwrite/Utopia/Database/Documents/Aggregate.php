<?php

namespace Appwrite\Utopia\Database\Documents;

use Utopia\Database\Document;

/**
 * A row of an aggregation: its groups and aggregates only. It is no stored record, so a response gives it none of
 * the record attributes it does not group.
 */
final class Aggregate extends Document
{
}
