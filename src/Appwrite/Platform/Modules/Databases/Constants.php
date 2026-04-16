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
