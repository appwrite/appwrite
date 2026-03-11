/**
 * Predefined filter columns for database table indexes list.
 */

import type { FilterColumn } from '../types'

const INDEX_TYPE_ELEMENTS = [
  { value: 'key', label: 'Key' },
  { value: 'unique', label: 'Unique' },
  { value: 'fulltext', label: 'Fulltext' },
  { value: 'spatial', label: 'Spatial' },
]

export const tableIndexesFilterColumns: FilterColumn[] = [
  { id: 'key', title: 'Key', type: 'string' },
  { id: 'name', title: 'Name', type: 'string' },
  {
    id: 'type',
    title: 'Type',
    type: 'enum',
    format: 'enum',
    elements: INDEX_TYPE_ELEMENTS,
    optional: false,
  },
]
