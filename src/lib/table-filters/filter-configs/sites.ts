/**
 * Predefined filter columns for project sites list.
 */

import type { FilterColumn } from '../types'

export const sitesFilterColumns: FilterColumn[] = [
  { id: 'name', title: 'Name', type: 'string' },
  { id: '$createdAt', title: 'Created', type: 'datetime' },
  { id: '$updatedAt', title: 'Updated', type: 'datetime' },
]
