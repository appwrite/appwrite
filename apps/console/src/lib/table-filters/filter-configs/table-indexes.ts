/**
 * Predefined filter columns for database table indexes list.
 */

import {
  COLLECTION_INDEX_TYPES,
  type CollectionIndexType,
} from '@/lib/databases/collection-index-types'
import type { FilterColumn } from '../types'

const INDEX_TYPE_ELEMENTS = COLLECTION_INDEX_TYPES.map((entry) => ({
  value: entry.id satisfies CollectionIndexType,
  label: entry.label,
  description: entry.description,
}))

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
