/**
 * Predefined filter columns for project functions list.
 */

import type { FilterColumn } from '../types'

export const functionsFilterColumns: FilterColumn[] = [
  { id: 'name', title: 'Name', type: 'string' },
  { id: 'runtime', title: 'Runtime', type: 'string' },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
  { id: '$updatedAt', title: '$updatedAt', type: 'datetime' },
]
