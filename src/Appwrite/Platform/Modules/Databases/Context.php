<?php

namespace Appwrite\Platform\Modules\Databases;

/**
 * Context constants for identifying database resource types.
 *
 * Tables vs. Collections, Rows vs. Documents, and Columns vs. Attributes
 * are functionally equivalent and share the same underlying API structure.
 *
 * These constants help distinguish the context of an action,
 * enabling accurate error messages, realtime event triggers, and other context-aware behaviors.
 */
class Context
{
    // Context constants for database
    public const DATABASE_ROWS = 'row';
    public const DATABASE_TABLES = 'table';
    public const DATABASE_COLUMNS = 'column';
    public const DATABASE_COLUMN_INDEX = 'columnIndex';

    public const DATABASE_INDEX = 'index';
    public const DATABASE_DOCUMENTS = 'document';
    public const DATABASE_ATTRIBUTES = 'attribute';
    public const DATABASE_COLLECTIONS = 'collection';
}
