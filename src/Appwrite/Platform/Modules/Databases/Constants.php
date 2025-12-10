<?php

/**
 * Constants for identifying database resource types.
 *
 * - Tables vs. Collections,
 * - Rows vs. Documents, and
 * - Columns vs. Attributes
 *
 * are functionally equivalent and share the same underlying API structure.
 *
 * These constants help distinguish the context of an action,
 * enabling accurate error messages, realtime event triggers, and other context-aware behaviors.
 */

const ROWS = 'row';
const TABLES = 'table';
const COLUMNS = 'column';
const COLUMN_INDEX = 'columnIndex';

const INDEX = 'index';
const DOCUMENTS = 'document';
const ATTRIBUTES = 'attribute';
const COLLECTIONS = 'collection';

const TABLESDB = 'tablesdb';
const DOCUMENTSDB = 'documentsdb';
const VECTORDB = 'vectordb';

const MIN_VECTOR_DIMENSION = 1;
const MAX_VECTOR_DIMENSION = 16000;
