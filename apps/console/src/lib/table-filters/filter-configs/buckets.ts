/**
 * Predefined filter columns for project buckets list (Storage).
 */

import type { FilterColumn } from '../types'

export const bucketsFilterColumns: FilterColumn[] = [
  { id: '$id', title: '$id', type: 'string' },
  { id: 'name', title: 'Name', type: 'string' },
  {
    id: 'enabled',
    title: 'Status',
    type: 'boolean',
  },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
  { id: '$updatedAt', title: '$updatedAt', type: 'datetime' },
]
