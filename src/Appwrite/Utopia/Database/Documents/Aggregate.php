<?php

namespace Appwrite\Utopia\Database\Documents;

use Utopia\Database\Document;

/**
 * A row of an aggregation or a distinct read: the values it groups, aggregates or selects only. It is no stored record,
 * so a response gives it none of the record attributes it does not group or select, nor the database and collection
 * a stored record is read from.
 */
final class Aggregate extends Document
{
}
