/**
 * Predefined filter columns for organization / partner project lists.
 */

import type { FilterColumn } from '../types'

export const projectsFilterColumns: FilterColumn[] = [
  { id: '$id', title: '$id', type: 'string' },
  { id: 'name', title: 'Name', type: 'string' },
  { id: 'region', title: 'Region', type: 'string' },
  {
    id: 'status',
    title: 'Status',
    type: 'enum',
    format: 'enum',
    elements: [
      { value: 'active', label: 'Active' },
      { value: 'paused', label: 'Paused' },
    ],
    optional: true,
  },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
]
