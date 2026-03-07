/**
 * Predefined filter columns for project databases list.
 */

import type { FilterColumn } from '../types'

export const databasesFilterColumns: FilterColumn[] = [
  { id: 'name', title: 'Name', type: 'string' },
  { id: '$createdAt', title: 'Created', type: 'datetime' },
  { id: '$updatedAt', title: 'Updated', type: 'datetime' },
]
