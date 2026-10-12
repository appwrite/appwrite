/**
 * Predefined filter columns for database table columns list.
 */

import type { FilterColumn } from '../types'

const COLUMN_TYPE_ELEMENTS = [
  { value: 'text', label: 'Text' },
  { value: 'mediumtext', label: 'Mediumtext' },
  { value: 'longtext', label: 'Longtext' },
  { value: 'varchar', label: 'Varchar' },
  { value: 'integer', label: 'Integer' },
  { value: 'bigint', label: 'Bigint' },
  { value: 'double', label: 'Float' },
  { value: 'boolean', label: 'Boolean' },
  { value: 'datetime', label: 'Datetime' },
  { value: 'email', label: 'Email' },
  { value: 'ip', label: 'IP' },
  { value: 'url', label: 'URL' },
  { value: 'enum', label: 'Enum' },
  { value: 'relationship', label: 'Relationship' },
  { value: 'point', label: 'Point' },
  { value: 'linestring', label: 'Line' },
  { value: 'polygon', label: 'Polygon' },
  { value: 'string', label: 'String (deprecated)' },
]

export const tableColumnsFilterColumns: FilterColumn[] = [
  { id: 'key', title: 'Key', type: 'string' },
  { id: 'name', title: 'Name', type: 'string' },
  {
    id: 'type',
    title: 'Type',
    type: 'enum',
    format: 'enum',
    elements: COLUMN_TYPE_ELEMENTS,
    optional: false,
  },
]
