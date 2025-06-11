<?php

/**
 * Context constants for identifying database resource types.
 *
 * Tables vs. Collections, Rows vs. Documents, and Columns vs. Attributes
 * are functionally equivalent and share the same underlying API structure.
 *
 * These constants help distinguish the context of an action,
 * enabling accurate error messages, realtime event triggers, and other context-aware behaviors.
 */

// Context constants for database

const DATABASE_ROWS_CONTEXT = 'row';
const DATABASE_TABLES_CONTEXT = 'table';
const DATABASE_COLUMNS_CONTEXT = 'column';
const DATABASE_INDEX_CONTEXT = 'index';

const DATABASE_DOCUMENTS_CONTEXT = 'document';
const DATABASE_ATTRIBUTES_CONTEXT = 'attribute';
const DATABASE_COLLECTIONS_CONTEXT = 'collection';
const DATABASE_COLUMN_INDEX_CONTEXT = 'columnIndex';
