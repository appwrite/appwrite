/**
 * Predefined filter columns for project users list (Auth).
 * Use when building the Filters UI for users; same contract as schema-driven lists.
 */

import type { FilterColumn } from '../types'

export const usersFilterColumns: FilterColumn[] = [
  { id: 'name', title: 'Name', type: 'string' },
  { id: 'email', title: 'Email', type: 'string' },
  { id: 'phone', title: 'Phone', type: 'string' },
  {
    id: 'status',
    title: 'Status',
    type: 'enum',
    elements: [
      { value: 'enabled', label: 'Enabled' },
      { value: 'disabled', label: 'Disabled' },
    ],
    optional: false, // User status is always set
  },
  { id: '$createdAt', title: 'Created', type: 'datetime' },
  { id: '$updatedAt', title: 'Updated', type: 'datetime' },
]
